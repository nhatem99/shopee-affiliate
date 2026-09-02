<?php

namespace App\Http\Middleware;

use App\Models\BlockedIp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockIps
{
    /**
     * Chặn hẳn các IP admin tự thêm ở /admin/blocked-ips — áp dụng cho MỌI request (kể cả
     * trang admin) trừ /admin/login để admin không tự khóa chính mình nếu lỡ chặn nhầm IP
     * đang dùng (vẫn đăng nhập lại được, chỉ là request khác bị chặn).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('admin.login', 'admin.login.store')) {
            return $next($request);
        }

        if (in_array($request->ip(), BlockedIp::activeIps(), true)) {
            abort(403);
        }

        return $next($request);
    }
}
