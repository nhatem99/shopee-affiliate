<?php

namespace App\Http\Controllers;

use App\Exceptions\AffiliateScanException;
use App\Models\ApiConfig;
use App\Services\AffiliateLinkRewriterService;
use App\Services\FacebookPageService;
use App\Services\KieuShopeeService;
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
            // 'ref' là token mờ do ShopeeVoucherController::maskVoucherLink() phát ra — URL
            // affiliate thật không bao giờ đi qua request body, tránh lộ URL gốc cho ai xem
            // được request này (Network tab, log trung gian...).
            'ref' => ['required', 'string', 'size:32'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'product_image' => ['nullable', 'url', 'max:2000'],
        ]);

        $url = Cache::get("voucher_ref:{$validated['ref']}");

        if (! $url) {
            return response()->json(['message' => 'Link đã hết hạn, vui lòng tải lại trang và thử lại.'], 422);
        }

        try {
            $this->urlValidator->validateAffiliateRedirectUrl($url);
        } catch (AffiliateScanException $e) {
            // Nguồn cấp mã có thể đổi domain trung gian bất kỳ lúc nào. Khi đó khách chỉ thấy
            // "Link không hợp lệ" — log kèm host để biết ngay cần thêm domain nào vào
            // UrlValidationService::$allowedRedirectDomains, khỏi phải mò lại từ đầu.
            Log::warning('ShortLinkController: link voucher bị chặn vì domain lạ', [
                'host' => parse_url($url, PHP_URL_HOST),
                'voucher_url' => $url,
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        Log::info('ShortLinkController: người dùng bấm "Mua ngay"', [
            'product_name' => $validated['product_name'] ?? null,
            'voucher_url' => $url,
        ]);

        $targetUrl = $this->rewriter->rewriteToOwnAffiliate($url);

        // Chỉ còn một nguồn mã duy nhất nên source là hằng số phía server, không nhận từ
        // client nữa (trước đây client gửi lên facebook/zalo/... vì salesoc trả nhiều kênh).
        $link = $this->shortLinks->create(
            $targetUrl,
            KieuShopeeService::SOURCE,
            $validated['product_name'] ?? null,
            $validated['product_image'] ?? null,
        );

        Log::info('ShortLinkController: đã tạo short-link', [
            'code' => $link->code,
            'target_url' => $targetUrl,
        ]);

        $shortUrl = url('/go/'.$link->code);

        $redirectUrl = $this->facebookCommentRedirectUrl(
            $validated['product_name'] ?? null,
            $link->code,
            $shortUrl,
        );

        $this->tracking->log('voucher_select', $request, [
            'url' => $targetUrl,
            'source' => KieuShopeeService::SOURCE,
            'product_name' => $validated['product_name'] ?? null,
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
     * Khi khách bấm "Mua ngay" VÀ admin đã bật "Bật chuyển hướng qua comment Facebook"
     * (meta.comment_redirect_enabled ở /admin/api-config, provider 'facebook'), đăng link
     * affiliate ($fallbackUrl) làm comment vào bài viết admin đã chọn (meta.target_post_id)
     * trên fanpage, rồi trả về permalink của CHÍNH comment đó thay vì link Shopee — khách
     * phải mở Facebook, bấm short-link trong comment thì mới thật sự tới Shopee, để lượt
     * click được tính là traffic từ Facebook thật. Khi tắt (mặc định), khách bấm mã đi
     * thẳng $fallbackUrl (/go/{code} → Shopee) như hành vi gốc, không đụng gì tới Facebook.
     *
     * Lớp này thao tác trên SHORT-LINK của mình chứ không phải link gốc, nên nó độc lập với
     * nguồn cấp mã — đổi salesoc sang kieushopee không ảnh hưởng gì tới cách nó chạy.
     *
     * Giới hạn 1 comment/sản phẩm mỗi 20 phút để không bị Facebook đánh dấu spam khi sản phẩm
     * hot có nhiều lượt bấm liên tục — trong khung đó, các lượt bấm lặp lại tái sử dụng
     * permalink đã đăng thay vì đăng comment mới. Thời salesoc khoá cache còn phải tính thêm
     * loại mã vì mỗi loại trỏ tới voucher khác nhau; kieushopee chỉ trả một link cho mỗi sản
     * phẩm nên tên sản phẩm là đủ.
     *
     * Nếu đăng comment thất bại (chưa cấu hình, token lỗi, Facebook sập...) thì trả về
     * $fallbackUrl để không chặn đường mua hàng của khách.
     */
    private function facebookCommentRedirectUrl(?string $productName, string $shortCode, string $fallbackUrl): string
    {
        $config = ApiConfig::where('platform', 'facebook')->where('is_active', true)->first();

        if (! $config || ! ($config->meta['comment_redirect_enabled'] ?? false)) {
            return $fallbackUrl;
        }

        $postId = $config->meta['target_post_id'] ?? null;

        if (! $config->app_id || ! $config->app_secret || ! $postId) {
            Log::warning('ShortLinkController: đã bật comment_redirect_enabled nhưng thiếu Page ID/Token/target_post_id.');

            return $fallbackUrl;
        }

        $productKey = Str::slug($productName ?: $shortCode) ?: $shortCode;
        $cacheKey = 'fb_comment_link:'.KieuShopeeService::SOURCE.":{$productKey}";

        if ($cached = Cache::get($cacheKey)) {
            return $this->commentUrl($cached, $postId);
        }

        $displayName = $productName ?: 'Sản phẩm Shopee';
        $message = "🔥 {$displayName}\n🎟️ Mã giảm giá đang chờ bạn!\n👉 Bấm vào link dưới đây để lấy mã & mua ngay:\n{$fallbackUrl}";

        $posted = (new FacebookPageService($config->app_id, $config->app_secret))->postComment($postId, $message);

        if (! $posted) {
            return $fallbackUrl;
        }

        Cache::put($cacheKey, $posted, now()->addMinutes(20));

        return $this->commentUrl($posted, $postId);
    }

    /**
     * Ghép URL story.php trỏ tới đúng comment, thay cho permalink_url dạng
     * /{actor_id}/posts/{post_id} mà Graph API trả về.
     *
     * Đã test trên máy thật: mọi biến thể scheme fb:// (permalink.php, story, facewebmodal)
     * đều KHÔNG mở đúng comment — app Facebook chỉ mở ra trang chủ hoặc báo nội dung không
     * hiển thị. Riêng URL web thường thì chạy đúng, nên bỏ hẳn hướng custom scheme (cũng không
     * còn cần phân biệt mobile/desktop nữa). Dùng story.php vì nó ghép từ đúng Page ID trong
     * cấu hình, không phụ thuộc actor_id lạ mà permalink_url hay trả về.
     *
     * Nếu thiếu dữ liệu để ghép thì rơi về permalink_url gốc của Graph API.
     */
    private function commentUrl(array $posted, string $postId): string
    {
        $commentId = $posted['comment_id'] ?? null;
        [$pageId, $storyFbid] = array_pad(explode('_', $postId, 2), 2, null);

        if (! $commentId || ! $pageId || ! $storyFbid) {
            return $posted['permalink_url'];
        }

        return 'https://www.facebook.com/story.php?'.http_build_query([
            'story_fbid' => $storyFbid,
            'id' => $pageId,
            'comment_id' => $commentId,
        ]);
    }
}
