<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lịch sử chạy của từng job theo lịch (routes/console.php) — mỗi lần chạy một dòng, dù do cron
 * gọi hay admin bấm "Chạy ngay" ở /admin/scheduler.
 *
 * Không có bảng này thì admin chỉ biết job "đáng lẽ" chạy 5 phút/lần chứ không biết nó có chạy
 * thật không, chạy xong có lỗi không, in ra gì — muốn biết phải SSH lên server. Xem
 * SchedulerService và ScheduledTaskRecorder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            // Tên lệnh artisan (vd "facebook:sync-reels") — khoá để tra "lần chạy cuối".
            $table->string('command', 191)->index();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->integer('exit_code')->nullable();
            // Output rút gọn (vài KB cuối) — đủ để thấy bảng kết quả hay dòng lỗi.
            $table->text('output')->nullable();
            // 'schedule' (cron gọi) hoặc 'admin:{user_id}' (bấm Chạy ngay).
            $table->string('triggered_by', 32);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
