<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chat hỗ trợ giữa khách và admin (/ho-tro và /admin/chats).
     *
     * Mỗi khách CHỈ có một hội thoại duy nhất (unique trên user_id) — đây là kênh hỗ trợ, không
     * phải tin nhắn nhiều luồng: khách không cần chọn "mở ticket nào", admin không cần gộp.
     *
     * Hai cột đếm chưa đọc được lưu sẵn thay vì đếm lại mỗi lần: badge ở sidebar admin và ở menu
     * khách nằm trong HandleInertiaRequests, tức chạy trên MỌI full visit của mọi trang — đếm
     * bằng COUNT trên chat_messages ở đó là bắt cả site trả giá cho một con số nhỏ xíu.
     */
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Thứ tự của danh sách bên admin: hội thoại vừa có tin nằm trên cùng.
            $table->timestamp('last_message_at')->nullable()->index();

            $table->unsignedInteger('user_unread')->default(0);
            $table->unsignedInteger('admin_unread')->default(0);

            // Lần cuối khách MỞ trang chat. Dùng để biết khách có đang ngồi xem hay không mà
            // quyết định có bắn chuông thông báo khi admin trả lời — xem ChatService::sendFromAdmin.
            $table->timestamp('user_read_at')->nullable();

            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();

            // Ai gõ tin này. nullOnDelete vì tài khoản admin có thể bị xoá sau nhiều tháng, lúc
            // đó nội dung hội thoại vẫn phải đọc được — nên chiều "tin này của phía nào" nằm ở
            // cột from_admin riêng, không suy ra từ sender_id.
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('from_admin')->default(false);

            $table->text('body');
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
    }
};
