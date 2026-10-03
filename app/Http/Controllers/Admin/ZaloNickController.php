<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ZaloMirrorService;
use App\Services\ZaloMirrorSettings;
use App\Services\ZaloPersonalBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Nick Zalo cá nhân trả link trong nhóm (xem ZaloGroupListen): xem đã đăng nhập chưa và quét
 * mã QR ngay trên trang admin, thay cho SSH lên VPS chạy cầu nối trong terminal.
 *
 * Cũng quản lý tính năng mirror (đăng lại bài từ nhóm nguồn sang nhóm đích): cài đặt và
 * lịch sử kết quả gần nhất để admin theo dõi không cần xem log.
 */
class ZaloNickController extends Controller
{
    public function __construct(
        private ZaloPersonalBridge $bridge,
        private ZaloMirrorSettings $mirrorSettings,
        private ZaloMirrorService $mirrorService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Admin/ZaloNick', [
            'status' => $this->bridge->status(),
            'bridgeUrl' => config('services.zalo_personal.bridge_url'),
            'groupIds' => config('services.zalo_personal.group_ids'),
            'repliesPer10Minutes' => config('services.zalo_personal.replies_per_10_minutes'),
            // Cài đặt mirror và 30 kết quả gần nhất để admin theo dõi không cần xem log.
            'mirror' => $this->mirrorSettings->toArray(),
            'recentActivity' => $this->mirrorService->recentActivity(),
        ]);
    }

    /**
     * Trang gọi lại vài giây một lần để thay mã QR (Zalo đổi mã khoảng mỗi phút) và biết lúc
     * quét xong. JSON chứ không partial reload, cùng lý do với /admin/chats/unread.
     */
    public function status(): JsonResponse
    {
        return response()->json($this->bridge->status());
    }

    public function relogin(): RedirectResponse
    {
        try {
            $this->bridge->relogin();
        } catch (Throwable $e) {
            return back()->withErrors(['relogin' => 'Không gọi được cầu nối: '.$e->getMessage()]);
        }

        return back()->with('success', 'Đang tạo mã QR — quét bằng app Zalo của nick phụ.');
    }

    /**
     * Lưu cài đặt mirror: nhóm nguồn, nhóm đích, tốc độ đăng, adminsOnly.
     *
     * Validate:
     *  • id nhóm là chuỗi số (Zalo dùng id kiểu int 64-bit, không có chữ cái)
     *  • nhóm đích không được nằm trong nhóm nguồn (tránh vòng lặp spam)
     *  • postsPerHour từ 1 đến 60
     */
    public function saveMirror(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => 'boolean',
            'sourceGroupIds' => 'array',
            'sourceGroupIds.*' => ['string', 'regex:/^\d+$/'],
            'targetGroupId' => ['nullable', 'string', 'regex:/^\d+$/'],
            'postsPerHour' => 'integer|min:1|max:60',
            'adminsOnly' => 'boolean',
        ]);

        // Nhóm đích không được nằm trong nhóm nguồn.
        $target = $validated['targetGroupId'] ?? null;
        $sources = $validated['sourceGroupIds'] ?? [];
        if ($target !== null && in_array($target, $sources, true)) {
            return back()->withErrors(['targetGroupId' => 'Nhóm đích không được nằm trong danh sách nhóm nguồn.']);
        }

        $this->mirrorSettings->save($validated);

        return back()->with('success', 'Đã lưu cài đặt mirror.');
    }

    /**
     * Danh sách nhóm nick đang tham gia — để admin chọn nhóm nguồn/đích bằng tên thay vì id.
     * JSON vì trang gọi khi mở modal chọn nhóm, không reload toàn trang.
     * Timeout 30 giây: cầu nối phải lấy danh sách liên lạc, có thể chậm khi account có nhiều nhóm.
     */
    public function groups(): JsonResponse
    {
        try {
            $groups = $this->bridge->groups();

            return response()->json(['success' => true, 'groups' => $groups]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => 'Không lấy được danh sách nhóm: '.$e->getMessage()], 200);
        }
    }
}
