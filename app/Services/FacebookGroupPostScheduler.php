<?php

namespace App\Services;

use App\Models\FacebookGroup;
use App\Models\FacebookGroupDeal;
use App\Models\FacebookGroupPost;
use App\Models\FacebookProfile;
use App\Models\ShortLink;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
 *
 * Bot đăng bằng nhiều page (FacebookProfile: nick chính + các Trang nick đó quản lý), lần lượt
 * theo thứ tự: page đầu đăng đủ trần bài/ngày của nó mới tới page sau. Page bị Facebook chặn thì
 * chỉ page đó nghỉ; bắt xác minh/đăng xuất là chuyện của cả nick nên vẫn dừng cả bot.
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

    /**
     * Bot từ bản này mới đính kèm được ảnh admin tự tải lên (trường "images" của lượt nhận bài).
     * Bot cũ chỉ biết "image_url" — giao bài có ảnh tự tải cho nó là bài lên nhóm thiếu ảnh, nên
     * những bài đó nằm chờ tới khi bot được cập nhật.
     */
    public const UPLOADS_MIN_VERSION = '1.1.0';

    /**
     * Bot từ bản này mới biết chuyển page trước khi đăng. Bot cũ đăng bằng nick đang mở trong
     * trình duyệt, nên chỉ được giao bài của nick chính.
     */
    public const PROFILES_MIN_VERSION = '1.3.0';

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
        private FacebookPostImages $images,
        private FacebookGroupReviewChecker $reviews,
    ) {}

    /**
     * Bot hỏi việc. Trả về một trong bốn dạng:
     *  • ['type' => 'post', ...]        — đăng bài này;
     *  • ['type' => 'sync_groups']      — lấy lại danh sách nhóm đã tham gia;
     *  • ['type' => 'review_group', ...] — lúc rảnh: xem bài đã đăng có được duyệt không;
     *  • ['type' => 'idle', 'reason', 'retry_after'] — chưa có gì, ngủ rồi hỏi lại.
     *
     * @param  array{state?: ?string, version?: ?string, account?: ?string, account_id?: ?string, actor_id?: ?string}  $runner
     */
    public function poll(string $claimKey, array $runner): array
    {
        $this->settings->recordRunner($runner);
        $this->rememberAccount($runner['account_id'] ?? null);

        // Bị chặn lúc kiểm tra duyệt bài: chặn đúng page đang mở. Page lạ (chưa thêm ở admin) thì
        // không biết cho ai nghỉ — dừng cả bot như trước.
        $state = $runner['state'] ?? 'ok';
        $acting = $state === 'blocked' ? $this->resolveActor($runner['account_id'] ?? null, $runner['actor_id'] ?? null) : null;
        if ($acting) {
            $this->blockProfile($acting, 'Facebook báo tạm chặn đăng bài', null);
        } elseif (isset(self::PAUSE_REASONS[$state])) {
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
        $version = $runner['version'] ?? null;
        if ($this->settings->syncRequested()) {
            // Giao một lần rồi xoá cờ: bot lấy hỏng (không chuyển được page, Facebook đổi giao
            // diện...) mà giữ cờ thì lượt hỏi nào cũng lại mở Facebook lấy nhóm. Hỏng thì bấm lại.
            $this->settings->clearSyncRequest();

            return $this->syncJob($version);
        }

        // Đăng bài trước; kiểm tra duyệt bài chỉ chen vào lúc rảnh, và vẫn trong khung giờ đăng.
        if ($blocking = $this->blockingReason()) {
            $review = $blocking['reason'] !== 'outside_window' ? $this->reviews->nextJob($version) : null;

            return $review ?? $this->idle($blocking['reason'], $blocking['retry_after']);
        }

        $post = $this->claimNext($claimKey, $version);
        if (! $post) {
            return $this->reviews->nextJob($version) ?? $this->idle('empty', 120);
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

        $version = $this->settings->runnerStatus()['version'];
        if ($this->postingProfiles($version)->isEmpty()) {
            $usable = FacebookProfile::where('enabled', true)
                ->when(! self::runnerSupportsProfiles($version), fn ($query) => $query->where('is_primary', true));
            if (! (clone $usable)->exists()) {
                return ['reason' => 'no_profile', 'retry_after' => 300, 'until' => null];
            }
            if (! $usable->whereNull('blocked_at')->exists()) {
                return ['reason' => 'all_blocked', 'retry_after' => 300, 'until' => null];
            }

            return $this->blockedUntil('daily_cap', $start->copy()->addDay());
        }

        $next = $this->settings->nextAllowedAt();
        if ($next && $now->lt($next)) {
            return $this->blockedUntil('gap', $next);
        }

        return null;
    }

    /**
     * Page được giao bài lúc này, theo thứ tự đăng: bật, không bị chặn, chưa đủ trần hôm nay.
     *
     * @return Collection<int, FacebookProfile>
     */
    public function postingProfiles(?string $runnerVersion): Collection
    {
        $counts = $this->countTodayByProfile();

        return FacebookProfile::available()
            ->ordered()
            ->when(! self::runnerSupportsProfiles($runnerVersion), fn ($query) => $query->where('is_primary', true))
            ->get()
            ->filter(fn (FacebookProfile $profile) => ($counts[$profile->id] ?? 0) < $profile->max_per_day)
            ->values();
    }

    /**
     * Nhóm nào lúc này xếp thêm bài được — trang chọn nhóm chỉ cho tích những nhóm 'ready'.
     * Vướng một trong ba thì bài xếp thêm phải nằm chờ cả buổi (dễ hết hạn trước khi tới lượt):
     *  • 'ready_at'   — chưa hết giãn cách "mỗi nhóm 1 bài / N giờ" (tính như claimNext);
     *  • 'queued'     — đã có bài chờ hoặc đang đăng, bài sau phải chờ bài đó lên rồi thêm N giờ;
     *  • 'no_profile' — chưa page nào (đang bật) vào nhóm, không ai đăng được.
     *
     * @param  iterable<FacebookGroup>  $groups  có last_attempt_at
     * @return array<int, array{ready: bool, ready_at: ?string, queued: bool, no_profile: bool}>
     */
    public function groupReadiness(iterable $groups): array
    {
        $cooldownHours = $this->settings->cadence()['cooldown_hours'];
        $queued = FacebookGroupPost::whereIn('status', [FacebookGroupPost::PENDING, FacebookGroupPost::CLAIMED])
            ->distinct()
            ->pluck('facebook_group_id')
            ->flip();
        $withProfile = DB::table('facebook_group_profile')
            ->join('facebook_profiles', 'facebook_profiles.id', '=', 'facebook_group_profile.facebook_profile_id')
            ->where('facebook_profiles.enabled', true)
            ->distinct()
            ->pluck('facebook_group_profile.facebook_group_id')
            ->flip();

        $readiness = [];
        foreach ($groups as $group) {
            $readyAt = $group->last_attempt_at?->copy()->addHours($cooldownHours);
            $readyAt = $readyAt?->isFuture() ? $readyAt : null;
            $isQueued = $queued->has($group->id);
            $noProfile = ! $withProfile->has($group->id);

            $readiness[$group->id] = [
                'ready' => $readyAt === null && ! $isQueued && ! $noProfile,
                'ready_at' => $readyAt?->toIso8601String(),
                'queued' => $isQueued,
                'no_profile' => $noProfile,
            ];
        }

        return $readiness;
    }

    /** "1.1.0-node", "1.1.0" → được; "1.0.0-node", không gửi phiên bản → chưa. */
    public static function runnerSupportsUploads(?string $version): bool
    {
        return self::runnerAtLeast($version, self::UPLOADS_MIN_VERSION);
    }

    public static function runnerSupportsProfiles(?string $version): bool
    {
        return self::runnerAtLeast($version, self::PROFILES_MIN_VERSION);
    }

    public static function runnerAtLeast(?string $version, string $min): bool
    {
        return $version !== null
            && preg_match('/^\d+(?:\.\d+)*/', $version, $match) === 1
            && version_compare($match[0], $min, '>=');
    }

    /** Số lượt đã (có thể) đưa bài lên Facebook từ 0h hôm nay, giờ VN — mọi page cộng lại. */
    public function countToday(): int
    {
        return FacebookGroupPost::whereIn('status', FacebookGroupPost::COUNTS_TOWARD_CAP)
            ->where('claimed_at', '>=', now()->startOfDay())
            ->count();
    }

    /** @return array<int, int> id page → số lượt hôm nay (tính như countToday) */
    public function countTodayByProfile(): array
    {
        return FacebookGroupPost::whereIn('status', FacebookGroupPost::COUNTS_TOWARD_CAP)
            ->where('claimed_at', '>=', now()->startOfDay())
            ->whereNotNull('facebook_profile_id')
            ->groupBy('facebook_profile_id')
            ->selectRaw('facebook_profile_id, count(*) as aggregate')
            ->pluck('aggregate', 'facebook_profile_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Page đang mở trong trình duyệt của bot: uid page (cookie i_user) hoặc nick chính (c_user).
     * null nếu là một page chưa thêm ở /admin/fb-groups.
     */
    public function resolveActor(?string $accountId, ?string $actorId): ?FacebookProfile
    {
        if ($actorId !== null && $actorId !== $accountId) {
            return FacebookProfile::where('fb_id', $actorId)->first();
        }

        return FacebookProfile::where('is_primary', true)->first();
    }

    /**
     * Bot báo kết quả một bài. false = không khớp lượt nhận bài (key sai, bài đã chốt kết quả
     * khác) — controller trả 409 để bot biết đừng gửi lại.
     */
    public function report(FacebookGroupPost $post, string $claimKey, string $status, ?string $error, ?string $actorId = null): bool
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

        $profile = $post->profile;

        // Chưa mở nhóm — không có gì lên Facebook: bài về hàng chờ cho page khác, page này nghỉ.
        if ($status === FacebookGroupPost::SWITCH_FAILED) {
            $this->requeue($post, 'Bot không chuyển được sang '.($profile?->label() ?? 'page được giao').' — chờ page khác đăng.');
            $group->forceFill(['last_attempt_at' => $group->last_posted_at])->save();
            if ($profile) {
                $this->blockProfile($profile, 'Bot không chuyển được sang page này', $error);
            }
            $this->settings->setNextAllowedAt(now()->addMinutes(self::AFTER_FAILURE_MINUTES));

            return true;
        }

        $this->rememberPageId($profile, $actorId);

        // Page này không đăng được ở nhóm (chưa vào nhóm, nhóm không nhận Trang...) mà nhóm còn
        // page khác — bỏ page này khỏi nhóm, bài về hàng chờ cho page kia. Chưa bấm Đăng nên an toàn.
        if ($status === FacebookGroupPost::NOT_ALLOWED && $profile && $this->handOver($group, $profile)) {
            $this->requeue($post, Str::limit('Không đăng được bằng '.$profile->label().' ('.($error ?: 'nhóm không cho đăng').') — chờ page khác trong nhóm.', 990));
            $group->forceFill(['last_attempt_at' => $group->last_posted_at])->save();
            $this->settings->setNextAllowedAt(now()->addMinutes(self::AFTER_FAILURE_MINUTES));

            return true;
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

            case FacebookGroupPost::BLOCKED:
                $group->forceFill(['last_attempt_at' => $group->last_posted_at])->save();
                if ($profile) {
                    $this->blockProfile($profile, 'Facebook báo tạm chặn đăng bài', $error);
                    // Nghỉ như sau một bài thường rồi page kế tiếp mới đăng — không đăng dồn ngay.
                    $this->waitRandomGap();
                } else {
                    $this->pauseAndNotify($status, $error);
                }
                break;

            case FacebookGroupPost::CHECKPOINT:
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
     * Mỗi lần là danh sách nhóm của MỘT page — gắn các nhóm đó cho page ấy (không có page là nick
     * chính, như bot cũ). Không gỡ nhóm vắng mặt: cào thiếu do cuộn trang thì dễ gỡ nhầm; page
     * đã rời nhóm thì lúc đăng bot báo "không cho đăng" và server gỡ khi đó.
     *
     * @param  list<array{url: string, name?: ?string}>  $groups
     * @return array{created: int, updated: int}
     */
    public function syncGroups(array $groups, ?string $account, ?FacebookProfile $profile = null): array
    {
        $profile ??= FacebookProfile::primary();

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
            $profile->groups()->syncWithoutDetaching([$group->id => ['last_seen_at' => now()]]);
        }

        $profile->forceFill(['last_synced_at' => now()])->save();
        $this->settings->markSynced();
        Log::info('FacebookGroupPostScheduler: đã đồng bộ nhóm', compact('created', 'updated', 'account') + ['profile_id' => $profile->id]);

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Page đầu tiên (theo thứ tự) còn bài để đăng thì nhận — page đó hết bài ở các nhóm nó đã
     * vào thì tới page sau, dù page đầu chưa đủ trần.
     */
    private function claimNext(string $claimKey, ?string $runnerVersion): ?FacebookGroupPost
    {
        $lock = Cache::lock(self::LOCK_KEY, 30);
        if (! $lock->get()) {
            return null;
        }

        try {
            $cooldownStart = now()->subHours($this->settings->cadence()['cooldown_hours']);
            $withUploads = self::runnerSupportsUploads($runnerVersion);

            foreach ($this->postingProfiles($runnerVersion) as $profile) {
                $post = FacebookGroupPost::where('status', FacebookGroupPost::PENDING)
                    ->whereHas('group', fn ($query) => $query->where('enabled', true)
                        ->where(fn ($q) => $q->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<=', $cooldownStart))
                        ->whereHas('profiles', fn ($q) => $q->whereKey($profile->id)))
                    ->when(! $withUploads, fn ($query) => $query->whereHas('deal', fn ($q) => $q->whereNull('images')))
                    ->orderBy('id')
                    ->first();
                if (! $post) {
                    continue;
                }

                // Chỉ nhận nếu vẫn còn "pending" — chặn hai lượt hỏi cùng lúc nhận trùng một bài.
                $claimed = FacebookGroupPost::whereKey($post->id)
                    ->where('status', FacebookGroupPost::PENDING)
                    ->update([
                        'status' => FacebookGroupPost::CLAIMED,
                        'facebook_profile_id' => $profile->id,
                        'claim_key' => $claimKey,
                        'claimed_at' => now(),
                        'finished_at' => null,
                        'error' => null,
                    ]);
                if (! $claimed) {
                    return null;
                }

                $post->group->forceFill(['last_attempt_at' => now()])->save();

                return $post->fresh(['deal', 'group', 'profile']);
            }

            return null;
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

        // Bài tự soạn: không link mua, chỉ còn bốc câu chữ {a|b} riêng cho nhóm này.
        if ($deal->isCustom()) {
            $post->forceFill(['caption' => $this->captions->render($deal->caption, '')])->save();

            return $this->payload($post);
        }

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
        $post->loadMissing(['deal', 'group', 'profile']);

        return [
            'type' => 'post',
            'post_id' => $post->id,
            'claim_key' => $post->claim_key,
            'group_url' => $post->group->url,
            'group_name' => $post->group->name,
            // Bot từ PROFILES_MIN_VERSION chuyển sang page này trước khi mở nhóm.
            'profile' => $post->profile?->forRunner(),
            'caption' => $post->caption,
            // Bot từ UPLOADS_MIN_VERSION dùng "images"; "image_url" giữ cho bot cũ — chúng chỉ
            // nhận bài không có ảnh tự tải (claimNext), nên ảnh sản phẩm là đủ.
            'image_url' => $this->images->productImage($post->deal),
            'images' => $this->images->refsFor($post->deal),
        ];
    }

    /**
     * Lấy nhóm: bot từ PROFILES_MIN_VERSION lần lượt chuyển sang từng page đang bật, lấy nhóm của
     * page đó; bot cũ lấy nhóm của nick đang mở (gắn cho nick chính).
     */
    private function syncJob(?string $runnerVersion): array
    {
        if (! self::runnerSupportsProfiles($runnerVersion)) {
            return ['type' => 'sync_groups'];
        }

        return [
            'type' => 'sync_groups',
            'profiles' => FacebookProfile::where('enabled', true)->ordered()->get()->map->forRunner()->all(),
        ];
    }

    /** Bài chưa lên nhóm, đưa về hàng chờ để page khác nhận (giữ queued_at — hạn chờ vẫn tính). */
    private function requeue(FacebookGroupPost $post, string $note): void
    {
        $post->forceFill([
            'status' => FacebookGroupPost::PENDING,
            'facebook_profile_id' => null,
            'claim_key' => null,
            'claimed_at' => null,
            'finished_at' => null,
            'caption' => null,
            'buy_url' => null,
            'link_kind' => null,
            'short_link_id' => null,
            'error' => $note,
        ])->save();
    }

    /** Gỡ page khỏi nhóm nếu nhóm còn page khác. Page cuối cùng thì giữ, để tắt nhóm như cũ. */
    private function handOver(FacebookGroup $group, FacebookProfile $profile): bool
    {
        if (! $group->profiles()->whereKeyNot($profile->id)->exists()) {
            return false;
        }
        $group->profiles()->detach($profile->id);

        return true;
    }

    /** uid nick chính lấy từ bot (cookie c_user) — để nhận ra page nào đang mở. */
    private function rememberAccount(?string $accountId): void
    {
        if ($accountId === null) {
            return;
        }
        $primary = FacebookProfile::where('is_primary', true)->first();
        if ($primary && $primary->fb_id !== $accountId && ! FacebookProfile::where('fb_id', $accountId)->exists()) {
            $primary->forceFill(['fb_id' => $accountId])->save();
        }
    }

    /** Page thêm bằng link tên rút gọn chưa biết uid — lấy uid bot thấy sau khi chuyển sang được. */
    private function rememberPageId(?FacebookProfile $profile, ?string $actorId): void
    {
        if (! $profile || $profile->is_primary || $profile->fb_id !== null || $actorId === null) {
            return;
        }
        if (! FacebookProfile::where('fb_id', $actorId)->exists()) {
            $profile->forceFill(['fb_id' => $actorId])->save();
        }
    }

    /**
     * Cho một page nghỉ tới khi admin bấm "Mở lại" — báo Zalo một lần. Các page khác đăng tiếp.
     */
    private function blockProfile(FacebookProfile $profile, string $headline, ?string $detail): void
    {
        if ($profile->blocked_at !== null) {
            return;
        }

        $profile->forceFill([
            'blocked_at' => now(),
            'blocked_reason' => Str::limit($headline.($detail ? ' — '.$detail : ''), 490),
        ])->save();

        Log::warning('FacebookGroupPostScheduler: cho page nghỉ', ['profile_id' => $profile->id, 'headline' => $headline, 'detail' => $detail]);

        $next = $this->postingProfiles($this->settings->runnerStatus()['version'])->first();
        $this->notifier->notify(implode("\n", array_filter([
            '⚠️ Bot đăng nhóm Facebook: '.$profile->label().' — '.$headline.'.',
            $detail ? 'Chi tiết: '.Str::limit($detail, 300) : null,
            $next ? 'Bot chuyển sang '.$next->label().'.' : 'Không còn page nào đăng được — bot nghỉ.',
            'Để page này nghỉ 1–2 ngày rồi bấm "Mở lại" ở '.route('admin.fb-groups'),
        ])));
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
