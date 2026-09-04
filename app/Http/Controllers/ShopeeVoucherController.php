<?php

namespace App\Http\Controllers;

use App\Exceptions\AffiliateScanException;
use App\Models\ApiConfig;
use App\Models\PlatformVoucher;
use App\Models\VoucherButtonConfig;
use App\Services\SalesOcService;
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
        private SalesOcService $salesOc,
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
        $ids = $this->resolver->extractIds($canonicalUrl);

        // salesoc.vn cung cấp thông tin hiển thị (tên/ảnh/giá) + link áp mã giảm giá thật.
        // Link voucher_links thuộc affiliate account của salesoc.vn (không sửa được mmp_pid)
        // nhưng là cách duy nhất người dùng nhận được mã giảm giá thật — xem SalesOcService.
        $salesOcData = $this->salesOc->fetchProductAndVoucherLabels($canonicalUrl);

        $voucherLabels = $salesOcData['voucher_labels'] ?? [];
        $voucherLinks = $this->maskVoucherLinks($salesOcData['voucher_links'] ?? []);
        unset($salesOcData['voucher_labels'], $salesOcData['voucher_links']);

        $product = $salesOcData ?? ($ids
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
                'voucher_labels' => $voucherLabels,
                // Link CTA chính — thuộc affiliate account của salesoc.vn (đổi lấy mã giảm giá thật).
                'voucher_links' => $voucherLinks,
            ],
            // Admin-editable display config: sort order, label override, featured source.
            'voucherButtonConfig' => VoucherButtonConfig::orderBy('sort_order')->get(['source', 'label', 'sort_order', 'is_featured']),
            'autoRedirectSource' => $this->autoRedirectSource(),
        ]);
    }

    /**
     * Loại mã admin chọn sẵn để tự động dùng (meta.auto_source ở /admin/api-config) khi đã bật
     * chuyển hướng qua comment Facebook. Khi có giá trị, Home.vue ẩn hết nút chọn mã và đưa
     * khách thẳng tới comment ngay sau khi dán link — mỗi sản phẩm vì thế chỉ sinh đúng 1
     * comment thay vì mỗi loại mã khách bấm lại thêm 1 cái.
     */
    private function autoRedirectSource(): ?string
    {
        $config = ApiConfig::where('platform', 'facebook')->where('is_active', true)->first();

        if (! $config || ! ($config->meta['comment_redirect_enabled'] ?? false)) {
            return null;
        }

        return $config->meta['auto_source'] ?? null;
    }

    /**
     * Thay URL affiliate thật (salesoc.vn/s.afp.ad) bằng token mờ trước khi trả về frontend —
     * URL thật chỉ được giải mã lại phía server (xem ShortLinkController::store()) khi người
     * dùng thực sự bấm chọn, để không lộ URL affiliate gốc ngay trong response /voucher/resolve
     * (xem được qua tab Network/Inertia devtools dù chưa bấm link nào).
     */
    private function maskVoucherLinks(array $voucherLinks): array
    {
        foreach ($voucherLinks as $source => $options) {
            foreach ($options as $i => $option) {
                $ref = Str::random(32);
                // TTL dài (7 ngày) để nút "mua lại" trong lịch sử chuyển đổi (lưu ở localStorage,
                // xem Home.vue) còn dùng được sau vài ngày — mã có thể hết lượt trước đó, nhưng
                // đó vốn là giới hạn có sẵn (salesoc.vn không báo trạng thái còn/hết lượt).
                Cache::put("voucher_ref:{$ref}", $option['url'], now()->addDays(7));
                $voucherLinks[$source][$i] = ['label' => $option['label'], 'ref' => $ref];
            }
        }

        return $voucherLinks;
    }
}
