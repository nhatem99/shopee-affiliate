<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\ApiConfigController;
use App\Http\Controllers\Admin\BlockedIpController;
use App\Http\Controllers\Admin\ChatController as AdminChatController;
use App\Http\Controllers\Admin\ConsoleController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PromoContentController;
use App\Http\Controllers\Admin\SchedulerController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\ShopeeOrderController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\VoucherButtonConfigController;
use App\Http\Controllers\Admin\VoucherController;
use App\Http\Controllers\Admin\WithdrawalController as AdminWithdrawalController;
use App\Http\Controllers\AffiliateController;
use App\Http\Controllers\Auth\AdminLoginController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderHistoryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShopeeVoucherController;
use App\Http\Controllers\ShortLinkController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\VoucherCatalogController;
use App\Http\Controllers\WithdrawalController;
use App\Models\PlatformVoucher;
use App\Services\CashbackLeaderboardService;
use App\Services\CashbackService;
use App\Services\DailyCheckInService;
use App\Services\MembershipTierService;
use App\Services\TrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Home
Route::get('/', function (Request $request, TrackingService $tracking, CashbackService $cashback, CashbackLeaderboardService $leaderboard, MembershipTierService $tiers, DailyCheckInService $checkIn) {
    $tracking->log('page_view', $request, ['url' => $request->fullUrl()]);

    return Inertia::render('Home', [
        'vouchers' => PlatformVoucher::suggestedList(),
        // Công cụ lấy mã chỉ dùng được trên điện thoại — admin luôn xem/test được từ máy tính.
        'canUseVoucherTool' => TrackingService::isMobile($request->userAgent())
            || ($request->user()?->isAdmin() ?? false),
        // Bảng xếp hạng hoàn tiền tháng này. Chỉ tính khi chương trình đang bật: tắt rồi mà vẫn
        // truy vấn là tốn công cho một khối frontend đằng nào cũng ẩn (useCashback::cashbackOn).
        'leaderboard' => $cashback->rate() > 0 ? $leaderboard->forMonth($request->user()) : null,
        // Hạng thành viên & đặc quyền. null = chương trình tắt, khối ở frontend tự biến mất
        // (MembershipTierService::enabled đã gộp luôn điều kiện tỉ lệ hoàn tiền > 0).
        'membershipTiers' => $tiers->enabled() ? $tiers->publicTiers($request->user()) : null,
        // Điểm danh nhận quà. null = admin đã tắt, thẻ ở frontend tự biến mất. Đóng closure để
        // những lượt partial reload không xin tới nó (ví dụ reload riêng voucherResult sau mỗi lần
        // dán link) không phải chạy lại mấy truy vấn đếm kho quà.
        'dailyCheckIn' => fn () => $checkIn->enabled() ? $checkIn->state($request->user()) : null,
    ]);
})->name('home');

// Auth (guests only)
Route::middleware('guest')->group(function () {
    // /login là lối vào cho KHÁCH; tài khoản admin bị chặn ở đây và phải dùng /admin/login riêng.
    // Khi bật "Chế độ bảo trì", /login cũng bị chặn như mọi trang khác của khách.
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');

    // /admin/login là lối vào riêng, duy nhất của admin — tách khỏi /login của khách để
    // admin luôn tự vào được (kể cả khi bật "Chế độ bảo trì") và không lộ ra menu công khai.
    Route::get('/admin/login', [AdminLoginController::class, 'show'])->name('admin.login');
    Route::post('/admin/login', [AdminLoginController::class, 'store'])->middleware('throttle:login')->name('admin.login.store');

    // Đăng ký là lối tạo tài khoản KHÁCH mới — cái này tắt được.
    Route::middleware('customer.auth.enabled')->group(function () {
        Route::get('/register', [RegisterController::class, 'show'])->name('register');
        Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:register');
        Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])->name('google.redirect');
        Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');
    });
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');
// Thoát chế độ "xem như khách" của admin — chỉ cần auth vì lúc này phiên đang là khách, không
// qua được auth.admin; ImpersonationService tự kiểm tra id admin lưu trong session.
Route::post('/impersonate/leave', [ImpersonationController::class, 'stop'])->middleware('auth')->name('impersonate.leave');

// Affiliate scan (throttled, open to all)
// GET fallback: redirect home if user refreshes after a scan
Route::get('/affiliate/scan', fn () => redirect()->route('home'));
Route::post('/affiliate/scan', [AffiliateController::class, 'scan'])
    ->middleware('throttle:affiliate-scan')
    ->name('affiliate.scan');

// tietkiemvi.com public voucher tool — no auth required
// GET fallback: redirect home if user refreshes after a resolve
Route::get('/voucher/resolve', fn () => redirect()->route('home'));
Route::post('/voucher/resolve', [ShopeeVoucherController::class, 'resolve'])
    ->middleware('throttle:affiliate-scan')
    ->name('voucher.resolve');

// Short-link cloaking cho link affiliate: /go/{code} -> 302 -> link Shopee thật (mmp_pid của mình)
Route::post('/voucher/shorten', [ShortLinkController::class, 'store'])
    ->middleware('throttle:affiliate-scan')
    ->name('voucher.shorten');
Route::get('/go/{code}', [ShortLinkController::class, 'redirect'])->name('go');
// Chế độ mã YTB, bước 1: khách tự mở link YouTube của ganma (302 nguyên bản) để Shopee ghi
// nhận mã trên máy khách, rồi mới bấm Facebook/Mua ngay — xem ShortLinkController::activateYoutube.
Route::get('/ytb/{ref}', [ShortLinkController::class, 'activateYoutube'])->name('voucher.ytb');

// Theo dõi hành vi frontend (copy mã...), mở cho khách, chung throttle chống spam
Route::post('/track/event', [TrackingController::class, 'store'])
    ->middleware('throttle:affiliate-scan')
    ->name('track.event');

// Giải thích hoàn tiền — trang riêng để thanh điều hướng dưới và bài đăng trỏ tới được.
// Chương trình chưa bật (tỉ lệ = 0) thì không có gì để giải thích: đá về trang chủ thay vì
// hiện một trang nói về thứ hệ thống đang không trả đồng nào.
Route::get('/hoan-tien', function (Request $request, CashbackService $cashback, CashbackLeaderboardService $leaderboard, MembershipTierService $tiers) {
    abort_if($cashback->rate() <= 0, 404);

    return Inertia::render('Cashback', [
        'leaderboard' => $leaderboard->forMonth($request->user()),
        'membershipTiers' => $tiers->enabled() ? $tiers->publicTiers($request->user()) : null,
    ]);
})->name('cashback.info');

// Huong dan lay ma — trang cong khai, co URL rieng de dan vao bai dang Facebook/Zalo.
Route::get('/huong-dan', [GuideController::class, 'index'])->name('guide');

// Kho ma giam gia toan san — trang cong khai, khach xem ma khong can dan link san pham.
// Khong dat duoi 'auth': day la trang di SEO, phai mo cho ca khach vang lai va bo tim kiem.
Route::get('/ma-giam-gia', [VoucherCatalogController::class, 'index'])->name('vouchers.catalog');
// Nguon du lieu cho cuon vo tan. Throttle rong tay hon 'affiliate-scan' vi day chi la doc
// DB, khong goi sang ben thu ba — nhung van co tran de khong ai keo het bang bang mot vong lap.
Route::get('/api/vouchers', [VoucherCatalogController::class, 'feed'])
    ->middleware('throttle:60,1')
    ->name('vouchers.catalog.feed');

// Blog
Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');

// Authenticated user routes
Route::middleware('auth')->group(function () {
    Route::get('/history', [AffiliateController::class, 'history'])->name('history');
    // Lich su tung don mua kem so tien hoan cua don do.
    Route::get('/don-hang', [OrderHistoryController::class, 'index'])->name('orders');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
    Route::patch('/profile', [ProfileController::class, 'updateProfile'])->name('profile.update');
    Route::get('/profile/thong-tin', [ProfileController::class, 'info'])->name('profile.info');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar');
    Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');
    Route::post('/profile/payout', [ProfileController::class, 'storePayoutAccount'])->name('profile.payout');
    Route::get('/profile/mat-khau', [ProfileController::class, 'password'])->name('profile.password.edit');
    Route::post('/profile/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:login')->name('profile.password');
    Route::post('/withdrawals', [WithdrawalController::class, 'store'])->middleware('throttle:withdrawals')->name('withdrawals.store');
    Route::get('/vi/lich-su', [ProfileController::class, 'walletHistory'])->name('wallet.history');
    // Chat hỗ trợ với admin. Poll bằng chính route index (router.reload only:['messages']) nên
    // không có route "lấy tin mới" riêng — xem ChatService.
    Route::get('/ho-tro', [ChatController::class, 'index'])->name('support');
    Route::post('/ho-tro/gui', [ChatController::class, 'store'])->middleware('throttle:chat')->name('support.send');
    // Điểm danh nhận quà hằng ngày — thẻ nằm trên trang chủ, bấm xong quay về chính trang đó.
    // Throttle theo user chứ không theo IP: cả nhà dùng chung một mạng là chung một IP.
    Route::post('/diem-danh', [CheckInController::class, 'store'])
        ->middleware('throttle:checkin')
        ->name('checkin.store');
    Route::get('/thong-bao', [NotificationController::class, 'index'])->name('notifications');
    Route::post('/thong-bao/doc-het', [NotificationController::class, 'readAll'])->name('notifications.readAll');
    Route::post('/thong-bao/{id}/doc', [NotificationController::class, 'read'])->name('notifications.read');
});

// Admin routes
Route::middleware(['auth', 'auth.admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/activities', [ActivityController::class, 'index'])->name('activities');
    Route::get('/activities/export', [ActivityController::class, 'export'])->name('activities.export');
    Route::post('/activities/prune-bots', [ActivityController::class, 'pruneBots'])->name('activities.prune-bots');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders');
    Route::patch('/orders/{commission}', [OrderController::class, 'update'])->name('orders.update');
    Route::get('/shopee-orders', [ShopeeOrderController::class, 'index'])->name('shopee-orders');
    Route::post('/shopee-orders/import', [ShopeeOrderController::class, 'import'])->name('shopee-orders.import');
    Route::get('/api-config', [ApiConfigController::class, 'index'])->name('api-config');
    Route::post('/api-config', [ApiConfigController::class, 'store'])->name('api-config.store');
    Route::post('/api-config/{config}/test', [ApiConfigController::class, 'test'])->name('api-config.test');
    Route::get('/api-config/{config}/facebook-posts', [ApiConfigController::class, 'facebookPosts'])->name('api-config.facebook-posts');
    Route::post('/api-config/{config}/probe-comment', [ApiConfigController::class, 'probeComment'])->name('api-config.probe-comment');
    Route::delete('/api-config/{config}/probe-comment', [ApiConfigController::class, 'deleteProbeComment'])->name('api-config.probe-comment.delete');
    Route::get('/vouchers', [VoucherController::class, 'index'])->name('vouchers');
    Route::post('/vouchers', [VoucherController::class, 'store'])->name('vouchers.store');
    Route::patch('/vouchers/{voucher}', [VoucherController::class, 'update'])->name('vouchers.update');
    Route::delete('/vouchers/{voucher}', [VoucherController::class, 'destroy'])->name('vouchers.destroy');
    Route::get('/withdrawals', [AdminWithdrawalController::class, 'index'])->name('withdrawals');
    Route::patch('/withdrawals/{withdrawal}', [AdminWithdrawalController::class, 'update'])->name('withdrawals.update');
    // Quan ly tai khoan khach: tim kiem, sua thong tin, doi quyen, dat lai mat khau, khoa/mo.
    Route::get('/users', [AdminUserController::class, 'index'])->name('users');
    Route::patch('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
    Route::patch('/users/{user}/role', [AdminUserController::class, 'updateRole'])->name('users.role');
    Route::post('/users/{user}/password', [AdminUserController::class, 'resetPassword'])->name('users.password');
    Route::post('/users/{user}/ban', [AdminUserController::class, 'ban'])->name('users.ban');
    Route::delete('/users/{user}/ban', [AdminUserController::class, 'unban'])->name('users.unban');
    // Admin nhảy vào tài khoản khách để xem đúng những gì khách thấy. Lối thoát là /impersonate/leave ở trên, cạnh /logout.
    Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'start'])->name('users.impersonate');
    // Hộp thư hỗ trợ: khách nhắn ở /ho-tro, admin đọc và trả lời ở đây.
    Route::get('/chats', [AdminChatController::class, 'index'])->name('chats');
    // Badge "có khách đang chờ" ở sidebar, AdminLayout gọi lại mỗi 30 giây. Là JSON chứ không
    // phải partial reload của Inertia: reload chạy LẠI controller của trang đang mở, tức mở
    // /admin/dashboard là cứ 30 giây chạy lại toàn bộ thống kê chỉ để lấy một con số.
    // Phải khai trước /chats/{conversation}, không thì 'unread' bị bắt làm id hội thoại.
    Route::get('/chats/unread', [AdminChatController::class, 'unread'])->name('chats.unread');
    Route::get('/chats/{conversation}', [AdminChatController::class, 'show'])->name('chats.show');
    Route::post('/chats/{conversation}/reply', [AdminChatController::class, 'reply'])->middleware('throttle:chat')->name('chats.reply');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/guide-video', [SettingsController::class, 'updateGuideVideo'])->name('settings.guide-video');
    Route::delete('/settings/guide-video', [SettingsController::class, 'destroyGuideVideo'])->name('settings.guide-video.destroy');
    // Kho mẫu bài đăng để admin copy đi giới thiệu web (nhóm Facebook, Zalo, TikTok...).
    Route::get('/promo', [PromoContentController::class, 'index'])->name('promo');
    Route::post('/promo', [PromoContentController::class, 'update'])->name('promo.update');
    Route::get('/blocked-ips', [BlockedIpController::class, 'index'])->name('blocked-ips');
    Route::post('/blocked-ips', [BlockedIpController::class, 'store'])->name('blocked-ips.store');
    Route::delete('/blocked-ips/{blockedIp}', [BlockedIpController::class, 'destroy'])->name('blocked-ips.destroy');
    // Xem log lỗi production ngay trên web thay vì phải SSH lên server đọc storage/logs.
    Route::get('/logs', [LogController::class, 'index'])->name('logs');
    // Scheduler: cron có chạy không, job nào chạy lúc nào, bấm chạy ngay — cũng để khỏi SSH.
    Route::get('/scheduler', [SchedulerController::class, 'index'])->name('scheduler');
    Route::post('/scheduler/run', [SchedulerController::class, 'run'])->name('scheduler.run');
    Route::post('/scheduler/reel-caption-probe', [SchedulerController::class, 'probeReelCaption'])->name('scheduler.reel-probe');
    Route::post('/scheduler/reel-caption-restore', [SchedulerController::class, 'restoreReelCaption'])->name('scheduler.reel-restore');
    // Dán lệnh artisan chạy ngay trên web (chỉ lệnh trong danh sách cho phép) — cũng để khỏi SSH.
    Route::get('/console', [ConsoleController::class, 'index'])->name('console');
    Route::post('/console/run', [ConsoleController::class, 'run'])->name('console.run');
    Route::get('/voucher-buttons', [VoucherButtonConfigController::class, 'index'])->name('voucher-buttons');
    Route::patch('/voucher-buttons/{voucherButtonConfig}', [VoucherButtonConfigController::class, 'update'])->name('voucher-buttons.update');
});
