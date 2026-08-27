<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('affiliate-scan', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // Khoá theo email+IP (không chỉ IP) để tránh khoá nhầm cả dãy IP dùng chung NAT
        // khi chỉ một người gõ sai mật khẩu của chính mình.
        RateLimiter::for('login', function (Request $request) {
            $key = Str::lower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Chống SMS-bombing: khoá theo số điện thoại lẫn IP.
        RateLimiter::for('otp-send', function (Request $request) {
            $key = (string) $request->input('phone').'|'.$request->ip();

            return Limit::perMinutes(10, 3)->by($key);
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            $key = (string) $request->input('phone').'|'.$request->ip();

            return Limit::perMinutes(5, 5)->by($key);
        });

        RateLimiter::for('withdrawals', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
