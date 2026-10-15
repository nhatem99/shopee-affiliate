<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FacebookEngageComment;
use App\Models\FacebookGroup;
use App\Models\FacebookProfile;
use App\Services\FacebookEngageQueue;
use App\Services\FacebookEngageSettings;
use App\Services\FacebookGroupRunnerSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/fb-engage — bình luận dạo trong các nhóm follow chéo: bật/tắt, page bình luận, câu mẫu,
 * nhịp, chọn nhóm và lịch sử. Bot là bot đăng nhóm ở /admin/fb-groups (cùng token, cùng máy).
 */
class FacebookEngageController extends Controller
{
    private const HISTORY_PER_PAGE = 20;

    public function __construct(
        private FacebookEngageSettings $settings,
        private FacebookEngageQueue $engage,
        private FacebookGroupRunnerSettings $runner,
    ) {}

    public function index(): Response
    {
        $config = $this->settings->config();
        $version = $this->runner->runnerStatus()['version'];
        $profile = $this->engage->profile();
        $profileGroupIds = $profile ? $profile->groups()->pluck('facebook_groups.id')->flip() : collect();

        $groups = FacebookGroup::query()
            ->withCount(['engageComments as commented_count' => fn ($q) => $q->where('status', FacebookEngageComment::COMMENTED)])
            ->orderByDesc('engage_enabled')
            ->orderBy('name')
            ->get(['id', 'fb_group_key', 'name', 'url', 'enabled', 'engage_enabled', 'engage_disabled_reason', 'last_engaged_at']);

        return Inertia::render('Admin/FacebookEngage', [
            'config' => $config,
            'templates' => $this->settings->templatesText(),
            'stoppedReason' => $this->settings->stoppedReason(),
            'blocking' => $this->engage->blockingReason($version),
            'nextAt' => $this->settings->nextAt()?->toIso8601String(),
            'today' => $this->engage->countToday(),
            'runner' => [
                'version' => $version,
                'supported' => FacebookEngageQueue::runnerSupports($version),
                'min_version' => FacebookEngageQueue::MIN_VERSION,
            ],
            'profiles' => FacebookProfile::ordered()->get()->map(fn (FacebookProfile $p) => [
                'id' => $p->id,
                'label' => $p->label(),
                'is_primary' => $p->is_primary,
                'blocked_reason' => $p->blocked_at ? $p->blocked_reason : null,
            ]),
            'groups' => $groups->map(fn (FacebookGroup $g) => [
                'id' => $g->id,
                'name' => $g->name ?: $g->fb_group_key,
                'url' => $g->url,
                'deal_enabled' => $g->enabled,
                'engage_enabled' => $g->engage_enabled,
                'engage_disabled_reason' => $g->engage_disabled_reason,
                'last_engaged_at' => $g->last_engaged_at?->toIso8601String(),
                'commented_count' => $g->commented_count,
                // Page bình luận chưa vào nhóm thì bot không bình luận ở đó.
                'in_profile' => $profileGroupIds->has($g->id),
            ]),
            'history' => FacebookEngageComment::with(['group:id,name,fb_group_key', 'profile'])
                ->latest('id')
                ->paginate(self::HISTORY_PER_PAGE)
                ->withQueryString()
                ->through(fn (FacebookEngageComment $c) => [
                    'id' => $c->id,
                    'status' => $c->status,
                    'group' => $c->group ? ($c->group->name ?: $c->group->fb_group_key) : null,
                    'profile' => $c->profile?->label(),
                    'comment' => $c->comment,
                    'post_url' => $c->post_url,
                    'author' => $c->author,
                    'error' => $c->error,
                    'claimed_at' => $c->claimed_at->toIso8601String(),
                ]),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'profile_id' => ['nullable', 'integer', 'exists:facebook_profiles,id'],
            'max_per_day' => ['required', 'integer', 'min:1', 'max:300'],
            'gap_min' => ['required', 'integer', 'min:1', 'max:1440'],
            'gap_max' => ['required', 'integer', 'gte:gap_min', 'max:1440'],
            'group_gap_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'window_start' => ['required', 'date_format:H:i'],
            'window_end' => ['required', 'date_format:H:i', 'after:window_start'],
            'templates' => ['nullable', 'string', 'max:20000'],
        ], [
            'gap_max.gte' => 'Khoảng nghỉ tối đa phải lớn hơn hoặc bằng tối thiểu.',
            'window_end.after' => 'Giờ kết thúc phải sau giờ bắt đầu (trong cùng một ngày).',
        ]);

        $this->settings->saveTemplates((string) ($data['templates'] ?? ''));
        unset($data['templates']);
        $this->settings->saveConfig($data);

        return back()->with('success', 'Đã lưu cài đặt bình luận dạo.');
    }

    public function toggle(Request $request): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $this->settings->setEnabled($data['enabled']);

        return back()->with('success', $data['enabled'] ? 'Đã bật — bot bình luận ở lượt hỏi việc tới khi tới giờ.' : 'Đã tắt bình luận dạo.');
    }

    /** Bỏ khoảng nghỉ đang chờ để lượt bình luận kế tiếp đi ngay (vẫn theo khung giờ và trần mỗi ngày). */
    public function clearWait(): RedirectResponse
    {
        $this->settings->setNextAt(null);

        return back()->with('success', 'Đã bỏ chờ — bot bình luận ở lượt hỏi việc tới.');
    }

    /** Bật/tắt bình luận dạo cho một hoặc nhiều nhóm. */
    public function updateGroups(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:5000'],
            'ids.*' => ['integer'],
            'engage_enabled' => ['required', 'boolean'],
        ]);

        FacebookGroup::whereKey($data['ids'])->update($data['engage_enabled']
            // Bật lại là admin đã xem lý do bot tự tắt nhóm.
            ? ['engage_enabled' => true, 'engage_disabled_reason' => null]
            : ['engage_enabled' => false]);

        return back();
    }
}
