<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class MaintenanceMode
{
    /**
     * Chặn toàn bộ trang cho khách khi admin bật "Chế độ bảo trì" — kể cả /login của khách.
     * Chỉ /admin/login và khu vực admin (admin.*) vẫn mở để admin luôn tự vào tắt lại được,
     * không bị khoá luôn chính mình. /logout cũng mở để ai đang đăng nhập vẫn thoát ra được.
     *
     * Admin đã đăng nhập thì đi qua HẾT, kể cả trang khách: bảo trì thường được bật đúng lúc
     * cần kiểm tra xem tìm mã / mở link còn chạy không, mà những luồng đó chỉ tồn tại ở phía
     * khách. Khách vẫn thấy trang bảo trì như cũ.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Setting::getBool('maintenance_mode', false)) {
            return $next($request);
        }

        if ($request->routeIs('logout', 'admin.*') || ($request->user()?->isAdmin() ?? false)) {
            return $next($request);
        }

        return Inertia::render('Maintenance')->toResponse($request)->setStatusCode(503);
    }
}
