<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Đăng nhập bằng OTP đã bị gỡ (OtpController + 2 route /auth/otp/* + tab trên Login.vue),
     * nên hai cột này không còn ai đọc hay ghi. Giữ lại chỉ tổ để một cột chứa hash treo lơ
     * lửng trên bảng users mà không ai nhớ nó dùng để làm gì.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['otp', 'otp_expires_at']);
        });
    }

    /**
     * Dựng lại đúng kiểu cột như 2026_06_15_073333_add_fields_to_users_table đã tạo.
     *
     * Vị trí đặt sau `sub_id` chứ không phải `wallet_balance` như bản gốc: cột sub_id được chèn
     * vào giữa hai thứ đó ở migration liền trước, và rollback chạy ngược nên lúc down() này
     * chạy thì sub_id chắc chắn còn đó.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('otp', 10)->nullable()->after('sub_id');
            $table->timestamp('otp_expires_at')->nullable()->after('otp');
        });
    }
};
