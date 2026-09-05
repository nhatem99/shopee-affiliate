<?php

namespace App\Http\Controllers;

use App\Exceptions\AffiliateScanException;
use App\Jobs\ResolveVoucherLinks;
use App\Models\PlatformVoucher;
use App\Models\VoucherButtonConfig;
use App\Services\ShopeeLinkResolverService;
use App\Services\TrackingService;
use App\Services\UrlValidationService;
use Illuminate\Http\JsonResponse;
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
        private TrackingService $tracking,
    ) {}

    /**
     * Nhận link khách dán vào và ĐẨY việc đi tìm mã sang hàng đợi, trả ngay một token để frontend
     * hỏi lại kết quả (xem status()).
     *
     * Vì sao không làm thẳng trong request như trước: đường tự đúc mã (App\Services\ChannelVoucher)
     * tốn 10-60s vì phải comment lên Facebook rồi mở Chromium thật. Giữ nguyên kiểu đồng bộ thì
     * request đụng trần timeout của nginx/php-fpm và khách chỉ thấy trang treo rồi 504.
     */
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

        // Tên sản phẩm chưa biết ở thời điểm này (việc tra cứu nằm trong job) — chấp nhận mất
        // trường đó trong sự kiện 'url_paste' để đổi lấy việc trả trang về ngay lập tức.
        $this->tracking->log('url_paste', $request, [
            'url' => $canonicalUrl,
            'platform' => 'shopee',
        ]);

        $token = Str::random(32);
        Cache::put(ResolveVoucherLinks::cacheKey($token), ['status' => 'pending'], now()->addMinutes(10));
        ResolveVoucherLinks::dispatch($token, $canonicalUrl);

        return Inertia::render('Home', [
            'vouchers' => PlatformVoucher::suggestedList(),
            // Frontend cầm token này đi hỏi status() cho tới khi có kết quả.
            'voucherJob' => ['token' => $token, 'canonical_url' => $canonicalUrl],
            'voucherButtonConfig' => VoucherButtonConfig::orderBy('sort_order')->get(['source', 'label', 'sort_order', 'is_featured']),
        ]);
    }

    /**
     * Frontend hỏi lại kết quả của một lượt quét. Trả về đúng shape mà Home.vue đang render
     * (product / voucher_labels / voucher_links) khi xong.
     */
    public function status(string $token): JsonResponse
    {
        $payload = Cache::get(ResolveVoucherLinks::cacheKey($token));

        if (! $payload) {
            // Hết hạn hoặc token bịa. Không phân biệt hai trường hợp: với khách thì cách xử lý
            // giống nhau (quét lại), còn phân biệt ra chỉ giúp người dò token biết mình dò trúng.
            return response()->json([
                'status' => 'expired',
                'message' => 'Phiên tìm mã đã hết hạn, vui lòng dán lại link.',
            ]);
        }

        return response()->json($payload);
    }
}
