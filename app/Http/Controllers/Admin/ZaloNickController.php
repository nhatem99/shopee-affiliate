<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DirectAffiliateLinkService;
use App\Services\ZaloPersonalBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Nick Zalo cá nhân trả link trong nhóm (xem ZaloGroupListen): xem đã đăng nhập chưa và quét
 * mã QR ngay trên trang admin, thay cho SSH lên VPS chạy cầu nối trong terminal.
 */
class ZaloNickController extends Controller
{
    public function __construct(private ZaloPersonalBridge $bridge) {}

    public function index(): Response
    {
        return Inertia::render('Admin/ZaloNick', [
            'status' => $this->bridge->status(),
            'bridgeUrl' => config('services.zalo_personal.bridge_url'),
            'groupIds' => config('services.zalo_personal.group_ids'),
            'repliesPer10Minutes' => config('services.zalo_personal.replies_per_10_minutes'),
            // Công tắc dùng chung với trang Đăng nhóm FB — xem GroupLinksDirectToggle.vue.
            'groupLinksDirectAffiliate' => DirectAffiliateLinkService::enabled(),
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
}
