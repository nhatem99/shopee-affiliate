<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Lần cuối tài khoản gửi request khi đã đăng nhập — TouchLastSeen ghi, thưa 5 phút/lần.
            $table->timestamp('last_seen_at')->nullable()->after('banned_reason');
        });

        // Lấp giá trị ban đầu từ nhật ký hoạt động để cột không trống toàn bộ với khách cũ.
        DB::statement('UPDATE users SET last_seen_at = (SELECT MAX(created_at) FROM user_activities WHERE user_activities.user_id = users.id)');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
