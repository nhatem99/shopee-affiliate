<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function __construct(private ImpersonationService $impersonation) {}

    /** Admin nhảy vào tài khoản khách — đưa thẳng ra trang chủ như khách vừa đăng nhập. */
    public function start(Request $request, User $user): RedirectResponse
    {
        $this->impersonation->start($request, $user);

        return redirect()->route('home');
    }

    /**
     * Thoát về admin. Route này nằm NGOÀI nhóm auth.admin: lúc bấm, request->user() đang là
     * khách nên AdminMiddleware sẽ chặn — quyền được xác minh qua id admin trong session.
     */
    public function stop(Request $request): RedirectResponse
    {
        $admin = $this->impersonation->stop($request);

        if (! $admin) {
            return redirect()->route('home');
        }

        return redirect()->route('admin.users')->with('success', 'Đã quay về tài khoản quản trị.');
    }
}
