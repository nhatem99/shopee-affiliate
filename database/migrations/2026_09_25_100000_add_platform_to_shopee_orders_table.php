<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bảng `shopee_orders` giờ chứa cả đơn TikTok Shop lấy qua API ACCESSTRADE.
     *
     * Vì sao DÙNG LẠI bảng này thay vì tạo bảng riêng: mọi thứ phía sau đơn hàng đều đã bám vào
     * nó — CashbackService::sync() cộng tiền, OrderHistoryController hiện lịch sử cho khách,
     * /admin/don-hang, và cả việc thu hồi tiền khi đơn bị huỷ. Tạo bảng thứ hai là phải nhân đôi
     * từng chỗ đó, và sớm muộn hai nhánh lệch nhau ở đúng khâu đụng tới tiền.
     *
     * Tên bảng thành ra không còn đúng nghĩa. Chấp nhận: đổi tên bảng đang chạy trên production
     * là một lượt downtime cộng với việc sửa hàng chục chỗ, đắt hơn nhiều so với một cái tên cũ.
     *
     * Khoá chống trùng phải kèm platform: order_id của hai sàn là hai không gian số khác nhau,
     * trùng nhau một lần là đơn sàn này ghi đè đơn sàn kia — mà cả hai đều đang gắn với tiền
     * của một khách thật.
     */
    public function up(): void
    {
        Schema::table('shopee_orders', function (Blueprint $table) {
            $table->string('platform', 16)->default('shopee')->after('id')->index();
        });

        Schema::table('shopee_orders', function (Blueprint $table) {
            $table->dropUnique('shopee_orders_line_unique');
            $table->unique(['platform', 'order_id', 'item_id', 'model_id'], 'shopee_orders_line_unique');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_orders', function (Blueprint $table) {
            $table->dropUnique('shopee_orders_line_unique');
            $table->unique(['order_id', 'item_id', 'model_id'], 'shopee_orders_line_unique');
        });

        Schema::table('shopee_orders', function (Blueprint $table) {
            $table->dropIndex(['platform']);
            $table->dropColumn('platform');
        });
    }
};
