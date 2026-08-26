<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerAuthEnabled
{
    /**
     * Chặn /login, /register và OTP khi admin đã tắt đăng nhập/đăng ký cho khách —
     * tránh khách gõ thẳng URL để lách qua giao diện đã ẩn.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Setting::getBool('customer_auth_enabled', true)) {
            return redirect()->route('home');
        }

        return $next($request);
    }
}
