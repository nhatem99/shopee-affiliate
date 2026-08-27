<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header bảo mật cho mọi response. CSP chỉ bật ở production vì Vite dev server
 * (module script + HMR websocket) chạy trên origin khác localhost/LAN, script-src 'self'
 * sẽ chặn luôn cả trang lúc dev.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(16);
        $request->attributes->set('csp_nonce', $nonce);

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (app()->environment('production')) {
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                // 'unsafe-inline' cho style vì Vue dùng nhiều binding :style — rủi ro CSS injection
                // thấp hơn nhiều so với việc nới script-src.
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com",
                // Ảnh sản phẩm đến từ CDN Shopee/Tiki hoặc bất kỳ site nào khách dán link (OpenGraph),
                // không thể giới hạn về một danh sách domain cố định.
                "img-src 'self' https: data:",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ]);

            $response->headers->set('Content-Security-Policy', $csp);
        }

        return $response;
    }
}
