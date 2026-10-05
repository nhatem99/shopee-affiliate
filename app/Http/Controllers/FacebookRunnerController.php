<?php

namespace App\Http\Controllers;

use App\Models\FacebookGroup;
use App\Models\FacebookGroupPost;
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
    public function __construct(private FacebookGroupPostScheduler $scheduler) {}

    public function poll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'claim_key' => ['required', 'string', 'min:16', 'max:64'],
            'state' => ['nullable', Rule::in(['ok', 'logged_out', 'checkpoint', 'blocked'])],
            'version' => ['nullable', 'string', 'max:32'],
            'account' => ['nullable', 'string', 'max:120'],
        ]);

        // Link /go/ trong bài dựng ngay trong request này — url() mặc định lấy host bot đã gọi
        // tới. Bot cấu hình gọi bằng IP hay tên miền phụ thì bài trên nhóm sẽ mang đúng host đó;
        // ép về APP_URL để link khách bấm luôn là tên miền chính.
        URL::forceRootUrl(config('app.url'));

        return response()->json($this->scheduler->poll($data['claim_key'], [
            'state' => $data['state'] ?? 'ok',
            'version' => $data['version'] ?? null,
            'account' => $data['account'] ?? null,
        ]));
    }

    public function result(Request $request, int $id): JsonResponse
    {
        $post = FacebookGroupPost::findOrFail($id);

        $data = $request->validate([
            'claim_key' => ['required', 'string', 'max:64'],
            'status' => ['required', Rule::in(FacebookGroupPost::RESULTS)],
            'error' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $this->scheduler->report($post, $data['claim_key'], $data['status'], $data['error'] ?? null)) {
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
        ]);

        return response()->json($reviews->apply($group, $data['tabs']));
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
        ]);

        return response()->json($this->scheduler->syncGroups($data['groups'], $data['account'] ?? null));
    }
}
