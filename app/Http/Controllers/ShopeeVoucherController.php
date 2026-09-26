<?php

namespace App\Http\Controllers;

use App\Exceptions\AffiliateScanException;
use App\Models\PlatformVoucher;
use App\Services\CashbackService;
use App\Services\FacebookRedirectFlagService;
use App\Services\ShopeeLinkResolverService;
use App\Services\ShopeeProductLookupService;
use App\Services\TrackingService;
use App\Services\UrlValidationService;
use App\Services\VoucherFetchResult;
use App\Services\VoucherFetchService;
use App\Services\VoucherRefService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class ShopeeVoucherController extends Controller
{
    public function __construct(
        private UrlValidationService $urlValidator,
        private ShopeeLinkResolverService $resolver,
        private VoucherFetchService $fetcher,
        private VoucherRefService $refs,
        private TrackingService $tracking,
        private ShopeeProductLookupService $productLookup,
    ) {}

    public function resolve(Request $request): Response|RedirectResponse
    {
        // Công cụ chỉ dành cho khách trên điện thoại (bấm link Facebook/Zalo) — admin luôn qua
        // được để test từ máy tính. Chặn ở đây để không ai gọi thẳng endpoint này bỏ qua giao
        // diện (ẩn khung tìm mã ở Home.vue chỉ là UI, không phải bảo mật thật).
        if (! TrackingService::isMobile($request->userAgent()) && ! ($request->user()?->isAdmin() ?? false)) {
            return back()->withErrors([
                'voucher_url' => 'Chức năng lấy mã chỉ dùng được trên điện thoại. Vui lòng mở tietkiemvi.com bằng trình duyệt trên điện thoại.',
            ]);
        }

        $request->validate([
            'url' => ['required', 'url', 'max:2000'],
        ]);

        $url = $request->input('url');

        // Nguồn mã trả về ĐÚNG MỘT link đã áp mã (kèm thông tin sản phẩm nếu đọc được). Link đó
        // thuộc affiliate account của nguồn; affiliate được đổi về của mình khi người dùng bấm
        // "Mua ngay" — xem AffiliateLinkRewriterService. Nguồn nào được gọi, gọi với link nào
        // (và chế độ mã YTB gọi cả hai ra sao) nằm trong VoucherFetchService.
        try {
            $this->urlValidator->validateShopeeOnly($url);
            $result = $this->fetcher->fetch($url);
        } catch (AffiliateScanException $e) {
            return back()->withErrors(['voucher_url' => $e->getMessage()]);
        }

        $data = $result->data;
        $canonicalUrl = $result->canonicalUrl;

        // ID lấy từ URL là nguồn chính; nếu URL không có dạng -i.SHOP.ITEM thì mượn ID mà
        // nguồn đã phân giải hộ, để vẫn hỏi được Shopee khi thiếu thông tin sản phẩm.
        $ids = $this->resolver->extractIds($canonicalUrl);
        if (! $ids && ! empty($data['shop_id']) && ! empty($data['item_id'])) {
            $ids = ['shop_id' => $data['shop_id'], 'item_id' => $data['item_id']];
        }

        $product = $data['product'] ?? ($ids
            ? $this->resolver->fetchProductInfo($ids['item_id'], $ids['shop_id'])
            : null);

        $this->tracking->log('url_paste', $request, [
            'url' => $canonicalUrl,
            'platform' => 'shopee',
            'product_name' => $product['product_name'] ?? null,
        ]);

        // Token mờ của link CTA duy nhất; null nghĩa là chưa lấy được mã cho sản phẩm này.
        $ref = isset($data['voucher_link']) ? $this->maskVoucherLink($result) : null;

        return Inertia::render('Home', [
            'vouchers' => PlatformVoucher::suggestedList(),
            'voucherResult' => [
                'canonical_url' => $canonicalUrl,
                'product' => $product,
                'voucher_ref' => $ref,
                // Để giao diện tự hỏi hoa hồng của sản phẩm này SAU khi đã hiện kết quả
                // (xem commission()). Không hỏi ngay trong lượt này: nguồn hoa hồng là proxy
                // bên thứ ba timeout 10 giây, mà lượt quét vốn đã phải chờ nguồn mã — cộng
                // thêm vào đường chờ của khách là đổi một con số ước tính lấy 10 giây im lặng.
                'item_id' => $ids['item_id'] ?? null,
                // Chế độ mã YTB: khách phải mở link này (bước 1) trước khi bấm Facebook/Mua ngay
                // (bước 2) — xem ShortLinkController::activateYoutube. null = không có bước 1.
                'ytb_activate_url' => $ref && $result->ytbUrl ? route('voucher.ytb', $ref) : null,
            ],
            ...$this->facebookRedirectFlags(),
        ]);
    }

    /**
     * Hoa hồng (bằng TIỀN) mà Shopee trả cho tụi mình nếu khách mua đúng sản phẩm này — giao
     * diện nhân với tỉ lệ hoàn để hiện "Hoàn tiền dự kiến".
     *
     * Tách thành một request riêng chạy SAU khi kết quả đã hiện, không nhét vào lượt quét: nguồn
     * hoa hồng là proxy bên thứ ba (ShopeeProductLookupService) timeout 10 giây và không có SLA,
     * trong khi lượt quét vốn đã phải chờ nguồn mã. Hỏng thì khách chỉ mất một con số ước tính,
     * không ai phải chờ thêm và luồng mua không hề đụng tới.
     *
     * Trả về tiền chứ không trả tỉ lệ: `cashback_rate` của nguồn là PHÂN SỐ (0.14 = 14%) chứ
     * không phải phần trăm, và giá hiển thị không phải lúc nào cũng là giá nguồn dùng để tính
     * hoa hồng — đẩy phép nhân ra frontend là mời gọi sai 100 lần.
     */
    public function commission(string $itemId, CashbackService $cashback): JsonResponse
    {
        if (! preg_match('/^\d{5,20}$/', $itemId)) {
            return response()->json(['commission' => null], 422);
        }

        // Chương trình hoàn tiền đang tắt thì không có gì để ước tính, và cũng không việc gì phải
        // gọi sang nguồn ngoài.
        if ($cashback->displayRate() <= 0) {
            return response()->json(['commission' => null]);
        }

        // 6 giờ: hoa hồng một sản phẩm gần như không đổi trong ngày, mà đây là nguồn ngoài —
        // mỗi khách dán cùng một link hot lại gọi một lượt là vừa chậm vừa dễ bị chặn.
        $commission = Cache::remember(
            "shopee_commission:{$itemId}",
            now()->addHours(6),
            fn () => (float) ($this->productLookup->getByItemId($itemId)['commission'] ?? 0),
        );

        return response()->json(['commission' => $commission > 0 ? $commission : null]);
    }

    /**
     * Hai cờ điều khiển giao diện, cùng đọc từ một bản ghi cấu hình Facebook:
     *
     * - facebookMode: 'reel' thì khách bấm link trong PHẦN MÔ TẢ của reel, 'comment' thì bấm
     *   link trong BÌNH LUẬN dưới bài viết. Hai chỗ khác nhau nên câu hướng dẫn cho khách phải
     *   khác nhau — chỉ đúng một chỗ có link, chỉ sai chỗ là khách loay hoay rồi thoát.
     *
     * - viaFacebookComment: cú bấm "Mua ngay" sẽ dẫn tới COMMENT TRÊN FACEBOOK chứ không phải
     *   thẳng Shopee. Giao diện bắt buộc phải biết điều này để nói trước cho khách — nếu không
     *   khách bấm "Mua ngay" mà hiện ra Facebook thì tưởng bị lỗi/lừa và thoát luôn.
     *   Điều kiện phải khớp ĐÚNG với ShortLinkController::facebookCommentRedirectUrl(): thiếu
     *   Page ID/token/bài viết thì bên đó tự rơi về Shopee, hứa trước là hứa sai.
     *
     * - autoRedirect: bỏ luôn bước bấm nút, dán link xong đi thẳng. Chỉ có nghĩa khi đã bật
     *   chuyển hướng qua comment — mục đích là mỗi sản phẩm chỉ sinh đúng 1 comment thay vì
     *   mỗi lượt bấm lại thêm một cái.
     *   Thời salesoc, ô này là DANH SÁCH chọn loại mã (meta.auto_source) vì một sản phẩm có
     *   nhiều mã. kieushopee chỉ trả một link nên không còn gì để chọn, ô đó thành công tắc
     *   (meta.auto_redirect_enabled) — vẫn đọc auto_source cũ để cấu hình đang chạy trên
     *   production không mất tác dụng ngay sau khi đổi nguồn.
     *
     * Logic đã chuyển sang FacebookRedirectFlagService vì kho mẫu bài đăng của admin cũng cần
     * đúng mấy cờ này — bài đăng mà mô tả sai luồng (dạy "bấm nút Mua ngay" trong khi nút đang
     * tên "Lấy mã qua Facebook") thì khách làm theo không ra kết quả.
     *
     * @return array{viaFacebookComment: bool, autoRedirect: bool, facebookMode: string}
     */
    private function facebookRedirectFlags(): array
    {
        return app(FacebookRedirectFlagService::class)->flags();
    }

    /**
     * Thay URL affiliate thật bằng token mờ trước khi trả về frontend — URL thật chỉ được
     * giải mã lại phía server (xem ShortLinkController::store()) khi người dùng thực sự bấm
     * "Mua ngay", để không lộ URL affiliate gốc ngay trong response /voucher/resolve (xem
     * được qua tab Network/Inertia devtools dù chưa bấm link nào).
     */
    private function maskVoucherLink(VoucherFetchResult $result): string
    {
        // Lưu kèm NGUỒN đã sinh ra link này, không chỉ mỗi URL: lúc khách bấm "Mua ngay",
        // ShortLinkController mới biết ghi `source` nào vào short-link và tracking. Suy ngược
        // từ URL thì không đáng tin — cả hai nguồn đều có thể trả về link trên domain Shopee.
        //
        // sourceUrl (URL Shopee đã đưa vào fetchProductAndVoucherLink) đi kèm để
        // ShortLinkController gọi lại đúng nguồn lấy mã mới nếu khách bấm "Mua ngay" khi ref
        // đã cũ — mã có thể đã hết lượt, link đã lưu không còn dùng được.
        //
        // ytbUrl (chế độ mã YTB) đi kèm để lúc bấm mua xâu vào trước link đích.
        return $this->refs->issue($result->data['voucher_link'], $result->source, $result->sourceUrl, $result->ytbUrl);
    }
}
