<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FacebookGroup;
use App\Models\FacebookGroupPost;
use App\Services\FacebookGroupPostScheduler;
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
            'baseUrl' => url('/'),
            'groups' => FacebookGroup::query()
                ->withCount(['posts as posted_count' => fn ($q) => $q->whereIn('status', [
                    FacebookGroupPost::POSTED, FacebookGroupPost::PENDING_APPROVAL,
                ])])
                ->orderByDesc('enabled')
                ->orderBy('name')
                ->get(['id', 'fb_group_key', 'name', 'url', 'enabled', 'source', 'disabled_reason', 'last_seen_at', 'last_posted_at']),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'max_per_day' => ['required', 'integer', 'min:1', 'max:50'],
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

    /** Thêm tay một nhóm — thêm tay nghĩa là muốn đăng, nên bật luôn. */
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
