<?php

use App\Http\Controllers\Admin\FacebookGroupController;
use App\Http\Controllers\Admin\FacebookGroupPostController;
use App\Http\Controllers\FacebookRunnerController;
use App\Http\Middleware\VerifyFacebookRunnerToken;
use Illuminate\Support\Facades\Route;

/*
| Đăng deal vào nhóm Facebook bằng bot trình duyệt trên máy nhà (deploy/fb-group-runner).
| Nạp từ bootstrap/app.php (withRouting then:) — tách khỏi routes/web.php cho gọn một chỗ.
*/

// Bot hỏi việc / báo kết quả: ngoài nhóm web (không CSRF, session, GeoBlock), xác thực bằng
// token. Bot hỏi tối đa vài lần mỗi phút — 60/phút là dư.
Route::prefix('runner/fb')
    ->name('runner.fb.')
    ->middleware(['throttle:60,1', VerifyFacebookRunnerToken::class])
    ->group(function () {
        Route::post('/poll', [FacebookRunnerController::class, 'poll'])->name('poll');
        Route::post('/posts/{id}/result', [FacebookRunnerController::class, 'result'])->whereNumber('id')->name('result');
        Route::post('/groups', [FacebookRunnerController::class, 'groups'])->name('groups');
    });

Route::middleware(['web', 'auth', 'auth.admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/fb-groups', [FacebookGroupController::class, 'index'])->name('fb-groups');
    Route::post('/fb-groups/settings', [FacebookGroupController::class, 'updateSettings'])->name('fb-groups.settings');
    Route::post('/fb-groups/token', [FacebookGroupController::class, 'regenerateToken'])->name('fb-groups.token');
    Route::post('/fb-groups/pause', [FacebookGroupController::class, 'pause'])->name('fb-groups.pause');
    Route::post('/fb-groups/clear-wait', [FacebookGroupController::class, 'clearWait'])->name('fb-groups.clear-wait');
    Route::post('/fb-groups/sync', [FacebookGroupController::class, 'requestSync'])->name('fb-groups.sync');
    Route::post('/fb-groups', [FacebookGroupController::class, 'store'])->name('fb-groups.store');
    Route::patch('/fb-groups/{facebookGroup}', [FacebookGroupController::class, 'update'])->name('fb-groups.update');
    Route::delete('/fb-groups/{facebookGroup}', [FacebookGroupController::class, 'destroy'])->name('fb-groups.destroy');

    Route::get('/fb-posts', [FacebookGroupPostController::class, 'index'])->name('fb-posts');
    Route::post('/fb-posts/compose', [FacebookGroupPostController::class, 'compose'])->name('fb-posts.compose');
    Route::post('/fb-posts', [FacebookGroupPostController::class, 'store'])->name('fb-posts.store');
    Route::post('/fb-posts/{facebookGroupPost}/cancel', [FacebookGroupPostController::class, 'cancel'])->name('fb-posts.cancel');
    Route::post('/fb-posts/{facebookGroupPost}/retry', [FacebookGroupPostController::class, 'retry'])->name('fb-posts.retry');
});
