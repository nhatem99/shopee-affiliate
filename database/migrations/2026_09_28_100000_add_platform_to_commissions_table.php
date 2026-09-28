<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sổ hoa hồng phải biết đơn đến từ sàn nào — song song với việc đã làm cho `shopee_orders`.
     *
     * Vì sao bắt buộc chứ không phải cho đẹp: `order_id` đang là UNIQUE TOÀN CỤC. Shopee và
     * TikTok Shop đánh số đơn trong hai không gian hoàn toàn khác nhau, không có gì bảo đảm
     * không trùng. Trùng một lần thì đơn của sàn sau hoặc không ghi được hoa hồng, hoặc tệ hơn:
     * award() tìm thấy bản ghi cũ theo order_id rồi CỘNG TIỀN CỦA KHÁCH NÀY VÀO KHOẢN CỦA KHÁCH
     * KHÁC. Không có gì báo lỗi cả — sổ vẫn cân, chỉ là cân sai người.
     *
     * Mặc định 'shopee' cho mọi dòng cũ: trước hôm nay toàn bộ hoa hồng đều đến từ Shopee.
     */
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->string('platform', 16)->default('shopee')->after('order_id')->index();
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->dropUnique('commissions_order_id_unique');
            $table->unique(['platform', 'order_id'], 'commissions_order_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropUnique('commissions_order_id_unique');
            $table->unique('order_id', 'commissions_order_id_unique');
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->dropIndex(['platform']);
            $table->dropColumn('platform');
        });
    }
};
