<?php

use App\Http\Controllers\Admin\FacebookGroupController;
use App\Http\Controllers\Admin\FacebookGroupPostController;
use App\Http\Controllers\Admin\FacebookPostTemplateController;
use App\Http\Controllers\Admin\FacebookProfileController;
use App\Http\Controllers\FacebookRunnerController;
use App\Http\Middleware\VerifyFacebookRunnerToken;
use App\Services\FacebookPostImages;
use Illuminate\Support\Facades\Route;

/*
| Đăng deal vào nhóm Facebook bằng bot trình duyệt trên máy nhà (deploy/fb-group-runner).
| Nạp từ bootstrap/app.php (withRouting then:) — tách khỏi routes/web.php cho gọn một chỗ.
*/

// Bot hỏi việc / báo kết quả: ngoài nhóm web (không CSRF, session, GeoBlock), xác thực bằng
// token. Bot hỏi tối đa vài lần mỗi phút, mỗi bài tải thêm tối đa 5 ảnh — 60/phút là dư.
Route::prefix('runner/fb')
    ->name('runner.fb.')
    ->middleware(['throttle:60,1', VerifyFacebookRunnerToken::class])
    ->group(function () {
        Route::post('/poll', [FacebookRunnerController::class, 'poll'])->name('poll');
        Route::post('/posts/{id}/result', [FacebookRunnerController::class, 'result'])->whereNumber('id')->name('result');
        Route::post('/posts/{id}/comment', [FacebookRunnerController::class, 'comment'])->whereNumber('id')->name('comment');
        Route::post('/groups', [FacebookRunnerController::class, 'groups'])->name('groups');
        Route::post('/groups/{id}/review', [FacebookRunnerController::class, 'review'])->whereNumber('id')->name('review');
        Route::get('/images/{name}', [FacebookRunnerController::class, 'image'])->where('name', FacebookPostImages::NAME_PATTERN)->name('image');
    });

Route::middleware(['web', 'auth', 'auth.admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/fb-groups', [FacebookGroupController::class, 'index'])->name('fb-groups');
    Route::post('/fb-groups/settings', [FacebookGroupController::class, 'updateSettings'])->name('fb-groups.settings');
    Route::post('/fb-groups/token', [FacebookGroupController::class, 'regenerateToken'])->name('fb-groups.token');
    Route::post('/fb-groups/pause', [FacebookGroupController::class, 'pause'])->name('fb-groups.pause');
    Route::post('/fb-groups/clear-wait', [FacebookGroupController::class, 'clearWait'])->name('fb-groups.clear-wait');
    Route::post('/fb-groups/sync', [FacebookGroupController::class, 'requestSync'])->name('fb-groups.sync');
    Route::post('/fb-groups/review', [FacebookGroupController::class, 'requestReview'])->name('fb-groups.review');
    Route::post('/fb-groups/profiles', [FacebookProfileController::class, 'store'])->name('fb-profiles.store');
    Route::patch('/fb-groups/profiles/{facebookProfile}', [FacebookProfileController::class, 'update'])->name('fb-profiles.update');
    Route::post('/fb-groups/profiles/{facebookProfile}/move', [FacebookProfileController::class, 'move'])->name('fb-profiles.move');
    Route::post('/fb-groups/profiles/{facebookProfile}/unblock', [FacebookProfileController::class, 'unblock'])->name('fb-profiles.unblock');
    Route::delete('/fb-groups/profiles/{facebookProfile}', [FacebookProfileController::class, 'destroy'])->name('fb-profiles.destroy');
    Route::post('/fb-groups', [FacebookGroupController::class, 'store'])->name('fb-groups.store');
    Route::patch('/fb-groups/{facebookGroup}', [FacebookGroupController::class, 'update'])->name('fb-groups.update');
    Route::delete('/fb-groups/{facebookGroup}', [FacebookGroupController::class, 'destroy'])->name('fb-groups.destroy');

    Route::get('/fb-posts', [FacebookGroupPostController::class, 'index'])->name('fb-posts');
    Route::post('/fb-posts/compose', [FacebookGroupPostController::class, 'compose'])->name('fb-posts.compose');
    Route::post('/fb-posts/images', [FacebookGroupPostController::class, 'uploadImage'])->name('fb-posts.images');
    Route::get('/fb-posts/images/{name}', [FacebookGroupPostController::class, 'image'])->where('name', FacebookPostImages::NAME_PATTERN)->name('fb-posts.image');
    Route::post('/fb-posts/templates', [FacebookPostTemplateController::class, 'store'])->name('fb-posts.templates.store');
    Route::put('/fb-posts/templates/{facebookPostTemplate}', [FacebookPostTemplateController::class, 'update'])->name('fb-posts.templates.update');
    Route::delete('/fb-posts/templates/{facebookPostTemplate}', [FacebookPostTemplateController::class, 'destroy'])->name('fb-posts.templates.destroy');
    Route::post('/fb-posts', [FacebookGroupPostController::class, 'store'])->name('fb-posts.store');
    Route::post('/fb-posts/{facebookGroupPost}/cancel', [FacebookGroupPostController::class, 'cancel'])->name('fb-posts.cancel');
    Route::post('/fb-posts/{facebookGroupPost}/retry', [FacebookGroupPostController::class, 'retry'])->name('fb-posts.retry');
});
