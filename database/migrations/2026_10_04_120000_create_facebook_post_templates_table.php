<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mẫu "bài tự soạn" admin lưu lại để đăng lặp lại (/admin/fb-posts): nội dung + ảnh. Ảnh dùng
     * chung file với bài đăng (FacebookPostImages::DIR) — FacebookPostImages::prune() không xoá
     * ảnh mẫu còn dùng.
     */
    public function up(): void
    {
        Schema::create('facebook_post_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('caption');
            $table->json('images')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_post_templates');
    }
};
