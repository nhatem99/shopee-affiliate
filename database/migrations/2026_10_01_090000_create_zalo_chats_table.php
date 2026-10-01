<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zalo Bot không có API liệt kê người đã nhắn cho bot, mà bot lại chỉ gửi được tới chat_id
     * đã từng nhắn — nên phải tự ghi lại mọi chat_id đi qua webhook.
     *
     * zalo_webhook_messages chỉ để chống xử lý trùng: Zalo có thể gửi lại cùng một tin khi
     * lần trước mình trả lời chậm. Unique trên message_id chặn ở tầng DB, không dựa vào
     * Cache vì mỗi lần deploy chạy optimize:clear.
     */
    public function up(): void
    {
        Schema::create('zalo_chats', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id', 64)->unique();
            $table->string('chat_type', 20)->nullable();
            $table->string('display_name')->nullable();

            // Gắn với tài khoản web ở giai đoạn 2 (khách gõ mã liên kết cho bot).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('zalo_webhook_messages', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 64)->unique();
            $table->string('chat_id', 64);
            $table->string('event_name', 64);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zalo_webhook_messages');
        Schema::dropIfExists('zalo_chats');
    }
};
