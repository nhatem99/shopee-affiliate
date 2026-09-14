<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Thưởng người mới đi chung bảng với hoa hồng: số dư ví = tổng commission approved trừ
     * tiền đang giữ (User::availableBalance), nên ghi thưởng vào đây là tiền tự vào ví mà không
     * phải sửa cách tính. Cột `type` để phân biệt: bảng xếp hạng, điều kiện rút tiền và trang
     * đơn hàng chỉ đếm 'cashback'; 'welcome_bonus' chỉ hiện ở ví.
     */
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->string('type', 20)->default('cashback')->after('affiliate_link_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
