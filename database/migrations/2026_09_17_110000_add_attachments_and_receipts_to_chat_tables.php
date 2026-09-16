<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phần 2 của chat hỗ trợ: gửi ảnh, "đã xem", "đang gõ" và gắn tin nhắn với một đơn hàng.
     */
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            // Đối xứng với user_read_at: mỗi bên biết bên kia đã đọc tới đâu để hiện "Đã xem".
            $table->timestamp('admin_read_at')->nullable()->after('user_read_at');

            // "Đang gõ". Không có route riêng nào ghi hai cột này: chính request poll (đằng nào
            // cũng chạy mỗi vài giây) mang theo ?typing=1 — xem ChatService::touchTyping.
            $table->timestamp('user_typing_at')->nullable()->after('admin_read_at');
            $table->timestamp('admin_typing_at')->nullable()->after('user_typing_at');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            // Tin nhắn chỉ có ảnh thì không có chữ nào — đúng cách người ta gửi ảnh chụp màn hình.
            $table->text('body')->nullable()->change();

            // Đường dẫn tương đối trên disk 'uploads' (public/uploads), không phải URL: đổi tên
            // miền hay chuyển sang S3 sau này thì không phải sửa lại dữ liệu cũ.
            $table->string('image_path')->nullable()->after('body');

            // Mã đơn Shopee mà tin nhắn này hỏi về — khách bấm "Hỏi về đơn này" ở /don-hang.
            // Cố ý KHÔNG khoá ngoại sang shopee_orders: một đơn là NHIỀU dòng ở bảng đó (mỗi sản
            // phẩm một dòng), nên không có một hàng nào để trỏ tới; đây là mã đơn dạng chuỗi.
            $table->string('order_id', 64)->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'order_id']);
            $table->text('body')->nullable(false)->change();
        });

        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropColumn(['admin_read_at', 'user_typing_at', 'admin_typing_at']);
        });
    }
};
