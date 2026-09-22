<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            // Phần thưởng hạng (điểm phần trăm) ĐÃ dùng để tính khoản tiền này.
            //
            // Phải đóng băng tại đây chứ không tra lại hạng hiện tại của khách mỗi lần chạy:
            // CashbackService::sync() tính lại TOÀN BỘ đơn ở mỗi lượt chạy, nên nếu đọc hạng
            // hiện tại thì khách vừa lên Kim cương là mọi khoản tiền cũ của họ tự phình lên theo
            // — tiền của quý trước bị trả theo đặc quyền của quý sau, và không ai phát hiện ra
            // vì chẳng có lỗi nào cả.
            //
            // NULL = khoản ghi từ trước khi có tính năng hạng; đọc ra là 0, giữ nguyên số cũ.
            $table->decimal('tier_bonus_rate', 5, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropColumn('tier_bonus_rate');
        });
    }
};
