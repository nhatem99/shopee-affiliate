<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Khoá tài khoản thay vì xoá: cột user_id của commissions/withdrawals/payout_accounts đều
     * cascadeOnDelete, nên xoá một user là xoá sạch lịch sử tiền của họ — không phục hồi được
     * và làm lệch mọi báo cáo đối soát về sau.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('banned_at')->nullable()->after('sub_id');
            $table->string('banned_reason', 255)->nullable()->after('banned_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['banned_at', 'banned_reason']);
        });
    }
};
