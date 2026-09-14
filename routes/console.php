<?php

use App\Models\VoucherRef;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dọn ref đã hết hạn (xem VoucherRef::prunable). Không dọn thì bảng chỉ phình chứ không sai —
// resolve() vẫn tự loại ref hết hạn.
Schedule::command('model:prune', ['--model' => [VoucherRef::class]])->daily();

// Đối soát slot reel với caption thật trên Facebook (xem FacebookReelSyncService). Không bật
// chế độ reel thì lệnh thoát ngay, không gọi API nào.
Schedule::command('facebook:sync-reels')->everyFiveMinutes()->withoutOverlapping();
