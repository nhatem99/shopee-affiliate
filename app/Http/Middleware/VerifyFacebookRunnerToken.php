<?php

namespace App\Http\Middleware;

use App\Services\FacebookGroupRunnerSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chỉ bot đăng nhóm Facebook trên máy nhà (có token tạo ở /admin/fb-groups) gọi được
 * /runner/fb/*. Ai nắm token là khiến được nick Facebook cá nhân đăng bài — so sánh hằng thời
 * gian, và chưa tạo token thì đóng hẳn chứ không mở "không khoá".
 */
class VerifyFacebookRunnerToken
{
    public function __construct(private FacebookGroupRunnerSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $this->settings->token();

        if ($token === '' || ! hash_equals($token, (string) $request->header('X-Runner-Token'))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
