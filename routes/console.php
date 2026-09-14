<?php

use App\Models\Setting;
use App\Models\VoucherRef;
use App\Services\SchedulerService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nhịp tim: bằng chứng duy nhất scheduler thật sự được cron gọi. /admin/scheduler đọc giá trị
// này để báo "đang chạy" hay "server chưa có cron" — xem SchedulerService::status().
Schedule::call(fn () => Setting::set(SchedulerService::HEARTBEAT_KEY, now()->toIso8601String()))
    ->everyMinute()
    ->name('scheduler-heartbeat');

// Dọn ref đã hết hạn (xem VoucherRef::prunable). Không dọn thì bảng chỉ phình chứ không sai —
// resolve() vẫn tự loại ref hết hạn.
Schedule::command('model:prune', ['--model' => [VoucherRef::class]])
    ->daily()
    ->description('Dọn voucher_ref đã hết hạn (7 ngày)')
    ->storeOutput();

// Đối soát slot reel với caption thật trên Facebook (xem FacebookReelSyncService). Không bật
// chế độ reel thì lệnh thoát ngay, không gọi API nào. storeOutput() để bảng kết quả của lệnh
// được ghi lại và xem được ở /admin/scheduler (ScheduledTaskRecorder).
Schedule::command('facebook:sync-reels')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->description('Đối soát caption reel Facebook với bảng slot')
    ->storeOutput();
