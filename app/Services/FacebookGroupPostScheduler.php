<?php

namespace App\Services;

use App\Models\FacebookGroup;
use App\Models\FacebookGroupDeal;
use App\Models\FacebookGroupPost;
use App\Models\ShortLink;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Bộ não của bot đăng nhóm Facebook. Bot trên máy nhà (deploy/fb-group-runner) không tự quyết
 * gì về giờ giấc: nó chỉ hỏi "có việc không" (poll), làm, rồi báo kết quả (report). Mọi luật —
 * khung giờ, trần bài mỗi ngày, khoảng nghỉ giữa hai bài, mỗi nhóm bao lâu một bài — nằm ở đây,
 * theo giờ VN của server. Mac ngủ hay để múi giờ Nhật cũng không làm lệch lịch.
 *
 * Nguyên tắc chống đăng trùng: một bài đã có thể lên nhóm (đã bấm Đăng) thì KHÔNG BAO GIỜ tự
 * đăng lại — không rõ kết quả thì để "ambiguous" cho admin tự xem nhóm rồi quyết.
 */
class FacebookGroupPostScheduler
{
    /** Bot nhận bài mà quá chừng này không báo kết quả thì coi như không rõ đã đăng chưa. */
    public const STALE_CLAIM_MINUTES = 15;

    /** Hỏng trước khi bấm Đăng thì chỉ nghỉ ngắn — chưa có gì lên Facebook. */
    private const AFTER_FAILURE_MINUTES = 2;

    /** Trần thời gian bảo bot ngủ: admin đổi cấu hình thì tối đa chừng này bot mới biết. */
    private const MAX_RETRY_AFTER = 1800;

    private const LOCK_KEY = 'fb-runner:claim';

    private const PAUSE_REASONS = [
        'checkpoint' => 'Facebook bắt xác minh tài khoản hoặc nick đã bị đăng xuất',
        'logged_out' => 'Nick Facebook trên máy chạy bot đã bị đăng xuất',
        'blocked' => 'Facebook báo tạm chặn đăng bài',
    ];

    public function __construct(
        private FacebookGroupRunnerSettings $settings,
        private FacebookDealLinkBuilder $links,
        private FacebookDealCaption $captions,
        private ZaloAdminNotifier $notifier,
    ) {}

    /**
     * Bot hỏi việc. Trả về một trong ba dạng:
     *  • ['type' => 'post', ...]        — đăng bài này;
     *  • ['type' => 'sync_groups']      — lấy lại danh sách nhóm đã tham gia;
     *  • ['type' => 'idle', 'reason', 'retry_after'] — chưa có gì, ngủ rồi hỏi lại.
     *
     * @param  array{state?: ?string, version?: ?string, account?: ?string}  $runner
     */
    public function poll(string $claimKey, array $runner): array
    {
        $this->settings->recordRunner($runner);

        $state = $runner['state'] ?? 'ok';
        if (isset(self::PAUSE_REASONS[$state])) {
            $this->pauseAndNotify($state);
        }

        $this->releaseStaleClaims();
        $this->expireOldPending();

        // Bot gửi lại đúng key của lượt trước (mất response, nginx 504...) — trả lại đúng bài
        // đó chứ không nhận thêm bài mới.
        $mine = FacebookGroupPost::where('status', FacebookGroupPost::CLAIMED)->where('claim_key', $claimKey)->first();
        if ($mine) {
            return $mine->caption ? $this->payload($mine) : $this->idle('preparing', 15);
        }
        if (FacebookGroupPost::where('status', FacebookGroupPost::CLAIMED)->exists()) {
            return $this->idle('busy', 60);
        }

        if ($this->settings->paused()) {
            return $this->idle('paused', 300);
        }
        if ($this->settings->syncRequested()) {
            return ['type' => 'sync_groups'];
        }

        if ($blocking = $this->blockingReason()) {
            return $this->idle($blocking['reason'], $blocking['retry_after']);
        }

        $post = $this->claimNext($claimKey);
        if (! $post) {
            return $this->idle('empty', 120);
        }

        return $this->prepare($post);
    }

    /**
     * Vì sao lúc này chưa đăng — null là được đăng. Trang admin dùng lại để giải thích.
     *
     * @return array{reason: string, retry_after: int, until: ?string}|null
     */
    public function blockingReason(): ?array
    {
        if ($this->settings->paused()) {
            return ['reason' => 'paused', 'retry_after' => 300, 'until' => null];
        }

        $cadence = $this->settings->cadence();
        $now = now();
        $start = $now->copy()->setTimeFromTimeString($cadence['window_start']);
        $end = $now->copy()->setTimeFromTimeString($cadence['window_end']);

        if ($now->lt($start)) {
            return $this->blockedUntil('outside_window', $start);
        }
        if ($now->gte($end)) {
            return $this->blockedUntil('outside_window', $start->copy()->addDay());
        }
        if ($this->countToday() >= $cadence['max_per_day']) {
            return $this->blockedUntil('daily_cap', $start->copy()->addDay());
        }

        $next = $this->settings->nextAllowedAt();
        if ($next && $now->lt($next)) {
            return $this->blockedUntil('gap', $next);
        }

        return null;
    }

    /** Số lượt đã (có thể) đưa bài lên Facebook từ 0h hôm nay, giờ VN. */
    public function countToday(): int
    {
        return FacebookGroupPost::whereIn('status', FacebookGroupPost::COUNTS_TOWARD_CAP)
            ->where('claimed_at', '>=', now()->startOfDay())
            ->count();
    }

    /**
     * Bot báo kết quả một bài. false = không khớp lượt nhận bài (key sai, bài đã chốt kết quả
     * khác) — controller trả 409 để bot biết đừng gửi lại.
     */
    public function report(FacebookGroupPost $post, string $claimKey, string $status, ?string $error): bool
    {
        if ($post->claim_key === null || ! hash_equals($post->claim_key, $claimKey)) {
            return false;
        }

        // Bot gửi lại cùng một kết quả (lần trước mất response) — đã ghi rồi.
        if ($post->status === $status) {
            return true;
        }

        $group = $post->group;

        // Lượt đã bị coi là "không rõ" vì bot báo muộn — giờ biết chắc đã lên thì ghi nhận.
        if ($post->status === FacebookGroupPost::AMBIGUOUS
            && in_array($status, [FacebookGroupPost::POSTED, FacebookGroupPost::PENDING_APPROVAL], true)) {
            $post->forceFill(['status' => $status, 'error' => null])->save();
            $group->forceFill(['last_posted_at' => $post->finished_at ?? now()])->save();

            return true;
        }

        if ($post->status !== FacebookGroupPost::CLAIMED) {
            return false;
        }

        $post->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'error' => $error !== null ? Str::limit($error, 990) : null,
        ])->save();

        switch ($status) {
            case FacebookGroupPost::POSTED:
            case FacebookGroupPost::PENDING_APPROVAL:
                $group->forceFill(['last_posted_at' => now()])->save();
                $this->waitRandomGap();
                break;

            case FacebookGroupPost::AMBIGUOUS:
                $this->waitRandomGap();
                break;

            case FacebookGroupPost::NOT_ALLOWED:
                $group->forceFill([
                    'enabled' => false,
                    'disabled_reason' => Str::limit($error ?: 'Nhóm không cho nick này đăng bài', 490),
                    'last_attempt_at' => $group->last_posted_at,
                ])->save();
                $this->settings->setNextAllowedAt(now()->addMinutes(self::AFTER_FAILURE_MINUTES));
                break;

            case FacebookGroupPost::CHECKPOINT:
            case FacebookGroupPost::BLOCKED:
                $group->forceFill(['last_attempt_at' => $group->last_posted_at])->save();
                $this->pauseAndNotify($status, $error);
                break;

            default: // FAILED — chưa có gì lên nhóm, nhóm được đăng lại ngay khi admin bấm.
                $group->forceFill(['last_attempt_at' => $group->last_posted_at])->save();
                $this->settings->setNextAllowedAt(now()->addMinutes(self::AFTER_FAILURE_MINUTES));
        }

        return true;
    }

    /**
     * Danh sách nhóm bot cào ở trang "Nhóm của bạn". Nhóm mới vào TẮT — admin tự chọn nhóm
     * nào được đăng; nhóm đã có giữ nguyên bật/tắt.
     *
     * @param  list<array{url: string, name?: ?string}>  $groups
     * @return array{created: int, updated: int}
     */
    public function syncGroups(array $groups, ?string $account): array
    {
        $byKey = [];
        foreach ($groups as $item) {
            $key = FacebookGroup::keyFromUrl((string) ($item['url'] ?? ''));
            if ($key !== null) {
                $byKey[$key] ??= trim((string) ($item['name'] ?? '')) ?: null;
            }
        }

        $created = 0;
        $updated = 0;
        foreach ($byKey as $key => $name) {
            $group = FacebookGroup::firstOrNew(['fb_group_key' => $key]);
            if (! $group->exists) {
                $group->forceFill(['enabled' => false, 'source' => 'sync']);
                $created++;
            } else {
                $updated++;
            }
            $group->forceFill([
                'name' => $name ?? $group->name,
                'url' => FacebookGroup::urlFor($key),
                'last_seen_at' => now(),
            ])->save();
        }

        $this->settings->markSynced();
        Log::info('FacebookGroupPostScheduler: đã đồng bộ nhóm', compact('created', 'updated', 'account'));

        return ['created' => $created, 'updated' => $updated];
    }

    private function claimNext(string $claimKey): ?FacebookGroupPost
    {
        $lock = Cache::lock(self::LOCK_KEY, 30);
        if (! $lock->get()) {
            return null;
        }

        try {
            $cooldownStart = now()->subHours($this->settings->cadence()['cooldown_hours']);

            $post = FacebookGroupPost::where('status', FacebookGroupPost::PENDING)
                ->whereHas('group', fn ($query) => $query->where('enabled', true)->where(
                    fn ($q) => $q->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<=', $cooldownStart)
                ))
                ->orderBy('id')
                ->first();
            if (! $post) {
                return null;
            }

            // Chỉ nhận nếu vẫn còn "pending" — chặn hai lượt hỏi cùng lúc nhận trùng một bài.
            $claimed = FacebookGroupPost::whereKey($post->id)
                ->where('status', FacebookGroupPost::PENDING)
                ->update([
                    'status' => FacebookGroupPost::CLAIMED,
                    'claim_key' => $claimKey,
                    'claimed_at' => now(),
                    'finished_at' => null,
                    'error' => null,
                ]);
            if (! $claimed) {
                return null;
            }

            $post->group->forceFill(['last_attempt_at' => now()])->save();

            return $post->fresh(['deal', 'group']);
        } finally {
            $lock->release();
        }
    }

    /**
     * Dựng link và nội dung NGOÀI khoá: chế độ mã YTB mất tới ~45 giây.
     */
    private function prepare(FacebookGroupPost $post): array
    {
        $deal = $post->deal;

        try {
            $link = $this->links->build($deal->shopee_url);
            $kind = 'fresh';
        } catch (\Throwable $e) {
            Log::warning('FacebookGroupPostScheduler: không lấy được link mới, dùng link lúc soạn bài', [
                'post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);
            $link = $this->fallbackLink($deal);
            $kind = 'fallback';
            $buildError = $e->getMessage();
        }

        if (! $link) {
            $post->forceFill([
                'status' => FacebookGroupPost::FAILED,
                'finished_at' => now(),
                'error' => Str::limit('Không tạo được link mua: '.($buildError ?? '?'), 990),
            ])->save();
            $post->group->forceFill(['last_attempt_at' => $post->group->last_posted_at])->save();

            return $this->idle('link_failed', 60);
        }

        $post->forceFill([
            'caption' => $this->captions->render($deal->caption, $link->captionBlock()),
            'buy_url' => $link->buyUrl,
            'link_kind' => $kind,
            'short_link_id' => $link->shortLinkId,
        ])->save();

        return $this->payload($post);
    }

    private function fallbackLink(FacebookGroupDeal $deal): ?FacebookDealLink
    {
        if (! $deal->fallback_buy_url) {
            return null;
        }

        $code = basename((string) parse_url($deal->fallback_buy_url, PHP_URL_PATH));

        return new FacebookDealLink(
            $deal->fallback_buy_url,
            $deal->fallback_ytb_url,
            ShortLink::where('code', $code)->value('id'),
            $deal->source,
            $deal->canonical_url,
            $deal->product,
        );
    }

    private function payload(FacebookGroupPost $post): array
    {
        $post->loadMissing(['deal', 'group']);

        return [
            'type' => 'post',
            'post_id' => $post->id,
            'claim_key' => $post->claim_key,
            'group_url' => $post->group->url,
            'group_name' => $post->group->name,
            'caption' => $post->caption,
            'image_url' => $post->deal->product['product_image'] ?? null,
        ];
    }

    private function releaseStaleClaims(): void
    {
        FacebookGroupPost::where('status', FacebookGroupPost::CLAIMED)
            ->where('claimed_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))
            ->update([
                'status' => FacebookGroupPost::AMBIGUOUS,
                'finished_at' => now(),
                'error' => 'Bot không báo kết quả sau '.self::STALE_CLAIM_MINUTES.' phút — có thể đã đăng hoặc chưa. Xem nhóm rồi mới bấm đăng lại.',
            ]);
    }

    private function expireOldPending(): void
    {
        FacebookGroupPost::where('status', FacebookGroupPost::PENDING)
            ->where('queued_at', '<', now()->subHours($this->settings->cadence()['expire_hours']))
            ->update([
                'status' => FacebookGroupPost::EXPIRED,
                'finished_at' => now(),
                'error' => 'Chờ quá lâu chưa tới lượt — bỏ để không đăng deal cũ.',
            ]);
    }

    private function waitRandomGap(): void
    {
        $cadence = $this->settings->cadence();
        $seconds = random_int($cadence['gap_min'] * 60, max($cadence['gap_min'], $cadence['gap_max']) * 60);
        $this->settings->setNextAllowedAt(now()->addSeconds($seconds));
    }

    /** Báo admin một lần cho mỗi lần dừng — bot hỏi lại liên tục không làm spam Zalo. */
    private function pauseAndNotify(string $state, ?string $detail = null): void
    {
        if ($this->settings->paused()) {
            return;
        }

        $reason = self::PAUSE_REASONS[$state] ?? $state;
        $this->settings->pause($reason);

        Log::warning('FacebookGroupPostScheduler: bot đăng nhóm tự tạm dừng', ['state' => $state, 'detail' => $detail]);

        $this->notifier->notify(implode("\n", array_filter([
            '⚠️ Bot đăng nhóm Facebook đã TẠM DỪNG: '.$reason.'.',
            $detail ? 'Chi tiết: '.Str::limit($detail, 300) : null,
            'Mở cửa sổ Chromium của bot trên máy nhà, xử lý xong thì bấm "Chạy tiếp" ở '.route('admin.fb-groups'),
        ])));
    }

    /**
     * @return array{reason: string, retry_after: int, until: string}
     */
    private function blockedUntil(string $reason, CarbonInterface $until): array
    {
        $seconds = (int) max(15, min(self::MAX_RETRY_AFTER, now()->diffInSeconds($until, false)));

        return ['reason' => $reason, 'retry_after' => $seconds, 'until' => $until->toIso8601String()];
    }

    private function idle(string $reason, int $retryAfter): array
    {
        return ['type' => 'idle', 'reason' => $reason, 'retry_after' => $retryAfter];
    }
}
