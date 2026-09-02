<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\BlockIps;
use App\Http\Middleware\EnsureCustomerAuthEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\MaintenanceMode;
use App\Http\Middleware\SecurityHeaders;
use App\Services\TrackingService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            BlockIps::class,
            MaintenanceMode::class,
        ]);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'auth.admin' => AdminMiddleware::class,
            'customer.auth.enabled' => EnsureCustomerAuthEnabled::class,
        ]);
        // Chỉ tin cậy Nginx chạy trên CHÍNH VPS này (proxy tới php-fpm qua localhost) —
        // trước đây dùng at: '*' (tin mọi nguồn) khiến bất kỳ ai cũng tự đặt được header
        // X-Forwarded-For để giả IP (ảnh hưởng rate limiting + tracking theo IP sai lệch).
        $middleware->trustProxies(at: ['127.0.0.1', '::1'], headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->report(function (ThrottleRequestsException $e) {
            $request = request();
            app(TrackingService::class)->logSecurityEvent('rate_limited', $request, [
                'metadata' => ['path' => $request->path()],
            ]);
        });
    })->create();
