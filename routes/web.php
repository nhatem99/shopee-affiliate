<?php

use App\Http\Controllers\Admin\ApiConfigController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\VoucherController;
use App\Http\Controllers\AffiliateController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Home
Route::get('/', function () {
    return Inertia::render('Home');
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

// Authenticated user routes
Route::middleware('auth')->group(function () {
    Route::get('/history', [AffiliateController::class, 'history'])->name('history');
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
});
