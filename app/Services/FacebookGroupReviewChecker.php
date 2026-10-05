<?php

namespace App\Services;

use App\Models\FacebookGroup;
use App\Models\FacebookGroupPost;
use Illuminate\Database\Eloquent\Builder;
use Normalizer;

/**
 * Bài đã đăng có được admin nhóm duyệt không. Lúc bấm Đăng, bot chỉ đọc được thông báo Facebook
 * hiện ra (và có thể đọc sót); bài chờ duyệt sau đó được duyệt, bị từ chối hay bị gỡ thì không ai
 * biết. Nên lúc bot rảnh (đang nghỉ giữa hai bài, hết bài, đủ bài hôm nay — vẫn trong khung giờ),
 * server giao việc "kiểm tra nhóm": bot mở các tab "Nội dung của bạn" của nhóm, gửi về chữ của
 * từng bài thấy được; server dò đoạn đầu nội dung từng bài mình đăng để biết bài nằm ở tab nào.
 *
 * Mỗi bài được kiểm tra sau khi đăng ~1 giờ, bài còn chờ duyệt/không thấy thì 6 giờ kiểm tra lại,
 * bài đã lên thì kiểm tra thêm một lần sau một ngày (bị gỡ không), tối đa 3 ngày. Bị từ chối/gỡ
 * là chốt, không kiểm tra nữa.
 */
class FacebookGroupReviewChecker
{
    /** Bot từ bản này mới biết làm việc "review_group". */
    public const MIN_VERSION = '1.2.0';

    /**
     * Tab "Nội dung của bạn" của nhóm → trạng thái bài nằm ở đó. Đường dẫn nằm ở server để
     * Facebook đổi thì sửa ở đây là xong, không phải cập nhật bot (bot chỉ cho mở trang con của
     * đúng nhóm — xem tools/fb-group-poster/lib/review.mjs).
     */
    public const TABS = [
        FacebookGroupPost::REVIEW_PENDING => 'my_pending_content',
        FacebookGroupPost::REVIEW_PUBLISHED => 'my_posted_content',
        FacebookGroupPost::REVIEW_DECLINED => 'my_declined_content',
        FacebookGroupPost::REVIEW_REMOVED => 'my_removed_content',
    ];

    /** Bài lên các tab này thì không kiểm tra nữa. */
    public const FINAL = [FacebookGroupPost::REVIEW_DECLINED, FacebookGroupPost::REVIEW_REMOVED];

    /** Bài ở những trạng thái này có thể đã lên nhóm — đáng kiểm tra. */
    private const WATCHED = [FacebookGroupPost::POSTED, FacebookGroupPost::PENDING_APPROVAL, FacebookGroupPost::AMBIGUOUS];

    private const FIRST_CHECK_AFTER_MINUTES = 60;

    private const RECHECK_HOURS = 6;

    /** Bài đã lên: kiểm tra thêm một lần sau chừng này giờ kể từ lúc đăng, xem có bị gỡ không. */
    private const REMOVAL_CHECK_AFTER_HOURS = 24;

    private const WATCH_DAYS = 3;

    /** Một nhóm không bị kiểm tra dày hơn thế này (trừ khi admin bấm kiểm tra ngay). */
    private const GROUP_MIN_INTERVAL_HOURS = 3;

    /** Giữa hai lượt kiểm tra (bất kể nhóm nào) — để nick không lướt dồn dập. */
    private const GAP_MIN_MINUTES = 3;

    private const GAP_MAX_MINUTES = 8;

    /**
     * Nhận ra bài bằng đoạn đầu nội dung (chỉ tính chữ cái/chữ số): thử đoạn dài trước để hai deal
     * mở đầu giống nhau ("Deal hời hôm nay: Áo thun…") không lẫn nhau, rồi ngắn dần vì Facebook
     * cắt bài dài kèm "Xem thêm".
     */
    private const SNIPPET_LENGTHS = [80, 50, 30];

    /** Ưu tiên khi một đoạn chữ hiện ở nhiều tab. */
    private const MATCH_ORDER = [
        FacebookGroupPost::REVIEW_REMOVED,
        FacebookGroupPost::REVIEW_DECLINED,
        FacebookGroupPost::REVIEW_PUBLISHED,
        FacebookGroupPost::REVIEW_PENDING,
    ];

    public function __construct(private FacebookGroupRunnerSettings $settings) {}

    public static function runnerSupports(?string $version): bool
    {
        return FacebookGroupPostScheduler::runnerAtLeast($version, self::MIN_VERSION);
    }

    /**
     * Việc kiểm tra kế tiếp cho bot, hoặc null nếu chưa có nhóm nào tới lượt. Giao việc là ghi
     * last_checked_at luôn: bot chết giữa chừng thì sau GROUP_MIN_INTERVAL_HOURS mới giao lại.
     */
    public function nextJob(?string $runnerVersion): ?array
    {
        if (! self::runnerSupports($runnerVersion)) {
            return null;
        }
        $next = $this->settings->nextReviewAt();
        if ($next && now()->lt($next)) {
            return null;
        }

        $group = $this->nextGroup();
        if (! $group) {
            return null;
        }

        $group->forceFill(['last_checked_at' => now()])->save();
        $this->settings->setNextReviewAt(now()->addSeconds(random_int(self::GAP_MIN_MINUTES * 60, self::GAP_MAX_MINUTES * 60)));

        return [
            'type' => 'review_group',
            'group_id' => $group->id,
            'group_url' => $group->url,
            'group_name' => $group->name,
            'tabs' => collect(self::TABS)->map(fn (string $path) => rtrim($group->url, '/').'/'.$path.'/')->all(),
        ];
    }

    /**
     * Bot báo những gì thấy ở từng tab. Tab mở không được (ok=false) thì không kết luận gì từ nó;
     * chỉ khi cả tab "đang chờ" lẫn "đã đăng" mở được mà không thấy bài mới ghi "không thấy".
     *
     * @param  array<string, array{ok: bool, items?: list<array{text?: ?string, url?: ?string}>}>  $tabs
     * @return array{checked: int, found: int}
     */
    public function apply(FacebookGroup $group, array $tabs): array
    {
        $seen = [];
        foreach (self::MATCH_ORDER as $state) {
            if ($tabs[$state]['ok'] ?? false) {
                $seen[$state] = array_map(fn (array $item) => [
                    self::fingerprint((string) ($item['text'] ?? '')),
                    self::postUrl($item['url'] ?? null),
                ], $tabs[$state]['items'] ?? []);
            }
        }
        $canTellMissing = isset($seen[FacebookGroupPost::REVIEW_PENDING], $seen[FacebookGroupPost::REVIEW_PUBLISHED]);

        $posts = $this->watched()->where('facebook_group_id', $group->id)->get();
        $found = 0;
        foreach ($posts as $post) {
            [$state, $url] = $this->locate((string) $post->caption, $seen);
            $found += $state ? 1 : 0;
            $state ??= $canTellMissing ? FacebookGroupPost::REVIEW_MISSING : $post->review_state;

            $changes = ['review_state' => $state, 'reviewed_at' => now()];
            if ($url) {
                $changes['post_url'] = $url;
            }
            // "Không rõ đã đăng chưa" mà thấy bài trong nhóm là chắc chắn đã lên — chốt lại để
            // khỏi bị bấm đăng lại thành trùng.
            if ($post->status === FacebookGroupPost::AMBIGUOUS && $state !== null && $state !== FacebookGroupPost::REVIEW_MISSING) {
                $changes['status'] = $state === FacebookGroupPost::REVIEW_PENDING ? FacebookGroupPost::PENDING_APPROVAL : FacebookGroupPost::POSTED;
                $changes['error'] = null;
                if (! $group->last_posted_at || $group->last_posted_at->lt($post->finished_at)) {
                    $group->last_posted_at = $post->finished_at;
                }
            }
            $post->forceFill($changes)->save();
        }

        $group->forceFill(['last_checked_at' => now()])->save();

        return ['checked' => $posts->count(), 'found' => $found];
    }

    /** Chỉ chữ cái và chữ số, viết thường: Facebook bỏ emoji (ảnh), gộp dấu cách, thêm "Xem thêm". */
    public static function fingerprint(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }

        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text)) ?? '';
    }

    /** Link bài bot gửi về — chỉ nhận link bài trong nhóm trên facebook.com. */
    private static function postUrl(?string $url): ?string
    {
        return $url !== null && strlen($url) <= 500
            && preg_match('#^https://(www|m|web)\.facebook\.com/groups/[^/?\#\s]+/(posts|permalink)/\d+#', $url) === 1
            ? $url : null;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function locate(string $caption, array $seen): array
    {
        $fingerprint = self::fingerprint($caption);
        if (mb_strlen($fingerprint) < 10) {
            return [null, null];
        }

        $snippets = array_unique(array_map(fn (int $length) => mb_substr($fingerprint, 0, $length), self::SNIPPET_LENGTHS));
        foreach ($snippets as $snippet) {
            foreach ($seen as $state => $items) {
                foreach ($items as [$text, $url]) {
                    if (str_contains($text, $snippet)) {
                        return [$state, $url];
                    }
                }
            }
        }

        return [null, null];
    }

    private function nextGroup(): ?FacebookGroup
    {
        $posts = $this->watched()->get(['id', 'facebook_group_id', 'finished_at', 'review_state', 'reviewed_at']);
        if ($posts->isEmpty()) {
            return null;
        }

        $requestedAt = $this->settings->reviewRequestedAt();
        $minInterval = now()->subHours(self::GROUP_MIN_INTERVAL_HOURS);

        return FacebookGroup::whereKey($posts->pluck('facebook_group_id')->unique())
            ->get()
            ->filter(function (FacebookGroup $group) use ($posts, $requestedAt, $minInterval) {
                $checked = $group->last_checked_at;
                if ($requestedAt && (! $checked || $checked->lt($requestedAt))) {
                    return true;
                }

                return (! $checked || $checked->lte($minInterval))
                    && $posts->where('facebook_group_id', $group->id)->contains(fn (FacebookGroupPost $post) => $this->isDue($post));
            })
            ->sortBy(fn (FacebookGroup $group) => $group->last_checked_at?->getTimestamp() ?? 0)
            ->first();
    }

    private function isDue(FacebookGroupPost $post): bool
    {
        $now = now();
        if ($post->finished_at->gt($now->copy()->subMinutes(self::FIRST_CHECK_AFTER_MINUTES))) {
            return false;
        }
        if ($post->reviewed_at === null) {
            return true;
        }
        if ($post->review_state === FacebookGroupPost::REVIEW_PUBLISHED) {
            $dayAfter = $post->finished_at->copy()->addHours(self::REMOVAL_CHECK_AFTER_HOURS);

            return $now->gte($dayAfter) && $post->reviewed_at->lt($dayAfter);
        }

        return $post->reviewed_at->lte($now->copy()->subHours(self::RECHECK_HOURS));
    }

    /** Bài đáng kiểm tra: có thể đã lên nhóm, trong WATCH_DAYS ngày, chưa chốt từ chối/gỡ. */
    private function watched(): Builder
    {
        return FacebookGroupPost::whereIn('status', self::WATCHED)
            ->whereNotNull('caption')
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', now()->subDays(self::WATCH_DAYS))
            ->where(fn ($query) => $query->whereNull('review_state')->orWhereNotIn('review_state', self::FINAL));
    }
}
