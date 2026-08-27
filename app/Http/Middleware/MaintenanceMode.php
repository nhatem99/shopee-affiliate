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
     * Chặn toàn bộ trang cho khách khi admin bật "Chế độ bảo trì" — /login và khu vực
     * admin vẫn mở để admin luôn tự tắt lại được, không bị khoá luôn chính mình.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Setting::getBool('maintenance_mode', false)) {
            return $next($request);
        }

        if ($request->routeIs('login', 'logout', 'admin.*')) {
            return $next($request);
        }

        return Inertia::render('Maintenance')->toResponse($request)->setStatusCode(503);
    }
}
