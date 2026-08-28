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
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Setting::getBool('maintenance_mode', false)) {
            return $next($request);
        }

        if ($request->routeIs('logout', 'admin.*')) {
            return $next($request);
        }

        return Inertia::render('Maintenance')->toResponse($request)->setStatusCode(503);
    }
}
