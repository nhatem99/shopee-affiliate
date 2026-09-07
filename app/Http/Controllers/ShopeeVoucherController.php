<?php

namespace App\Http\Controllers;

use App\Exceptions\AffiliateScanException;
use App\Models\ApiConfig;
use App\Models\PlatformVoucher;
use App\Services\KieuShopeeService;
use App\Services\ShopeeLinkResolverService;
use App\Services\TrackingService;
use App\Services\UrlValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ShopeeVoucherController extends Controller
{
    public function __construct(
        private UrlValidationService $urlValidator,
        private ShopeeLinkResolverService $resolver,
        private KieuShopeeService $kieuShopee,
        private TrackingService $tracking,
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

        try {
            $this->urlValidator->validateShopeeOnly($url);
        } catch (AffiliateScanException $e) {
            return back()->withErrors(['voucher_url' => $e->getMessage()]);
        }

        $canonicalUrl = $this->resolver->resolveCanonicalUrl($url);

        // kieushopee trả về ĐÚNG MỘT link đã áp mã (kèm thông tin sản phẩm nếu đọc được).
        // Link đó thuộc affiliate account của họ; mmp_pid được đổi về của mình khi người dùng
        // bấm "Mua ngay" — xem KieuShopeeService + AffiliateLinkRewriterService.
        $data = $this->kieuShopee->fetchProductAndVoucherLink($canonicalUrl);

        // ID lấy từ URL là nguồn chính; nếu URL không có dạng -i.SHOP.ITEM thì mượn ID mà
        // kieushopee đã phân giải hộ, để vẫn hỏi được Shopee khi thiếu thông tin sản phẩm.
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

        return Inertia::render('Home', [
            'vouchers' => PlatformVoucher::suggestedList(),
            'voucherResult' => [
                'canonical_url' => $canonicalUrl,
                'product' => $product,
                // Token mờ của link CTA duy nhất; null nghĩa là chưa lấy được mã cho sản phẩm này.
                'voucher_ref' => isset($data['voucher_link']) ? $this->maskVoucherLink($data['voucher_link']) : null,
            ],
            ...$this->facebookRedirectFlags(),
        ]);
    }

    /**
     * Hai cờ điều khiển giao diện, cùng đọc từ một bản ghi cấu hình Facebook:
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
     * @return array{viaFacebookComment: bool, autoRedirect: bool}
     */
    private function facebookRedirectFlags(): array
    {
        $config = ApiConfig::where('platform', 'facebook')->where('is_active', true)->first();

        $viaComment = $config
            && ($config->meta['comment_redirect_enabled'] ?? false)
            && $config->app_id
            && $config->app_secret
            && ($config->meta['target_post_id'] ?? null);

        if (! $viaComment) {
            return ['viaFacebookComment' => false, 'autoRedirect' => false];
        }

        return [
            'viaFacebookComment' => true,
            'autoRedirect' => (bool) ($config->meta['auto_redirect_enabled'] ?? ! empty($config->meta['auto_source'])),
        ];
    }

    /**
     * Thay URL affiliate thật bằng token mờ trước khi trả về frontend — URL thật chỉ được
     * giải mã lại phía server (xem ShortLinkController::store()) khi người dùng thực sự bấm
     * "Mua ngay", để không lộ URL affiliate gốc ngay trong response /voucher/resolve (xem
     * được qua tab Network/Inertia devtools dù chưa bấm link nào).
     */
    private function maskVoucherLink(string $voucherLink): string
    {
        $ref = Str::random(32);

        // TTL dài (7 ngày) để nút "mua lại" trong lịch sử chuyển đổi (lưu ở localStorage,
        // xem Home.vue) còn dùng được sau vài ngày — mã có thể hết lượt trước đó, nhưng
        // đó vốn là giới hạn có sẵn (nguồn không báo trạng thái còn/hết lượt).
        Cache::put("voucher_ref:{$ref}", $voucherLink, now()->addDays(7));

        return $ref;
    }
}
