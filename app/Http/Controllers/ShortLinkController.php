<?php

namespace App\Http\Controllers;

use App\Exceptions\AffiliateScanException;
use App\Models\ApiConfig;
use App\Services\AffiliateLinkRewriterService;
use App\Services\FacebookPageService;
use App\Services\ShortLinkService;
use App\Services\TrackingService;
use App\Services\UrlValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShortLinkController extends Controller
{
    public function __construct(
        private UrlValidationService $urlValidator,
        private ShortLinkService $shortLinks,
        private AffiliateLinkRewriterService $rewriter,
        private TrackingService $tracking,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // 'ref' là token mờ do ShopeeVoucherController::maskVoucherLinks() phát ra — URL
            // affiliate thật (salesoc.vn/s.afp.ad) không bao giờ đi qua request body, tránh lộ
            // URL gốc cho ai xem được request này (Network tab, log trung gian...).
            'ref' => ['required', 'string', 'size:32'],
            'source' => ['nullable', 'string', 'in:facebook,instagram,zalo,youtube'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'product_image' => ['nullable', 'url', 'max:2000'],
            'voucher_label' => ['nullable', 'string', 'max:100'],
        ]);

        $url = Cache::get("voucher_ref:{$validated['ref']}");

        if (! $url) {
            return response()->json(['message' => 'Link đã hết hạn, vui lòng tải lại trang và thử lại.'], 422);
        }

        try {
            $this->urlValidator->validateAffiliateRedirectUrl($url);
        } catch (AffiliateScanException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Log::info('ShortLinkController: người dùng bấm "Mua ngay"', [
            'source' => $validated['source'] ?? null,
            'product_name' => $validated['product_name'] ?? null,
            'salesoc_url' => $url,
        ]);

        $targetUrl = $this->rewriter->rewriteToOwnAffiliate($url);

        $link = $this->shortLinks->create(
            $targetUrl,
            $validated['source'] ?? null,
            $validated['product_name'] ?? null,
            $validated['product_image'] ?? null,
        );

        Log::info('ShortLinkController: đã tạo short-link', [
            'code' => $link->code,
            'target_url' => $targetUrl,
        ]);

        $shortUrl = url('/go/'.$link->code);

        $redirectUrl = $this->facebookCommentRedirectUrl(
            $validated['source'] ?? null,
            $validated['product_name'] ?? null,
            $link->code,
            $shortUrl,
            $request->userAgent(),
        );

        $this->tracking->log('voucher_select', $request, [
            'url' => $targetUrl,
            'source' => $validated['source'] ?? null,
            'product_name' => $validated['product_name'] ?? null,
            'voucher_code' => $validated['voucher_label'] ?? null,
        ]);

        return response()->json([
            'code' => $link->code,
            'short_url' => $redirectUrl,
        ]);
    }

    public function redirect(Request $request, string $code): RedirectResponse|Response
    {
        $link = $this->shortLinks->find($code);

        abort_if($link === null, 404);

        // Facebook/Zalo/Telegram... không hiển thị preview bằng link mình gửi — bot của họ tự
        // ghé thăm target_url (Shopee), đọc thẻ og:url CANONICAL (không có mmp_pid/mã giảm giá)
        // của Shopee rồi lấy chính URL đó thay cho link gốc khi hiển thị/redirect cho người dùng
        // cuối, khiến affiliate ID + mã giảm giá bị "bóc" mất trước khi người dùng kịp bấm.
        // => Với bot: trả HTML có og:url tự trỏ về chính /go/{code} (không đi theo target_url)
        // để bot không "thấy" được URL sạch của Shopee. Với người dùng thật: 302 như cũ.
        if (TrackingService::isBot($request->userAgent())) {
            return response()->view('short-link-preview', ['link' => $link]);
        }

        $this->shortLinks->trackClick($link);

        Log::info('ShortLinkController: redirect thật khi bấm link', [
            'code' => $code,
            'target_url' => $link->target_url,
        ]);

        $this->tracking->log('short_link_click', $request, ['url' => $link->target_url]);

        // Đã tắt tạm bọc intent:// (mở thẳng app Shopee trên Android) — gây lỗi 502 thật trên
        // production ngay sau khi bấm link. Nguyên nhân đang được điều tra (nghi do hạ tầng
        // proxy/CDN phía trước xử lý Location header khác với local); quay lại redirect thẳng
        // như trước cho tới khi tìm ra và kiểm chứng được cách làm đúng.
        return redirect()->away($link->target_url, 302);
    }

    /**
     * Khi khách bấm bất kỳ mã nào VÀ admin đã bật "Bật chuyển hướng qua comment Facebook"
     * (meta.comment_redirect_enabled ở /admin/api-config, provider 'facebook'), đăng link
     * affiliate ($fallbackUrl) làm comment vào bài viết admin đã chọn (meta.target_post_id)
     * trên fanpage, rồi trả về permalink của CHÍNH comment đó thay vì link Shopee — khách
     * phải mở Facebook, bấm short-link trong comment thì mới thật sự tới Shopee, để lượt
     * click được tính là traffic từ Facebook thật. Khi tắt (mặc định), khách bấm mã đi
     * thẳng $fallbackUrl (/go/{code} → Shopee) như hành vi gốc, không đụng gì tới Facebook.
     *
     * Giới hạn 1 comment/sản phẩm/loại mã mỗi 20 phút để không bị Facebook đánh dấu spam khi
     * sản phẩm hot có nhiều lượt bấm liên tục — trong khung đó, các lượt bấm LẶP LẠI cùng
     * loại mã tái sử dụng permalink đã đăng thay vì đăng comment mới. Tính riêng theo từng
     * loại mã (không gộp chung theo sản phẩm) vì mỗi mã trỏ tới voucher khác nhau — gộp chung
     * sẽ đưa nhầm khách bấm mã IG tới đúng comment/voucher của mã FB đã đăng trước đó.
     *
     * Nếu đăng comment thất bại (chưa cấu hình, token lỗi, Facebook sập...) thì trả về
     * $fallbackUrl để không chặn đường mua hàng của khách.
     */
    private function facebookCommentRedirectUrl(?string $source, ?string $productName, string $shortCode, string $fallbackUrl, ?string $userAgent): string
    {
        if ($source === null) {
            return $fallbackUrl;
        }

        $config = ApiConfig::where('platform', 'facebook')->where('is_active', true)->first();

        if (! $config || ! ($config->meta['comment_redirect_enabled'] ?? false)) {
            return $fallbackUrl;
        }

        $postId = $config->meta['target_post_id'] ?? null;

        if (! $config->app_id || ! $config->app_secret || ! $postId) {
            Log::warning('ShortLinkController: đã bật comment_redirect_enabled nhưng thiếu Page ID/Token/target_post_id.');

            return $fallbackUrl;
        }

        // Gộp theo cả $source lẫn tên sản phẩm — mỗi loại mã (FB/YTB/IG/Zalo) trỏ tới voucher
        // khác nhau (khác $fallbackUrl), nên gộp chung chỉ theo sản phẩm sẽ đưa nhầm khách bấm
        // mã IG tới comment/voucher của mã FB đã đăng trước đó trong cùng cửa sổ cooldown.
        $productKey = Str::slug($productName ?: $shortCode) ?: $shortCode;
        $cacheKey = "fb_comment_link:{$source}:{$productKey}";

        if ($cached = Cache::get($cacheKey)) {
            return $this->toFacebookAppLink($cached, $userAgent);
        }

        $displayName = $productName ?: 'Sản phẩm Shopee';
        $message = "🔥 {$displayName}\n🎟️ Mã giảm giá đang chờ bạn!\n👉 Bấm vào link dưới đây để lấy mã & mua ngay:\n{$fallbackUrl}";

        $permalink = (new FacebookPageService($config->app_id, $config->app_secret))->postComment($postId, $message);

        if (! $permalink) {
            return $fallbackUrl;
        }

        Cache::put($cacheKey, $permalink, now()->addMinutes(20));

        return $this->toFacebookAppLink($permalink, $userAgent);
    }

    /**
     * Trên mobile, mở thẳng bằng URL https://facebook.com/... hay bị OS/trình duyệt xử lý dở:
     * có lúc app Facebook mở ra nhưng không tới đúng bài/comment (chỉ về trang chủ), có lúc lại
     * đòi đăng nhập lại dù đã đăng nhập trong app (trình duyệt mobile là 1 phiên đăng nhập khác
     * với app). Dùng thẳng scheme fb://facewebmodal/f?href=... để mở NGAY trong app Facebook
     * (nếu đã cài) bằng chính phiên đăng nhập của app, không qua trình duyệt/login-wall nữa.
     * Chỉ áp dụng cho mobile — desktop không có app Facebook để bắt scheme này, giữ nguyên
     * link https:// gốc (đã xác nhận hoạt động đúng trên desktop).
     */
    private function toFacebookAppLink(string $permalink, ?string $userAgent): string
    {
        if (! TrackingService::isMobile($userAgent)) {
            return $permalink;
        }

        return 'fb://facewebmodal/f?href='.urlencode($permalink);
    }
}
