<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlockedIp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BlockedIpController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/BlockedIps', [
            'blockedIps' => BlockedIp::latest()->get(),
            'currentIp' => request()->ip(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip_address' => ['required', 'ip', 'unique:blocked_ips,ip_address'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // Chặn nhầm chính IP admin đang dùng để thao tác thì sẽ tự khóa mình ngoài các trang
        // khác (chỉ /admin/login còn mở) — chặn luôn ở đây cho rõ ràng thay vì để tự phát hiện.
        if ($data['ip_address'] === $request->ip()) {
            return back()->withErrors(['ip_address' => 'Đây là IP bạn đang dùng — không thể tự chặn chính mình.']);
        }

        BlockedIp::create($data);

        return back();
    }

    public function destroy(BlockedIp $blockedIp): RedirectResponse
    {
        $blockedIp->delete();

        return back();
    }
}
