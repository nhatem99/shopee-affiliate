<?php

use App\Http\Controllers\Admin\ApiConfigController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\VoucherController;
use App\Http\Controllers\Admin\WithdrawalController as AdminWithdrawalController;
use App\Http\Controllers\AffiliateController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShopeeVoucherController;
use App\Http\Controllers\ShortLinkController;
use App\Http\Controllers\WithdrawalController;
use App\Models\PlatformVoucher;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Home
Route::get('/', function () {
    return Inertia::render('Home', [
        'vouchers' => PlatformVoucher::suggestedList(),
    ]);
})->name('home');

// Auth (guests only)
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
    Route::post('/auth/otp/send', [OtpController::class, 'send'])->name('otp.send');
    Route::post('/auth/otp/verify', [OtpController::class, 'verify'])->name('otp.verify');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// Affiliate scan (throttled, open to all)
// GET fallback: redirect home if user refreshes after a scan
Route::get('/affiliate/scan', fn () => redirect()->route('home'));
Route::post('/affiliate/scan', [AffiliateController::class, 'scan'])
    ->middleware('throttle:affiliate-scan')
    ->name('affiliate.scan');

// sanvoucher.vn public voucher tool — no auth required
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

// Blog
Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');

// Authenticated user routes
Route::middleware('auth')->group(function () {
    Route::get('/history', [AffiliateController::class, 'history'])->name('history');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
    Route::patch('/profile', [ProfileController::class, 'updateProfile'])->name('profile.update');
    Route::post('/profile/payout', [ProfileController::class, 'storePayoutAccount'])->name('profile.payout');
    Route::post('/withdrawals', [WithdrawalController::class, 'store'])->name('withdrawals.store');
});

// Admin routes
Route::middleware(['auth', 'auth.admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders');
    Route::patch('/orders/{commission}', [OrderController::class, 'update'])->name('orders.update');
    Route::get('/api-config', [ApiConfigController::class, 'index'])->name('api-config');
    Route::post('/api-config', [ApiConfigController::class, 'store'])->name('api-config.store');
    Route::post('/api-config/{config}/test', [ApiConfigController::class, 'test'])->name('api-config.test');
    Route::get('/vouchers', [VoucherController::class, 'index'])->name('vouchers');
    Route::post('/vouchers', [VoucherController::class, 'store'])->name('vouchers.store');
    Route::patch('/vouchers/{voucher}', [VoucherController::class, 'update'])->name('vouchers.update');
    Route::delete('/vouchers/{voucher}', [VoucherController::class, 'destroy'])->name('vouchers.destroy');
    Route::get('/withdrawals', [AdminWithdrawalController::class, 'index'])->name('withdrawals');
    Route::patch('/withdrawals/{withdrawal}', [AdminWithdrawalController::class, 'update'])->name('withdrawals.update');
});
