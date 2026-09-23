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

// Xét hạng thành viên theo tiền hoàn quý trước (xem MembershipTierService). Hạng chỉ ĐỔI khi
// sang quý mới, nhưng lệnh chạy hàng ngày vì hoa hồng của quý trước còn về muộn theo từng đợt
// nhập báo cáo — lý do đầy đủ nằm ở đầu lớp RefreshMembershipTiers.
Schedule::command('tiers:refresh')
    ->dailyAt('00:20')
    ->withoutOverlapping()
    ->description('Xét hạng thành viên theo tiền hoàn quý trước')
    ->storeOutput();

// Gọi thử nguồn kieushopee, tự bật chế độ bảo trì khi nó chết (xem SourceHealthService). Công
// tắc ở Admin > Cài đặt đang tắt thì lệnh thoát ngay, không gọi sang nguồn.
//
// 5 phút/lần: nguồn chết là khách vẫn dán link như thường và không có dấu hiệu gì, nên khoảng
// mù càng ngắn càng tốt; đổi lại phải lỗi 2 lượt liên tiếp mới đóng trang (~10 phút) để một
// nhịp timeout lẻ không đá khách ra giữa chừng.
Schedule::command('kieushopee:health')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->description('Kiểm tra nguồn kieushopee, tự bật/tắt bảo trì')
    ->storeOutput();

// Làm mới kho mã của trang /ma-giam-gia. Mỗi giờ: mã toàn sàn đổi theo ngày chứ không theo
// phút, nhưng cột "đã dùng %" thì nhích liên tục — để quá thưa là khách bấm vào mã mà nguồn
// đang ghi 60% thực tế đã hết lượt. Nguồn là API của website khác nên cũng không nên gọi dày.
Schedule::command('vouchers:sync')
    ->hourly()
    ->withoutOverlapping()
    ->description('Đồng bộ kho mã giảm giá toàn sàn (/ma-giam-gia)')
    ->storeOutput();
