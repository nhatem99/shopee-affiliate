<?php

namespace App\Services;

use App\Exceptions\AffiliateScanException;
use App\Exceptions\BridgeDownloadFailedException;
use App\Exceptions\BridgeUrlsNotSupportedException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Tính năng "đăng lại bài" (mirror): lấy bài từ nhóm Zalo của đối thủ (nhóm nguồn), đổi tất cả
 * link Shopee sang link affiliate của mình, rồi đăng lên nhóm của mình (nhóm đích).
 *
 * Bài gốc thường là một loạt ảnh sản phẩm kèm nội dung văn bản và 5–10 link Shopee rút gọn
 * (s.shopee.vn/...). Quy trình:
 *   1. Gom các mảnh (ảnh, text, link card) do cùng người gửi → một bài hoàn chỉnh.
 *   2. Chuẩn hoá bare URL (s.shopee.vn, zalo.me... không có scheme) → https://...
 *   3. Lọc dòng chứa link không phải Shopee (zalo.me/g/, t.me, facebook...).
 *   4. Đổi TOÀN BỘ link Shopee sang link của mình — nếu có link nào không đổi được thì BỎ HẲN
 *      bài đó, không đăng link mang mã affiliate của người khác.
 *   5. Chống trùng bài giống nhau từ nhóm chị em cross-post cùng deal.
 *   6. Giới hạn tốc độ đăng theo giờ.
 *   7. Ghi lịch sử 30 kết quả cuối vào cache để admin xem ở /admin/zalo-nick.
 */
class ZaloMirrorService
{
    private const URL_PATTERN = '#https?://[^\s<>"\']+#iu';

    // Cache key giữ 30 kết quả gần nhất để hiện ở trang admin.
    private const ACTIVITY_KEY = 'zalo_mirror:recent_activity';

    private const ACTIVITY_LIMIT = 30;

    // Admin nhóm nguồn cache 1 giờ — hỏi bridge mỗi lần đăng là quá chậm.
    private const GROUP_ADMINS_TTL = 3600;

    // Khi text sau khi bỏ URL quá ngắn, gộp thêm URL gốc vào hash — tránh coi mọi bài
    // "Áo đẹp!" + vài link là bài cùng nội dung dù link khác nhau hoàn toàn.
    private const SHORT_TEXT_THRESHOLD = 20;

    // Thời gian giữ dedupe mark trong khi đang đăng (5 phút). Nếu job bị kill (timeout
    // process) trước khi kịp extend sang dedupe_hours, mark tự hết hạn và retry sẽ thử lại.
    private const DEDUPE_PENDING_MINUTES = 5;

    public function __construct(
        private ZaloMirrorSettings $settings,
        private ZaloPersonalBridge $bridge,
        private AffiliateLinkRewriterService $rewriter,
        private ShortLinkService $shortLinks,
        private UrlValidationService $urlValidator,
    ) {}

    // ── Phân tích tin ────────────────────────────────────────────────────────

    /**
     * Trích xuất "mảnh" hữu ích từ một tin nhắn đã chuẩn hoá của cầu nối. Trả null nếu tin
     * không mang nội dung đáng đăng lại (sticker, voice, recalled...).
     *
     * @param  array<string, mixed>  $message  tin chuẩn hoá của zaloClient.js::_normaliseMessage
     * @return array{type: string, text: string, imageUrl: ?string, ts: int, senderId: string, senderName: string}|null
     */
    public function extractPiece(array $message): ?array
    {
        $msgType = (string) ($message['msgType'] ?? 'webchat');
        $text = (string) ($message['text'] ?? '');
        $attachment = $message['attachment'] ?? null;

        // Giờ gửi thật của tin (mili giây, do Zalo đóng dấu) chứ không phải lúc listener nhận:
        // cầu nối phát lại cả loạt tin bị lỡ trong một giây khi nối lại, AssembleZaloMirrorPost
        // dựa vào khoảng cách giữa các ts này để tách bài.
        $sentAt = (int) ($message['ts'] ?? 0);

        $base = [
            'ts' => $sentAt > 0 ? $sentAt : (int) (microtime(true) * 1000),
            'senderId' => (string) ($message['senderId'] ?? ''),
            'senderName' => (string) ($message['senderName'] ?? ''),
        ];

        // Ảnh — zca-js đặt URL ảnh HD vào media.url hoặc attachment.href (tùy phiên bản).
        if ($msgType === 'chat.photo') {
            $imageUrl = (string) (data_get($message, 'media.url')
                ?? data_get($message, 'attachment.href')
                ?? '');

            if ($imageUrl !== '') {
                return $base + ['type' => 'photo', 'text' => $text, 'imageUrl' => $imageUrl];
            }
        }

        // Loại bỏ sớm các tin không chứa nội dung đáng đăng lại.
        // chat.voice/chat.file/chat.video/chat.location: bridge gán attachment = {href: '', ...}
        // cho mọi message có content dạng object — nếu không lọc sớm ở đây, chúng sẽ bị phân
        // loại nhầm thành link card (isset($attachment['href']) = true kể cả khi href = '').
        if (in_array($msgType, [
            'chat.sticker', 'chat.voice', 'chat.file', 'share.file',
            'chat.video', 'chat.video.msg', 'chat.gif',
            'chat.location', 'chat.location.new', 'chat.recalled',
        ], true)) {
            return null;
        }

        // Link card — bridge gắn nhãn 'chat.recommended'; đôi khi mislabel thành 'contact' và
        // đặt text = "[contact: ...]". Chỉ xử lý hai trường hợp này — KHÔNG dùng
        // isset($attachment['href']) vì bridge đặt href = '' cho mọi message có attachment object
        // (voice, file, video...) và sẽ bị nhầm thành link card.
        $isLinkCard = $msgType === 'chat.recommended'
            || str_starts_with($text, '[contact:');

        if ($isLinkCard && is_array($attachment)) {
            $title = (string) ($attachment['title'] ?? '');
            $href = (string) ($attachment['href'] ?? '');
            $combined = trim($title);
            if ($href !== '' && ! str_contains($combined, $href)) {
                $combined = $combined !== '' ? $combined."\n".$href : $href;
            }
            if ($combined !== '') {
                return $base + ['type' => 'link', 'text' => $combined, 'imageUrl' => null];
            }
        }

        // Text thường (webchat) — bỏ sticker, voice, file...
        if ($msgType === 'webchat' && trim($text) !== '') {
            return $base + ['type' => 'text', 'text' => $text, 'imageUrl' => null];
        }

        // Fallback: văn bản không rõ loại mà có nội dung thì vẫn thu thập.
        if (! in_array($msgType, ['chat.photo', 'chat.recommended'], true)
            && trim($text) !== '') {
            return $base + ['type' => 'text', 'text' => $text, 'imageUrl' => null];
        }

        return null;
    }

    // ── Dựng bài đăng ────────────────────────────────────────────────────────

    /**
     * Gom các mảnh thành nội dung bài đăng hoàn chỉnh:
     *   • text = tất cả văn bản và caption ảnh, sắp xếp theo ts, loại bỏ chuỗi trùng lặp
     *   • imageUrls = URL ảnh theo ts, cắt tại max_images
     *   • shopeeUrls = danh sách link Shopee duy nhất tìm thấy trong text (sau khi lọc)
     *
     * @param  list<array>  $pieces
     * @return array{text: string, imageUrls: list<string>, rawShopeeUrls: list<string>}
     */
    public function buildPostContent(array $pieces): array
    {
        // Sắp xếp theo thời điểm nhận.
        usort($pieces, fn ($a, $b) => $a['ts'] <=> $b['ts']);

        $maxImages = (int) config('services.zalo_personal.mirror.max_images', 10);

        // Gom text + ảnh.
        $textParts = [];
        $imageUrls = [];
        foreach ($pieces as $piece) {
            $pieceText = trim((string) ($piece['text'] ?? ''));
            if ($pieceText !== '' && ! in_array($pieceText, $textParts, true)) {
                $textParts[] = $pieceText;
            }
            $imageUrl = (string) ($piece['imageUrl'] ?? '');
            if ($imageUrl !== '' && ! in_array($imageUrl, $imageUrls, true)) {
                $imageUrls[] = $imageUrl;
            }
        }

        if (count($imageUrls) > $maxImages) {
            $dropped = array_slice($imageUrls, $maxImages);
            Log::info('ZaloMirrorService: cắt ảnh thừa', [
                'kept' => $maxImages,
                'dropped' => count($dropped),
                'dropped_urls' => $dropped,
            ]);
            $imageUrls = array_slice($imageUrls, 0, $maxImages);
        }

        // Chuẩn hoá bare URL trước khi lọc: s.shopee.vn/... → https://s.shopee.vn/...
        // Zalo render các bare domain thành clickable link, nên phải xử lý như URL thực sự.
        $rawText = $this->normalizeBareUrls(implode("\n", $textParts));

        // Ghép và lọc dòng.
        $filteredText = $this->filterNonShopeeLines($rawText);

        // Thu thập link Shopee trong text đã lọc.
        $shopeeUrls = $this->extractShopeeUrls($filteredText);

        return [
            'text' => $filteredText,
            'imageUrls' => array_values($imageUrls),
            'rawShopeeUrls' => array_values(array_unique($shopeeUrls)),
        ];
    }

    /**
     * Thêm https:// vào các tên miền quen thuộc không có scheme — Zalo tự tạo link clickable
     * cho chúng. Nếu không chuẩn hoá, link affiliate của đối thủ (s.shopee.vn/xyz) hoặc
     * link mời nhóm (zalo.me/g/abc) sẽ lọt qua bộ lọc và được đăng lại nguyên vẹn.
     *
     * Lookbehind (?<![:/\w]) loại trừ các match nằm bên trong URL đã có scheme
     * (chữ trước hostname trong https://s.shopee.vn là '/', nằm trong tập [:/\w]).
     */
    public function normalizeBareUrls(string $text): string
    {
        $knownDomains = implode('|', [
            '(?:s\.)?shopee\.vn', 'shp\.ee', 'shope\.ee',
            'zalo\.me', 't\.me',
            'm\.fb\.com', 'facebook\.com', 'fb\.com',
            'tiktok\.com',
        ]);

        // Lookbehind (?<![.:/\w]) loại trừ cả dấu '.' để tránh match 'shopee.vn' bên trong
        // 'https://s.shopee.vn/...' (chữ trước 'shopee' là '.' — nằm trong tập loại trừ).
        return preg_replace(
            '#(?<![.:/\w])(?:'.$knownDomains.')/[^\s<>"\']+#iu',
            'https://$0',
            $text
        ) ?? $text;
    }

    /**
     * Xoá các dòng có chứa URL không phải Shopee (link mời nhóm Zalo, TikTok, Facebook, t.me...).
     * Dòng không có URL nào thì giữ lại. Dòng chỉ có link Shopee thì giữ.
     */
    public function filterNonShopeeLines(string $text): string
    {
        $lines = explode("\n", $text);
        $kept = [];
        foreach ($lines as $line) {
            preg_match_all(self::URL_PATTERN, $line, $m);
            $urls = $m[0];
            if (empty($urls)) {
                $kept[] = $line;

                continue;
            }
            $hasNonShopee = false;
            foreach ($urls as $url) {
                $url = self::trimUrl($url);
                try {
                    $this->urlValidator->validateShopeeOnly($url);
                } catch (AffiliateScanException) {
                    $hasNonShopee = true;
                    break;
                }
            }
            if (! $hasNonShopee) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    /**
     * @return list<string>
     */
    private function extractShopeeUrls(string $text): array
    {
        preg_match_all(self::URL_PATTERN, $text, $m);
        $shopee = [];
        foreach ($m[0] as $url) {
            $url = self::trimUrl($url);
            try {
                $this->urlValidator->validateShopeeOnly($url);
                if (! in_array($url, $shopee, true)) {
                    $shopee[] = $url;
                }
            } catch (AffiliateScanException) {
                // không phải Shopee
            }
        }

        return $shopee;
    }

    /**
     * Bỏ dấu câu và ký tự Unicode không phải URL ở cuối (emoji phổ biến, dấu chỉ tay...).
     * Các ký tự này thường bị gắn vào link khi copy-paste từ Zalo.
     */
    private static function trimUrl(string $url): string
    {
        $url = rtrim($url, '.,;:!?)]}');

        // Bỏ thêm ký tự Unicode không thuộc ASCII printable ở cuối (emoji, ký hiệu...).
        return preg_replace('/[^\x20-\x7E]+$/u', '', $url) ?? $url;
    }

    /**
     * Đổi các link Shopee trong text sang /go/{code} của mình. Trả null nếu có bất kỳ link nào
     * không đổi được — toàn bài phải bị bỏ, không đăng một nửa link mang mã người khác.
     *
     * Dùng strtr thay cho str_replace: strtr thay từ trái qua phải, tại mỗi vị trí chọn key dài
     * nhất khớp trước. Điều này tránh trường hợp URL A là prefix của URL B: thay A trước làm
     * hỏng B. strtr xử lý đúng vì nó không duyệt key theo thứ tự mà chọn match dài nhất.
     *
     * @param  list<string>  $shopeeUrls
     * @return array{text: string, count: int}|null null = có link không đổi được
     */
    public function convertLinks(string $text, array $shopeeUrls): ?array
    {
        $ownMmpPid = config('services.shopee_affiliate.mmp_pid');
        $replacements = [];

        foreach ($shopeeUrls as $original) {
            $rewritten = $this->rewriter->rewriteToOwnAffiliate($original);

            // Kiểm tra đã đổi thật sự chưa: host phải là shopee.vn và mmp_pid phải là của mình.
            $parts = parse_url($rewritten);
            if (($parts['host'] ?? '') !== 'shopee.vn') {
                Log::warning('ZaloMirrorService: link Shopee không đổi được — bỏ cả bài', [
                    'original' => $original,
                    'rewritten' => $rewritten,
                ]);

                return null;
            }
            parse_str($parts['query'] ?? '', $q);
            if (($q['mmp_pid'] ?? '') !== $ownMmpPid) {
                Log::warning('ZaloMirrorService: link Shopee đổi được nhưng mmp_pid không khớp — bỏ cả bài', [
                    'original' => $original,
                    'rewritten' => $rewritten,
                    'got_pid' => $q['mmp_pid'] ?? '',
                    'want_pid' => $ownMmpPid,
                ]);

                return null;
            }

            $shortLink = $this->shortLinks->create($rewritten, 'zalo_mirror');
            $replacements[$original] = url('/go/'.$shortLink->code);
        }

        // strtr thay trong một lần quét, tại mỗi vị trí chọn key dài nhất — tránh hỏng URL
        // dài khi có URL ngắn hơn là prefix của nó trong cùng bài.
        return ['text' => strtr($text, $replacements), 'count' => count($replacements)];
    }

    // ── Đăng bài ─────────────────────────────────────────────────────────────

    /**
     * Pipeline đầy đủ: kiểm tra điều kiện → chuyển đổi link → đăng → ghi lịch sử.
     * Ném \RuntimeException khi gặp lỗi hạ tầng (bridge chết, ...) để job tự retry.
     *
     * @param  list<array>  $pieces
     */
    public function publish(
        array $pieces,
        string $targetGroupId,
        string $sourceGroupId,
        string $senderId,
        string $senderName,
    ): void {
        // Đọc lại settings mới nhất (job có thể delay 20 giây, admin kịp tắt).
        if (! $this->settings->isActive()) {
            Log::info('ZaloMirrorService: mirror không còn active, bỏ qua', compact('sourceGroupId'));

            return;
        }

        // Xác nhận lại target và source — settings có thể đã thay đổi trong thời gian buffer.
        $latestTarget = $this->settings->targetGroupId();
        if ($latestTarget !== $targetGroupId) {
            Log::info('ZaloMirrorService: nhóm đích đã thay đổi, bỏ qua bài', compact('sourceGroupId', 'targetGroupId', 'latestTarget'));

            return;
        }
        if (! $this->settings->isSourceGroup($sourceGroupId)) {
            Log::info('ZaloMirrorService: nhóm nguồn đã bị xóa khỏi danh sách, bỏ qua bài', compact('sourceGroupId'));

            return;
        }

        // ── 1. adminsOnly ─────────────────────────────────────────────────────
        if ($this->settings->adminsOnly()) {
            $admins = $this->fetchGroupAdmins($sourceGroupId);
            if ($admins === null) {
                $this->recordActivity([
                    'sourceGroupId' => $sourceGroupId,
                    'senderName' => $senderName,
                    'status' => 'skipped_not_admin',
                    'note' => 'không lấy được danh sách admin nhóm nguồn',
                    'textPreview' => '',
                    'targetLinksCount' => 0,
                ]);
                Log::warning('ZaloMirrorService: bỏ qua bài — không lấy được admin nhóm nguồn', compact('sourceGroupId'));

                return;
            }
            if (! in_array($senderId, $admins, true)) {
                $this->recordActivity([
                    'sourceGroupId' => $sourceGroupId,
                    'senderName' => $senderName,
                    'status' => 'skipped_not_admin',
                    'note' => 'người gửi không phải admin nhóm nguồn',
                    'textPreview' => '',
                    'targetLinksCount' => 0,
                ]);
                Log::info('ZaloMirrorService: bỏ qua bài — người gửi không phải admin', compact('senderId', 'sourceGroupId'));

                return;
            }
        }

        // ── 2. Dựng nội dung bài ─────────────────────────────────────────────
        $post = $this->buildPostContent($pieces);
        $textPreview = mb_substr($post['text'], 0, 100);

        if (empty($post['rawShopeeUrls'])) {
            $this->recordActivity([
                'sourceGroupId' => $sourceGroupId,
                'senderName' => $senderName,
                'status' => 'skipped_no_shopee',
                'note' => 'không có link Shopee trong bài',
                'textPreview' => $textPreview,
                'targetLinksCount' => 0,
            ]);
            Log::info('ZaloMirrorService: bỏ qua bài — không có link Shopee', compact('sourceGroupId'));

            return;
        }

        // ── 3. Chống trùng bài ───────────────────────────────────────────────
        $dedupeHash = $this->postDedupeHash($post['text'], $post['rawShopeeUrls']);
        $dedupeKey = 'zalo_mirror:post:'.$dedupeHash;
        $dedupeHours = (int) config('services.zalo_personal.mirror.dedupe_hours', 24);

        // Dùng TTL ngắn (DEDUPE_PENDING_MINUTES) ban đầu: nếu job bị kill bởi process timeout
        // (không qua catch), mark tự hết hạn và retry/nhóm chị em có thể thử lại. Sau khi
        // gửi thành công mới extend sang dedupe_hours đầy đủ.
        if (! Cache::add($dedupeKey, 'pending', now()->addMinutes(self::DEDUPE_PENDING_MINUTES))) {
            $this->recordActivity([
                'sourceGroupId' => $sourceGroupId,
                'senderName' => $senderName,
                'status' => 'skipped_duplicate',
                'note' => 'bài đã đăng (nhóm chị em cross-post cùng deal)',
                'textPreview' => $textPreview,
                'targetLinksCount' => 0,
            ]);
            Log::info('ZaloMirrorService: bỏ qua bài trùng', compact('dedupeHash', 'sourceGroupId'));

            return;
        }

        // Nếu bỏ qua vì rate limit hoặc link không đổi được, xoá mark ngay để retry/nhóm chị em
        // còn cơ hội. Nếu gửi thành công, extend sang dedupe_hours.
        $dedupeMarkSet = true;

        try {
            // ── 4. Giới hạn tốc độ ───────────────────────────────────────────
            // Kiểm tra trước khi chuyển đổi link — không tiêu quota cho bài thất bại.
            // Chỉ gọi hit() SAU khi gửi thành công.
            $postsPerHour = $this->settings->postsPerHour();
            $rateLimitKey = "zalo_mirror:rate:{$targetGroupId}";

            if (RateLimiter::tooManyAttempts($rateLimitKey, $postsPerHour)) {
                Cache::forget($dedupeKey);
                $dedupeMarkSet = false;
                $this->recordActivity([
                    'sourceGroupId' => $sourceGroupId,
                    'senderName' => $senderName,
                    'status' => 'skipped_rate_limit',
                    'note' => "vượt {$postsPerHour} bài/giờ",
                    'textPreview' => $textPreview,
                    'targetLinksCount' => 0,
                ]);
                Log::info('ZaloMirrorService: bỏ qua bài — đạt giới hạn đăng', compact('targetGroupId', 'postsPerHour'));

                return;
            }

            // ── 5. Chuyển đổi link ───────────────────────────────────────────
            $converted = $this->convertLinks($post['text'], $post['rawShopeeUrls']);
            if ($converted === null) {
                Cache::forget($dedupeKey);
                $dedupeMarkSet = false;
                $this->recordActivity([
                    'sourceGroupId' => $sourceGroupId,
                    'senderName' => $senderName,
                    'status' => 'skipped_unconvertible',
                    'note' => 'có link Shopee không đổi được mmp_pid',
                    'textPreview' => $textPreview,
                    'targetLinksCount' => 0,
                ]);

                return;
            }

            $finalText = $converted['text'];
            $linkCount = $converted['count'];
            $imageUrls = $post['imageUrls'];

            // ── 6. Gửi ───────────────────────────────────────────────────────
            $sendNote = $this->sendPost($targetGroupId, $finalText, $imageUrls, $dedupeHash);

            // Gửi thành công — tiêu một slot rate limit và commit dedupe mark đầy đủ.
            RateLimiter::hit($rateLimitKey, 3600);
            Cache::put($dedupeKey, 'sent', now()->addHours($dedupeHours));
            $dedupeMarkSet = false;

            $activityNote = "{$linkCount} link đã đổi";
            if ($sendNote !== null) {
                $activityNote .= ' — '.$sendNote;
            }

            $this->recordActivity([
                'sourceGroupId' => $sourceGroupId,
                'senderName' => $senderName,
                'status' => 'posted',
                'note' => $activityNote,
                'textPreview' => $textPreview,
                'targetLinksCount' => $linkCount,
            ]);
            Log::info('ZaloMirrorService: đăng thành công', [
                'source' => $sourceGroupId,
                'target' => $targetGroupId,
                'links' => $linkCount,
                'images' => count($imageUrls),
            ]);

        } catch (Throwable $e) {
            if ($dedupeMarkSet) {
                Cache::forget($dedupeKey);
            }
            $this->recordActivity([
                'sourceGroupId' => $sourceGroupId,
                'senderName' => $senderName,
                'status' => 'failed',
                'note' => $e->getMessage(),
                'textPreview' => $textPreview,
                'targetLinksCount' => 0,
            ]);
            Log::warning('ZaloMirrorService: đăng thất bại', [
                'source' => $sourceGroupId,
                'target' => $targetGroupId,
                'error' => $e->getMessage(),
            ]);

            throw $e; // để job tự retry
        }
    }

    /**
     * Gửi bài: ảnh + text, hoặc chỉ text nếu không có ảnh. Xử lý fallback khi cầu nối cũ chưa
     * hỗ trợ gửi ảnh qua URL, hoặc khi ảnh không tải được (422). Tránh gửi lại album khi retry
     * chỉ vì bước gửi text bị lỗi.
     *
     * Trả về note mô tả vấn đề nếu có (để ghi vào activity), null nếu gửi bình thường.
     *
     * @param  list<string>  $imageUrls
     * @param  string  $postId  hash bài để làm albumKey duy nhất — tránh hai bài dùng cùng ảnh CDN
     *                          (từ nhóm chị em forward) làm lẫn albumKey với nhau
     */
    private function sendPost(string $targetGroupId, string $text, array $imageUrls, string $postId): ?string
    {
        // ltrim: filterNonShopeeLines có thể bỏ dòng đầu chứa link, để lại '\n' ở đầu text.
        // boldFirstLine dùng strtok bỏ qua '\n' leading khi tính $firstLine nhưng start = 0,
        // nên bold range bị lệch — ký tự 0 là '\n', headline bị in đậm thiếu ký tự cuối.
        $msg = ZaloGroupLinkReplyService::boldFirstLine(ltrim($text));

        if (empty($imageUrls)) {
            $this->bridge->sendText($targetGroupId, 'group', $msg['text'], null, $msg['styles']);

            return null;
        }

        // Key chống gửi lại album khi job retry chỉ vì step tiếp theo lỗi.
        // Gộp $postId (hash bài) vào key: hai bài khác nội dung dùng cùng ảnh CDN (forward từ
        // nhóm chị em) không được share albumKey với nhau.
        $albumKey = 'zalo_mirror:album_sent:'.sha1($postId.'|'.implode('|', $imageUrls).'|'.$targetGroupId);
        $albumAlreadySent = Cache::has($albumKey);

        if (! $albumAlreadySent) {
            // Đặt albumKey TRƯỚC khi gọi bridge: nếu bridge hết giờ (timeout 120 giây trên điện
            // thoại qua 4G) và job bị retry, retry sẽ không gửi lại album vì albumKey đã tồn tại.
            // Nếu bridge hoàn toàn từ chối (400 paths / 422 download fail), ta xóa key dưới.
            Cache::put($albumKey, true, now()->addMinutes(5));

            try {
                if (count($imageUrls) === 1) {
                    // Một ảnh: gửi kèm caption luôn trong một bubble.
                    $this->bridge->sendImages($targetGroupId, 'group', $imageUrls, $msg['text']);

                    return null; // caption đã kèm — không cần gửi text riêng
                }
                // Nhiều ảnh: album không có caption → text riêng theo sau.
                $this->bridge->sendImages($targetGroupId, 'group', $imageUrls);
            } catch (BridgeUrlsNotSupportedException $e) {
                // Cầu nối cũ (chưa patch) — xóa albumKey vì không gửi ảnh.
                Cache::forget($albumKey);
                Log::warning('ZaloMirrorService: bridge chưa hỗ trợ ảnh — chỉ gửi text', [
                    'target' => $targetGroupId,
                    'images' => count($imageUrls),
                ]);
                $this->bridge->sendText($targetGroupId, 'group', $msg['text'], null, $msg['styles']);

                return 'bridge chưa hỗ trợ ảnh';
            } catch (BridgeDownloadFailedException $e) {
                // 422 — URL ảnh xác định không tải được (404, quá lớn...) — xóa albumKey, fallback.
                Cache::forget($albumKey);
                Log::warning('ZaloMirrorService: bridge không tải được ảnh (422) — chỉ gửi text', [
                    'target' => $targetGroupId,
                    'images' => count($imageUrls),
                    'error' => $e->getMessage(),
                ]);
                $this->bridge->sendText($targetGroupId, 'group', $msg['text'], null, $msg['styles']);

                return 'ảnh tải lỗi — chỉ đăng chữ';
            }
        }

        // Album đã gửi (lần này hoặc lần retry trước) → gửi text kèm theo.
        $this->bridge->sendText($targetGroupId, 'group', $msg['text'], null, $msg['styles']);

        return null;
    }

    // ── Trợ lý ───────────────────────────────────────────────────────────────

    /**
     * Hash để nhận ra bài đăng cùng nội dung từ nhóm chị em cross-post.
     * Chuẩn hoá: bỏ URL (sẽ khác sau khi đổi sang /go/), viết thường, gộp khoảng trắng.
     * Nếu text còn lại quá ngắn, gộp thêm URL gốc vào hash để tránh khớp nhầm.
     */
    private function postDedupeHash(string $text, array $shopeeUrls): string
    {
        $normalized = preg_replace(self::URL_PATTERN, '', $text) ?? $text;
        $normalized = mb_strtolower(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
        $normalized = trim($normalized);

        if (mb_strlen($normalized) < self::SHORT_TEXT_THRESHOLD) {
            $normalized .= '|'.implode('|', $shopeeUrls);
        }

        return sha1($normalized);
    }

    /**
     * @return list<string>|null null = không lấy được (bridge lỗi)
     */
    private function fetchGroupAdmins(string $groupId): ?array
    {
        $cacheKey = "zalo_mirror:admins:{$groupId}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return json_decode($cached, true) ?: [];
        }

        try {
            $admins = $this->bridge->groupAdmins($groupId);

            // Không cache danh sách rỗng — groupAdmins() ném lỗi khi nhóm không có creatorId,
            // nhưng nếu mã thay đổi sau này, ta không muốn lưu [] vào cache 1 giờ.
            if (! empty($admins)) {
                Cache::put($cacheKey, json_encode($admins), self::GROUP_ADMINS_TTL);
            }

            return $admins;
        } catch (Throwable $e) {
            Log::warning("ZaloMirrorService: không lấy được admin nhóm {$groupId}", ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function recordActivity(array $entry): void
    {
        $activities = json_decode(Cache::get(self::ACTIVITY_KEY, '[]'), true);
        if (! is_array($activities)) {
            $activities = [];
        }

        array_unshift($activities, array_merge($entry, [
            'time' => now()->toIso8601String(),
        ]));

        Cache::put(self::ACTIVITY_KEY, json_encode(array_slice($activities, 0, self::ACTIVITY_LIMIT)), now()->addDays(7));
    }

    /** @return list<array> */
    public function recentActivity(): array
    {
        return json_decode(Cache::get(self::ACTIVITY_KEY, '[]'), true) ?: [];
    }
}
