<?php

namespace App\Http\Controllers;

use App\Models\FacebookGroup;
use App\Models\FacebookGroupPost;
use App\Models\FacebookProfile;
use App\Services\FacebookGroupPostScheduler;
use App\Services\FacebookGroupReviewChecker;
use App\Services\FacebookPostImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Nơi bot đăng nhóm Facebook (deploy/fb-group-runner, chạy trên máy nhà) hỏi việc và báo kết
 * quả. Route nằm NGOÀI nhóm web (routes/facebook-groups.php nạp từ bootstrap/app.php): không
 * CSRF, không session, không GeoBlock — xác thực bằng token ở VerifyFacebookRunnerToken.
 *
 * Ngoài nhóm web cũng không có SubstituteBindings, nên nhận {id} rồi tự findOrFail.
 */
class FacebookRunnerController extends Controller
{
    /** uid Facebook (nick hoặc page) — chỉ chữ số. */
    private const FB_ID = '/^\d{1,30}$/';

    public function __construct(private FacebookGroupPostScheduler $scheduler) {}

    public function poll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'claim_key' => ['required', 'string', 'min:16', 'max:64'],
            'state' => ['nullable', Rule::in(['ok', 'logged_out', 'checkpoint', 'blocked'])],
            'version' => ['nullable', 'string', 'max:32'],
            'account' => ['nullable', 'string', 'max:120'],
            'account_id' => ['nullable', 'string', 'regex:'.self::FB_ID],
            'actor_id' => ['nullable', 'string', 'regex:'.self::FB_ID],
        ]);

        // Link /go/ trong bài dựng ngay trong request này — url() mặc định lấy host bot đã gọi
        // tới. Bot cấu hình gọi bằng IP hay tên miền phụ thì bài trên nhóm sẽ mang đúng host đó;
        // ép về APP_URL để link khách bấm luôn là tên miền chính.
        URL::forceRootUrl(config('app.url'));

        return response()->json($this->scheduler->poll($data['claim_key'], [
            'state' => $data['state'] ?? 'ok',
            'version' => $data['version'] ?? null,
            'account' => $data['account'] ?? null,
            'account_id' => $data['account_id'] ?? null,
            'actor_id' => $data['actor_id'] ?? null,
        ]));
    }

    public function result(Request $request, int $id): JsonResponse
    {
        $post = FacebookGroupPost::findOrFail($id);

        $data = $request->validate([
            'claim_key' => ['required', 'string', 'max:64'],
            'status' => ['required', Rule::in(FacebookGroupPost::RESULTS)],
            'error' => ['nullable', 'string', 'max:1000'],
            // Page bot đã đăng bằng — server điền uid cho page thêm bằng link tên rút gọn.
            'actor_id' => ['nullable', 'string', 'regex:'.self::FB_ID],
        ]);

        if (! $this->scheduler->report($post, $data['claim_key'], $data['status'], $data['error'] ?? null, $data['actor_id'] ?? null)) {
            return response()->json(['message' => 'Lượt nhận bài không khớp — bỏ qua kết quả này.'], 409);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Bot báo những gì thấy ở các tab "Nội dung của bạn" của một nhóm (việc "review_group").
     * Mỗi bài: chữ hiện trên trang (đã cắt) và link bài nếu có. Bot không thấy bài nào dạng
     * thẻ thì gửi cả khối chữ của trang thành một mục không link.
     */
    public function review(Request $request, FacebookGroupReviewChecker $reviews, int $id): JsonResponse
    {
        $group = FacebookGroup::findOrFail($id);

        $data = $request->validate([
            'tabs' => ['required', 'array:'.implode(',', array_keys(FacebookGroupReviewChecker::TABS))],
            'tabs.*.ok' => ['required', 'boolean'],
            'tabs.*.error' => ['nullable', 'string', 'max:500'],
            'tabs.*.items' => ['present', 'array', 'max:100'],
            'tabs.*.items.*.text' => ['nullable', 'string', 'max:20000'],
            'tabs.*.items.*.url' => ['nullable', 'string', 'max:1000'],
            // Page bot đã chuyển sang để xem — chỉ xét bài của page đó. Bot cũ không gửi: xét hết.
            'profile_id' => ['nullable', 'integer'],
        ]);

        $profile = isset($data['profile_id']) ? FacebookProfile::findOrFail($data['profile_id']) : null;

        return response()->json($reviews->apply($group, $data['tabs'], $profile));
    }

    /** Ảnh admin tự tải lên cho bài — bot tải về đính kèm (đường dẫn có trong lượt nhận bài). */
    public function image(FacebookPostImages $images, string $name): BinaryFileResponse
    {
        $path = $images->path($name);
        abort_if($path === null, 404);

        return response()->file($path);
    }

    public function groups(Request $request): JsonResponse
    {
        $data = $request->validate([
            'groups' => ['present', 'array', 'max:5000'],
            'groups.*.url' => ['required', 'string', 'max:500'],
            'groups.*.name' => ['nullable', 'string', 'max:255'],
            'account' => ['nullable', 'string', 'max:120'],
            // Bot từ 1.3.0: nhóm này của page nào. Không có thì đoán theo page đang mở (bot cũ: nick chính).
            'profile_id' => ['nullable', 'integer'],
            'account_id' => ['nullable', 'string', 'regex:'.self::FB_ID],
            'actor_id' => ['nullable', 'string', 'regex:'.self::FB_ID],
        ]);

        $profile = isset($data['profile_id'])
            ? FacebookProfile::findOrFail($data['profile_id'])
            : $this->scheduler->resolveActor($data['account_id'] ?? null, $data['actor_id'] ?? null);
        if (! $profile) {
            return response()->json(['message' => 'Bot đang mở một page chưa thêm ở /admin/fb-groups — thêm page đó trước rồi lấy nhóm lại.'], 422);
        }

        return response()->json($this->scheduler->syncGroups($data['groups'], $data['account'] ?? null, $profile));
    }
}
