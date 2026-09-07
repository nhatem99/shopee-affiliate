<?php

namespace App\Http\Controllers;

use App\Exceptions\AffiliateScanException;
use App\Models\ApiConfig;
use App\Services\AffiliateLinkRewriterService;
use App\Services\FacebookPageService;
use App\Services\FacebookPostTarget;
use App\Services\FacebookReelSlotService;
use App\Services\KieuShopeeService;
use App\Services\ShortLinkService;
use App\Services\TrackingService;
use App\Services\UrlValidationService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShortLinkController extends Controller
{
    /** Con trỏ xoay vòng nhóm bài viết nhận comment — xem pickTargetPost. */
    private const POST_CURSOR_KEY = 'fb_comment_post_cursor';

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
            // og:image của trang preview lấy thẳng từ đây (short-link-preview.blade.php).
            // Thiếu ảnh trong card Facebook thì nhìn dòng này là biết ngay lỗi ở nguồn cấp
            // (không trả ảnh) hay ở khâu hiển thị, khỏi phải mò từ đầu.
            'product_image' => $link->product_image ?? '(khong co — card FB se khong co anh)',
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
     * Nhiều khách bấm cùng lúc được xử lý ở hai tầng:
     *  • KHÁC sản phẩm → mỗi sản phẩm rơi vào một bài viết khác nhau trong nhóm bài đã cấu hình
     *    (xem pickTargetPost), nên comment không dồn hết vào một bài.
     *  • CÙNG sản phẩm → khoá theo sản phẩm, chỉ một request được đăng, các request còn lại
     *    chờ rồi dùng lại đúng comment đó thay vì đăng trùng.
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

        $productKey = Str::slug($productName ?: $shortCode) ?: $shortCode;

        // Chế độ đổi caption reel được ưu tiên khi bật: đo trên máy thật thì chỉ link /reel/ mới
        // mở được ứng dụng Facebook, VÀ chỉ link trong caption reel mới bấm được (link trong
        // bình luận reel hiện thành text thường). Hết slot reel thì rơi tiếp xuống chế độ
        // comment bên dưới, rồi cuối cùng mới tới $fallbackUrl.
        if ($config->facebookReelCaptionEnabled() && $config->app_id && $config->app_secret) {
            $reelUrl = (new FacebookReelSlotService($config))->reelUrlFor(
                $productKey,
                $productName ?: 'Sản phẩm Shopee',
                $fallbackUrl,
            );

            if ($reelUrl) {
                return $reelUrl;
            }
        }

        $pool = $config->facebookTargetPostIds();

        if (! $config->app_id || ! $config->app_secret || ! $pool) {
            Log::warning('ShortLinkController: đã bật comment_redirect_enabled nhưng thiếu Page ID/Token/bài viết đích.');

            return $fallbackUrl;
        }

        $cacheKey = 'fb_comment_link:'.KieuShopeeService::SOURCE.":{$productKey}";

        if ($cached = Cache::get($cacheKey)) {
            return $this->cachedCommentUrl($cached, $pool);
        }

        // Tới đây là chưa có comment cho sản phẩm này. Nếu hai khách cùng bấm đúng lúc thì cả
        // hai đều trượt cache ở trên và cùng đăng comment — trùng nội dung, phí quota, và dễ bị
        // Facebook coi là spam. Khoá lại để chỉ một request đăng; request còn lại chờ xong rồi
        // dùng chung kết quả (đọc lại cache ngay sau khi giành được khoá).
        $lock = Cache::lock($cacheKey.':lock', 30);

        try {
            $lock->block(15);
        } catch (LockTimeoutException $e) {
            Log::warning('ShortLinkController: chờ quá lâu khoá đăng comment Facebook', ['product' => $productKey]);

            return $fallbackUrl;
        }

        try {
            return $this->postCommentAndBuildUrl($config, $pool, $cacheKey, $productName, $fallbackUrl);
        } finally {
            $lock->release();
        }
    }

    /**
     * Phần đăng comment thật, chạy khi đã giữ khoá theo sản phẩm.
     *
     * @param  list<string>  $pool
     */
    private function postCommentAndBuildUrl(ApiConfig $config, array $pool, string $cacheKey, ?string $productName, string $fallbackUrl): string
    {
        // Request kia có thể vừa đăng xong trong lúc mình đang chờ khoá — đọc lại trước khi đăng.
        if ($cached = Cache::get($cacheKey)) {
            return $this->cachedCommentUrl($cached, $pool);
        }

        $postId = $this->pickTargetPost($pool);
        $target = FacebookPostTarget::parse($postId);
        $displayName = $productName ?: 'Sản phẩm Shopee';

        // Khách vừa bị chuyển từ web sang Facebook nên đang hơi mất phương hướng — câu chữ ở
        // đây phải nói thẳng "bấm link NÀY" và cảnh báo đóng giữa chừng là mất mã, vì đây là
        // bước cuối cùng quyết định mã có được áp hay không.
        $message = implode("\n", [
            "🔥 {$displayName}",
            '🎟️ Mã giảm giá đã sẵn sàng — bấm đúng link ngay dưới đây để nhận:',
            $fallbackUrl,
            '',
            '⚠️ Bấm link ở trên mới được giảm giá. Tìm sản phẩm thẳng trên Shopee thì mã không áp được.',
        ]);

        $posted = (new FacebookPageService($config->app_id, $config->app_secret))->postComment($target->graphId, $message);

        if (! $posted) {
            return $fallbackUrl;
        }

        // Nhớ luôn bài đã đăng: với nhiều bài trong nhóm, không lưu thì lượt bấm sau lấy lại
        // comment từ cache mà ghép nhầm vào bài khác → link mở ra bài không chứa comment đó.
        $posted['post_id'] = $postId;

        Cache::put($cacheKey, $posted, now()->addMinutes(20));

        return $this->commentUrl($posted, $postId);
    }

    /**
     * Dựng lại URL từ comment đã cache. Bản ghi cache cũ (trước khi có nhóm bài) không kèm
     * post_id — rơi về bài đầu nhóm, đúng với thời điểm chỉ có một bài duy nhất.
     *
     * @param  array{comment_id?: ?string, permalink_url: string, post_id?: string}  $cached
     * @param  list<string>  $pool
     */
    private function cachedCommentUrl(array $cached, array $pool): string
    {
        return $this->commentUrl($cached, $cached['post_id'] ?? $pool[0]);
    }

    /**
     * Chọn bài viết cho comment sắp đăng, xoay vòng lần lượt qua nhóm bài đã cấu hình.
     *
     * Con trỏ nằm ở cache (dùng chung giữa các request) nên hai khách bấm gần như cùng lúc với
     * hai sản phẩm khác nhau sẽ nhận hai bài khác nhau, thay vì cùng đổ vào một bài. Increment
     * của cache là thao tác nguyên tử trên Redis; các store khác có thể trả về false khi khoá
     * chưa tồn tại nên phải khởi tạo thủ công.
     *
     * @param  list<string>  $pool
     */
    private function pickTargetPost(array $pool): string
    {
        if (count($pool) === 1) {
            return $pool[0];
        }

        $cursor = Cache::increment(self::POST_CURSOR_KEY);

        if (! is_int($cursor)) {
            Cache::forever(self::POST_CURSOR_KEY, 1);
            $cursor = 1;
        }

        return $pool[$cursor % count($pool)];
    }

    /**
     * Ghép URL công khai trỏ tới đúng comment, thay cho permalink_url dạng
     * /{actor_id}/posts/{post_id} mà Graph API trả về (actor_id lạ, không mở đúng trên app).
     *
     * Dạng URL phụ thuộc loại bài đích — reel thì /reel/{id}, bài thường thì
     * /{page_id}/posts/{story_fbid}; xem FacebookPostTarget để biết đo trên máy thật thì mỗi
     * loại được/mất gì (tóm tắt: reel mở được app nhưng link trong bình luận reel không bấm
     * được, nên hiện phải dùng bài viết thường).
     *
     * Lưu ý: chỉ URL thôi là chưa đủ để mở được app — phía client khách phải CHẠM VÀO THẺ <a>
     * thật thì iOS mới chịu kích hoạt universal link (xem openVoucherLink trong Home.vue).
     *
     * Nếu thiếu dữ liệu để ghép thì rơi về permalink_url gốc của Graph API.
     */
    private function commentUrl(array $posted, string $postId): string
    {
        return FacebookPostTarget::parse($postId)->commentUrl($posted['comment_id'] ?? null)
            ?? $posted['permalink_url'];
    }
}
