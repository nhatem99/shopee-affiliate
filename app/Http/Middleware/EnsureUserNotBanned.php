<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserNotBanned
{
    /**
     * Đá phiên đăng nhập của tài khoản vừa bị khoá.
     *
     * Đặt ở tầng middleware chứ không chỉ ở màn hình đăng nhập: khoá một tài khoản đang online
     * mà không cắt phiên hiện tại thì lệnh khoá gần như vô nghĩa — họ vẫn quét link, vẫn gửi
     * lệnh rút cho tới khi tự đăng xuất (hoặc tới khi hết hạn "remember me", có thể hàng tháng).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->isBanned()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'Tài khoản của bạn đã bị khoá.');
        }

        return $next($request);
    }
}
