<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bảng chuẩn của Laravel database notifications — User đã dùng trait Notifiable từ đầu
     * nhưng chưa có chỗ chứa. Thông báo trong app (thưởng người mới, rút tiền được duyệt...)
     * đều ghi vào đây; cột `data` là JSON {title, body, url}.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
