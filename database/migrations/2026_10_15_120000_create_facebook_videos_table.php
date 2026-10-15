<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reel đăng lên fanpage từ video sản phẩm Shopee (/admin/fb-videos): admin dán link, bot điện
     * thoại mở Shopee lấy link video (server bị Shopee chặn), server tải về, cắt đầu/cuối, dựng
     * khung 9:16 có chữ rồi đăng Reel qua Graph API. Bot không lấy được thì admin tải video lên tay.
     */
    public function up(): void
    {
        Schema::create('facebook_videos', function (Blueprint $table) {
            $table->id();
            $table->string('shopee_url', 2000);
            $table->string('status', 20)->index();
            // Thông tin sản phẩm (tên, ảnh, giá) lúc tạo link — để soạn caption và chữ trên video.
            $table->json('product')->nullable();
            $table->foreignId('short_link_id')->nullable()->constrained()->nullOnDelete();
            $table->string('buy_url', 1000)->nullable();
            $table->text('caption')->nullable();
            // Link mp4 bot đọc được trên trang Shopee — server tự tải về.
            $table->string('source_url', 2000)->nullable();
            $table->decimal('source_duration', 7, 2)->nullable();
            $table->decimal('trim_start', 4, 1)->default(1.5);
            $table->decimal('trim_end', 4, 1)->default(1.5);
            $table->string('overlay_top', 200)->nullable();
            $table->string('overlay_bottom', 200)->nullable();
            $table->decimal('output_duration', 7, 2)->nullable();
            // Thư mục riêng của video trong storage/app/private — giữ file gốc để dựng lại.
            $table->boolean('has_source')->default(false);
            $table->boolean('has_output')->default(false);
            $table->string('fb_video_id', 40)->nullable();
            $table->string('permalink', 500)->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_videos');
    }
};
