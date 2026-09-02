<?php

namespace App\Http\Controllers;

use App\Exceptions\AffiliateScanException;
use App\Models\PlatformVoucher;
use App\Services\SalesOcService;
use App\Services\ShopeeLinkResolverService;
use App\Services\TrackingService;
use App\Services\UrlValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $voucherLinks = $salesOcData['voucher_links'] ?? [];
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
        ]);
    }
}
