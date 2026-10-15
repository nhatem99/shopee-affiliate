<?php

namespace App\Services;

use App\Models\FacebookEngageComment;
use App\Models\FacebookGroup;
use App\Models\FacebookProfile;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Bình luận dạo trong các nhóm follow chéo. Giao bot việc "engage": chuyển sang page admin chọn,
 * mở bảng tin một nhóm (bài mới trước), chọn bài đầu tiên chưa bình luận và không phải của mình,
 * bình luận một câu bốc ngẫu nhiên từ câu mẫu.
 *
 * Nhịp riêng, không dính nhịp đăng deal: khung giờ, trần mỗi ngày, nghỉ ngẫu nhiên giữa hai bình
 * luận, mỗi nhóm cách nhau tối thiểu bao lâu (nhóm lâu chưa ghé nhất đi trước). Khoảng nghỉ tính
 * ngay lúc giao việc — bot chết giữa chừng cũng không bình luận dồn.
 *
 * Bài đã bình luận nhớ theo số bài (post_key) để không bình luận lại; bot còn tự bỏ bài có uid
 * page/nick của mình (bài mình đăng, hoặc mình đã bình luận từ trước).
 */
class FacebookEngageQueue
{
    /** Bot từ bản này mới biết làm việc "engage". */
    public const MIN_VERSION = '1.5.0';

    /** Bot nhận việc mà quá chừng này không báo kết quả thì coi như không rõ. */
    public const STALE_MINUTES = 15;

    /** Chưa bình luận gì (không có bài mới, trang không tải...) thì chỉ nghỉ ngắn. */
    private const AFTER_NOTHING_MINUTES = 2;

    /** Nhớ bài đã bình luận trong chừng này ngày — bài cũ hơn đã trôi khỏi đầu bảng tin. */
    private const REMEMBER_DAYS = 30;

    private const SKIP_LIMIT = 500;

    private const LOCK_KEY = 'fb-runner:engage';

    private const MAX_RETRY_AFTER = 1800;

    public function __construct(
        private FacebookEngageSettings $settings,
        private FacebookDealCaption $captions,
        private ZaloAdminNotifier $notifier,
    ) {}

    public static function runnerSupports(?string $version): bool
    {
        return FacebookGroupPostScheduler::runnerAtLeast($version, self::MIN_VERSION);
    }

    /** Page admin chọn để bình luận — null nếu chưa chọn hoặc page đã bị xoá. */
    public function profile(): ?FacebookProfile
    {
        $id = $this->settings->config()['profile_id'];

        return $id ? FacebookProfile::find($id) : null;
    }

    /**
     * Vì sao lúc này chưa bình luận — null là được. Trang admin dùng lại để giải thích.
     *
     * @return array{reason: string, retry_after: int, until: ?string}|null
     */
    public function blockingReason(?string $runnerVersion): ?array
    {
        $config = $this->settings->config();
        if (! $config['enabled']) {
            return $this->blocked('disabled');
        }
        if (! self::runnerSupports($runnerVersion)) {
            return $this->blocked('old_runner');
        }
        $profile = $this->profile();
        if (! $profile) {
            return $this->blocked('no_profile');
        }
        if ($profile->blocked_at !== null) {
            return $this->blocked('profile_blocked');
        }
        if (! $this->settings->templates()) {
            return $this->blocked('no_templates');
        }

        $now = now();
        $start = $now->copy()->setTimeFromTimeString($config['window_start']);
        $end = $now->copy()->setTimeFromTimeString($config['window_end']);
        if ($now->lt($start)) {
            return $this->blockedUntil('outside_window', $start);
        }
        if ($now->gte($end)) {
            return $this->blockedUntil('outside_window', $start->copy()->addDay());
        }
        if ($this->countToday() >= $config['max_per_day']) {
            return $this->blockedUntil('daily_cap', $start->copy()->addDay());
        }

        $next = $this->settings->nextAt();
        if ($next && $now->lt($next)) {
            return $this->blockedUntil('gap', $next);
        }

        if (! $this->groups($profile)->exists()) {
            return $this->blocked('no_group');
        }
        if (! $this->nextGroup($profile)) {
            $earliest = $this->groups($profile)->min('last_engaged_at');

            return $this->blockedUntil('group_gap', Carbon::parse($earliest)->addMinutes($config['group_gap_minutes']));
        }

        return null;
    }

    /**
     * Việc bình luận kế tiếp, hoặc null. Giao việc là ghi một dòng "claimed" (tính vào trần) và đặt
     * luôn khoảng nghỉ tới lượt sau.
     */
    public function nextJob(?string $runnerVersion): ?array
    {
        $this->releaseStale();
        if ($this->blockingReason($runnerVersion)) {
            return null;
        }

        $lock = Cache::lock(self::LOCK_KEY, 30);
        if (! $lock->get()) {
            return null;
        }

        try {
            $profile = $this->profile();
            $group = $this->nextGroup($profile);
            // Hai lượt hỏi cùng lúc: lượt sau thấy khoảng nghỉ lượt trước vừa đặt.
            $next = $this->settings->nextAt();
            if (! $group || ($next && now()->lt($next))) {
                return null;
            }

            $templates = $this->settings->templates();
            $comment = $this->captions->render($templates[random_int(0, count($templates) - 1)], '');

            $row = FacebookEngageComment::create([
                'facebook_group_id' => $group->id,
                'facebook_profile_id' => $profile->id,
                'status' => FacebookEngageComment::CLAIMED,
                'comment' => $comment,
                'claimed_at' => now(),
            ]);
            $group->forceFill(['last_engaged_at' => now()])->save();
            $this->waitRandomGap();
        } finally {
            $lock->release();
        }

        return [
            'type' => 'engage',
            'engage_id' => $row->id,
            'group_url' => $group->url,
            'group_name' => $group->name,
            // Bình luận dưới tên page này — bot chuyển sang trước khi mở nhóm.
            'profile' => $profile->forRunner(),
            'comment' => $comment,
            // Số bài đã bình luận ở nhóm này — bot bỏ qua.
            'skip_posts' => $group->engageComments()
                ->whereNotNull('post_key')
                ->where('claimed_at', '>=', now()->subDays(self::REMEMBER_DAYS))
                ->latest('id')
                ->limit(self::SKIP_LIMIT)
                ->pluck('post_key')
                ->unique()
                ->values()
                ->all(),
            // uid nick và các page của mình: bài của mình, hoặc bài mình đã bình luận, thì bỏ qua.
            'own_ids' => FacebookProfile::whereNotNull('fb_id')->pluck('fb_id')->all(),
        ];
    }

    /**
     * Còn bao lâu nữa tới lượt bình luận — để bot đang rảnh (không có bài đăng) hỏi lại đúng lúc.
     * null nếu chưa tới lượt được vì lý do khác thời gian (tắt, chưa chọn page...).
     */
    public function retryAfter(?string $runnerVersion): ?int
    {
        $blocking = $this->blockingReason($runnerVersion);
        if ($blocking === null) {
            return 15;
        }

        return $blocking['until'] !== null ? $blocking['retry_after'] : null;
    }

    /**
     * Bot báo kết quả. false = lượt này đã chốt (báo lần hai) — controller trả 409. Báo muộn sau khi
     * đã bị coi là "không rõ" thì vẫn nhận.
     */
    public function report(FacebookEngageComment $row, string $status, ?string $comment, ?string $postUrl, ?string $author, ?string $error): bool
    {
        if (! in_array($row->status, [FacebookEngageComment::CLAIMED, FacebookEngageComment::UNKNOWN], true)) {
            return false;
        }

        $postUrl = FacebookGroupReviewChecker::postUrl($postUrl);
        $row->forceFill([
            'status' => $status,
            'comment' => $comment !== null && trim($comment) !== '' ? Str::limit($comment, 2000) : $row->comment,
            'post_url' => $postUrl,
            'post_key' => self::postKey($postUrl),
            'author' => $author !== null ? Str::limit(trim($author), 250) : null,
            'error' => $error !== null ? Str::limit($error, 990) : null,
            'finished_at' => now(),
        ])->save();

        $group = $row->group;
        switch ($status) {
            case FacebookEngageComment::NOT_ALLOWED:
                $group->forceFill([
                    'engage_enabled' => false,
                    'engage_disabled_reason' => Str::limit($error ?: 'Page không bình luận được trong nhóm này', 490),
                ])->save();
                $this->shortenWait();
                break;

            case FacebookEngageComment::NO_POST:
            case FacebookEngageComment::FAILED:
                $this->shortenWait();
                break;

            case FacebookEngageComment::BLOCKED:
                $this->stopAndNotify($error);
                break;
        }

        return true;
    }

    /** Lượt có thể đã bình luận từ 0h hôm nay, giờ VN. */
    public function countToday(): int
    {
        return FacebookEngageComment::whereIn('status', FacebookEngageComment::COUNTS_TOWARD_CAP)
            ->where('claimed_at', '>=', now()->startOfDay())
            ->count();
    }

    /** Số bài trong link bài nhóm (…/posts/<số>/ hoặc …/permalink/<số>/). */
    public static function postKey(?string $postUrl): ?string
    {
        return $postUrl !== null && preg_match('#/(?:posts|permalink)/(\d{1,40})#', $postUrl, $match) === 1 ? $match[1] : null;
    }

    /** Nhóm được bình luận dạo bằng page này (page đã vào nhóm — bot lấy nhóm theo từng page). */
    public function groups(FacebookProfile $profile): Builder
    {
        return FacebookGroup::where('engage_enabled', true)
            ->whereHas('profiles', fn (Builder $q) => $q->whereKey($profile->id));
    }

    /** Nhóm lâu chưa ghé nhất (chưa ghé bao giờ đi trước), đã qua khoảng cách mỗi nhóm. */
    private function nextGroup(FacebookProfile $profile): ?FacebookGroup
    {
        $cutoff = now()->subMinutes($this->settings->config()['group_gap_minutes']);

        return $this->groups($profile)
            ->where(fn (Builder $q) => $q->whereNull('last_engaged_at')->orWhere('last_engaged_at', '<=', $cutoff))
            ->orderByRaw('last_engaged_at is not null')
            ->orderBy('last_engaged_at')
            ->orderBy('id')
            ->first();
    }

    private function releaseStale(): void
    {
        FacebookEngageComment::where('status', FacebookEngageComment::CLAIMED)
            ->where('claimed_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update([
                'status' => FacebookEngageComment::UNKNOWN,
                'finished_at' => now(),
                'error' => 'Bot không báo kết quả sau '.self::STALE_MINUTES.' phút — có thể đã bình luận hoặc chưa.',
            ]);
    }

    private function waitRandomGap(): void
    {
        $config = $this->settings->config();
        $seconds = random_int($config['gap_min'] * 60, max($config['gap_min'], $config['gap_max']) * 60);
        $this->settings->setNextAt(now()->addSeconds($seconds));
    }

    /** Lượt vừa rồi không để lại bình luận nào — không cần nghỉ đủ khoảng nghỉ. */
    private function shortenWait(): void
    {
        $soon = now()->addMinutes(self::AFTER_NOTHING_MINUTES);
        $next = $this->settings->nextAt();
        if ($next === null || $next->gt($soon)) {
            $this->settings->setNextAt($soon);
        }
    }

    /**
     * Facebook chặn bình luận: tắt hẳn bình luận dạo tới khi admin bật lại, báo Zalo một lần. Đăng
     * deal vẫn chạy — Facebook chặn theo từng tính năng.
     */
    private function stopAndNotify(?string $detail): void
    {
        if (! $this->settings->enabled()) {
            return;
        }

        $this->settings->stop('Facebook báo tạm chặn bình luận'.($detail ? ' — '.$detail : ''));
        Log::warning('FacebookEngageQueue: Facebook chặn bình luận, tắt bình luận dạo', ['detail' => $detail]);

        $this->notifier->notify(implode("\n", array_filter([
            '⚠️ Bình luận dạo nhóm follow chéo đã TẮT: Facebook báo tạm chặn bình luận.',
            $detail ? 'Chi tiết: '.Str::limit($detail, 300) : null,
            'Đăng deal vẫn chạy. Để page nghỉ 1–2 ngày, giảm số bình luận/ngày rồi bật lại ở '.route('admin.fb-engage'),
        ])));
    }

    /** @return array{reason: string, retry_after: int, until: null} */
    private function blocked(string $reason): array
    {
        return ['reason' => $reason, 'retry_after' => 300, 'until' => null];
    }

    /** @return array{reason: string, retry_after: int, until: string} */
    private function blockedUntil(string $reason, CarbonInterface $until): array
    {
        $seconds = (int) max(15, min(self::MAX_RETRY_AFTER, now()->diffInSeconds($until, false)));

        return ['reason' => $reason, 'retry_after' => $seconds, 'until' => $until->toIso8601String()];
    }
}
