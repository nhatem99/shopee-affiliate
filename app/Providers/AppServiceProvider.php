<?php

namespace App\Providers;

use App\Listeners\ScheduledTaskRecorder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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

        RateLimiter::for('withdrawals', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Ghi lịch sử chạy của mọi job theo lịch vào scheduled_task_runs (xem /admin/scheduler).
        // Một instance dùng chung cho cả ba event để nối được starting → finished của cùng job.
        $recorder = new ScheduledTaskRecorder;
        Event::listen(ScheduledTaskStarting::class, [$recorder, 'starting']);
        Event::listen(ScheduledTaskFinished::class, [$recorder, 'finished']);
        Event::listen(ScheduledTaskFailed::class, [$recorder, 'failed']);
    }
}
