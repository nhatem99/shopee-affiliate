<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FacebookGroup;
use App\Models\FacebookGroupPost;
use App\Models\FacebookProfile;
use App\Services\FacebookGroupPostScheduler;
use App\Services\FacebookGroupReviewChecker;
use App\Services\FacebookGroupRunnerSettings;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/fb-groups — bot đăng nhóm Facebook trên máy nhà (trạng thái, token, tạm dừng, nhịp
 * đăng) và danh sách nhóm được phép đăng.
 */
class FacebookGroupController extends Controller
{
    /**
     * Bot ngủ tối đa 2 phút giữa hai lượt hỏi việc (xem deploy/fb-group-runner) — quá 5 phút
     * không thấy hỏi là bot không chạy.
     */
    private const ONLINE_WITHIN_MINUTES = 5;

    public function __construct(
        private FacebookGroupRunnerSettings $settings,
        private FacebookGroupPostScheduler $scheduler,
    ) {}

    public function index(): Response
    {
        $runner = $this->settings->runnerStatus();
        $lastSeen = $runner['last_seen_at'] ? Carbon::parse($runner['last_seen_at']) : null;
        $token = $this->settings->token();

        $groups = FacebookGroup::query()
            ->withCount([
                'posts as posted_count' => fn ($q) => $q->whereIn('status', [
                    FacebookGroupPost::POSTED, FacebookGroupPost::PENDING_APPROVAL,
                ]),
                // Kết quả bot kiểm tra "Nội dung của bạn" — xem FacebookGroupReviewChecker.
                'posts as review_published_count' => fn ($q) => $q->where('review_state', FacebookGroupPost::REVIEW_PUBLISHED),
                'posts as review_pending_count' => fn ($q) => $q->where('review_state', FacebookGroupPost::REVIEW_PENDING),
                'posts as review_rejected_count' => fn ($q) => $q->whereIn('review_state', FacebookGroupReviewChecker::FINAL),
                'posts as review_missing_count' => fn ($q) => $q->where('review_state', FacebookGroupPost::REVIEW_MISSING),
            ])
            ->with('profiles:id')
            ->orderByDesc('enabled')
            ->orderBy('name')
            ->get(['id', 'fb_group_key', 'name', 'url', 'enabled', 'source', 'disabled_reason', 'last_seen_at', 'last_attempt_at', 'last_posted_at', 'last_checked_at']);
        $readiness = $this->scheduler->groupReadiness($groups);

        $countsToday = $this->scheduler->countTodayByProfile();
        $postingIds = $this->scheduler->postingProfiles($runner['version'])->pluck('id');
        $profiles = FacebookProfile::ordered()->withCount('groups')->get();
        $acting = $runner['account_id'] || $runner['actor_id'] ? $this->scheduler->resolveActor($runner['account_id'], $runner['actor_id']) : null;

        return Inertia::render('Admin/FacebookGroups', [
            'runner' => $runner + [
                'online' => $lastSeen !== null && $lastSeen->gt(now()->subMinutes(self::ONLINE_WITHIN_MINUTES)),
            ],
            'tokenTail' => $token ? substr($token, -4) : null,
            'paused' => $this->settings->paused(),
            'pausedReason' => $this->settings->pausedReason(),
            'cadence' => $this->settings->cadence(),
            'blocking' => $this->scheduler->blockingReason(),
            'today' => [
                'used' => $this->scheduler->countToday(),
                'pending' => FacebookGroupPost::where('status', FacebookGroupPost::PENDING)->count(),
            ],
            'syncRequested' => $this->settings->syncRequested(),
            'lastSyncedAt' => $this->settings->lastSyncedAt(),
            'review' => [
                'supported' => FacebookGroupReviewChecker::runnerSupports($runner['version']),
                'min_version' => FacebookGroupReviewChecker::MIN_VERSION,
                'requested_at' => $this->settings->reviewRequestedAt()?->toIso8601String(),
            ],
            'baseUrl' => url('/'),
            'profiles' => $profiles->map(fn (FacebookProfile $profile) => [
                'id' => $profile->id,
                'label' => $profile->label(),
                'name' => $profile->name,
                'url' => $profile->url ?? ($profile->fb_id ? FacebookProfile::profileUrl($profile->fb_id) : null),
                'fb_id' => $profile->fb_id,
                'is_primary' => $profile->is_primary,
                'enabled' => $profile->enabled,
                'max_per_day' => $profile->max_per_day,
                'used_today' => $countsToday[$profile->id] ?? 0,
                'groups_count' => $profile->groups_count,
                'blocked_at' => $profile->blocked_at?->toIso8601String(),
                'blocked_reason' => $profile->blocked_reason,
                'last_synced_at' => $profile->last_synced_at?->toIso8601String(),
                // Page bot nhận bài kế tiếp (page đầu còn chỗ) — chưa chắc còn bài cho nó.
                'next' => $postingIds->first() === $profile->id,
                'acting' => $acting?->is($profile) ?? false,
            ]),
            'profilesSupported' => FacebookGroupPostScheduler::runnerSupportsProfiles($runner['version']),
            'profilesMinVersion' => FacebookGroupPostScheduler::PROFILES_MIN_VERSION,
            'groups' => $groups->map(fn (FacebookGroup $group) => collect($group->toArray())->except('profiles')->all() + $readiness[$group->id] + [
                'profile_ids' => $group->profiles->pluck('id'),
            ]),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'max_per_day' => ['required', 'integer', 'min:1', 'max:200'],
            'gap_min' => ['required', 'integer', 'min:1', 'max:1440'],
            'gap_max' => ['required', 'integer', 'gte:gap_min', 'max:1440'],
            'cooldown_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'window_start' => ['required', 'date_format:H:i'],
            'window_end' => ['required', 'date_format:H:i', 'after:window_start'],
            'expire_hours' => ['required', 'integer', 'min:1', 'max:720'],
        ], [
            'gap_max.gte' => 'Khoảng nghỉ tối đa phải lớn hơn hoặc bằng tối thiểu.',
            'window_end.after' => 'Giờ kết thúc phải sau giờ bắt đầu (trong cùng một ngày).',
        ]);

        $this->settings->saveCadence($data);

        return back()->with('success', 'Đã lưu nhịp đăng.');
    }

    /**
     * Trả JSON thay vì flash: token chỉ hiện MỘT lần, không nằm trong session hay props trang.
     */
    public function regenerateToken(): JsonResponse
    {
        return response()->json(['token' => $this->settings->regenerateToken()]);
    }

    public function pause(Request $request): RedirectResponse
    {
        $data = $request->validate(['paused' => ['required', 'boolean']]);

        if ($data['paused']) {
            $this->settings->pause('Admin tạm dừng');

            return back()->with('success', 'Đã tạm dừng — bot không nhận bài mới nữa.');
        }

        $this->settings->resume();

        return back()->with('success', 'Bot chạy tiếp.');
    }

    /** Bỏ khoảng nghỉ đang chờ để bài kế tiếp đi ngay (vẫn theo khung giờ và trần mỗi ngày). */
    public function clearWait(): RedirectResponse
    {
        $this->settings->setNextAllowedAt(null);

        return back()->with('success', 'Đã bỏ chờ — bot nhận bài kế tiếp ở lượt hỏi tới.');
    }

    public function requestSync(): RedirectResponse
    {
        $this->settings->requestSync();

        return back()->with('success', 'Đã gửi yêu cầu — bot lấy danh sách nhóm ở lượt hỏi tới (tối đa vài phút).');
    }

    /** Kiểm tra ngay bài đã đăng trong 3 ngày qua, không chờ tới lượt (bot vẫn làm lần lượt từng nhóm). */
    public function requestReview(): RedirectResponse
    {
        $this->settings->requestReview();

        return back()->with('success', 'Đã gửi yêu cầu — lúc rảnh bot sẽ lần lượt mở "Nội dung của bạn" của từng nhóm có bài trong 3 ngày qua.');
    }

    /**
     * Thêm tay một nhóm — thêm tay nghĩa là muốn đăng, nên bật luôn. Không biết page nào đã vào
     * nhóm nên gắn mọi page: page chưa vào thì lúc đăng bot báo "không cho đăng", server tự gỡ.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:500'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $key = FacebookGroup::keyFromUrl($data['url']);
        if ($key === null) {
            return back()->withErrors(['url' => 'Đây không phải link nhóm Facebook (dạng facebook.com/groups/...).']);
        }

        $group = FacebookGroup::firstOrNew(['fb_group_key' => $key]);
        if (! $group->exists) {
            $group->source = 'manual';
        }
        $group->forceFill([
            'name' => ($data['name'] ?? null) ?: $group->name,
            'url' => FacebookGroup::urlFor($key),
            'enabled' => true,
            'disabled_reason' => null,
        ])->save();
        $group->profiles()->syncWithoutDetaching(FacebookProfile::pluck('id'));

        return back()->with('success', 'Đã thêm nhóm.');
    }

    public function update(Request $request, FacebookGroup $facebookGroup): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $facebookGroup->forceFill([
            'enabled' => $data['enabled'],
            // Bật lại là admin đã xem lý do bot tự tắt nhóm — xoá để khỏi hiện lý do cũ.
            'disabled_reason' => $data['enabled'] ? null : $facebookGroup->disabled_reason,
        ])->save();

        return back();
    }

    /**
     * Chỉ xoá nhóm thêm tay: nhóm bot lấy về xoá đi thì lần đồng bộ sau lại hiện — muốn bỏ thì tắt.
     */
    public function destroy(FacebookGroup $facebookGroup): RedirectResponse
    {
        if ($facebookGroup->source !== 'manual') {
            return back()->withErrors(['group' => 'Nhóm bot lấy về thì tắt đi thay vì xoá — lần đồng bộ sau nó sẽ hiện lại.']);
        }

        $facebookGroup->delete();

        return back()->with('success', 'Đã xoá nhóm.');
    }
}
