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

// Kéo đơn TikTok Shop từ ACCESSTRADE về rồi cộng tiền hoàn (xem AccessTradeOrderImportService).
//
// 2 giờ/lần, quét lại 90 NGÀY mỗi lượt. Con số 90 không phải cho rộng rãi — nó bắt buộc:
// tham số since/until của họ lọc theo NGÀY PHÁT SINH ĐƠN, trong khi cờ đối soát (is_confirmed,
// điều kiện duy nhất để tiền chảy vào ví) về theo KỲ, thường ở tháng kế tiếp. Quét 30 ngày thì
// đơn đặt đầu tháng đã rơi ra khỏi cửa sổ trước khi được xác nhận: dòng trong shopee_orders
// đứng nguyên 'pending' mãi mãi và khách không bao giờ nhận được đồng nào — không log, không
// lỗi, không ai biết. 90 ngày = 3 lát = 3 request mỗi lượt, vẫn thừa quota 30 request/phút.
// Lệnh còn tự đếm và kêu lên nếu vẫn có đơn treo ngoài cửa sổ.
Schedule::command('accesstrade:sync-orders', ['--days' => 90])
    ->everyTwoHours()
    ->withoutOverlapping()
    ->description('Đồng bộ đơn TikTok Shop (ACCESSTRADE) + cộng tiền hoàn')
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

// Lam moi kho Flash Sale. 15 phut/lan — day hon voucher (hourly) vi so suat con lai
// (amount) va suat da dong doi nhanh hon han ma giam gia, va nguon tra het du lieu
// trong MOT lan goi (khong phan trang) nen moi lan chi la mot request, khong tich luy
// nhu vong lap phan trang cua voucher.
Schedule::command('flashsale:sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->description('Đồng bộ kho sản phẩm Flash Sale (/flashsale)')
    ->storeOutput();
