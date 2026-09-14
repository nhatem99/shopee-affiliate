<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trạng thái từng reel trong nhóm reel của chế độ "đổi caption reel" — xem FacebookReelSlotService.
 *
 * Trước đây slot reel nằm trong cache (lease 10 phút), tức là hệ thống chỉ TIN rằng reel đang
 * hiện link nào chứ không hề biết thật. Deploy xoá cache là quên hết; admin sửa tay caption trên
 * Facebook là lệch mà không ai hay. Bảng này giữ hai lớp thông tin tách bạch:
 *  - product_key/target_url: reel đang hiện link của sản phẩm nào — job facebook:sync-reels đọc
 *    caption thật từ Graph API 5 phút/lần để ghi đè cho đúng thực tế.
 *  - leased_until: reel đang được GIỮ cho sản phẩm đó tới khi nào, để request khác không đè
 *    caption trong lúc khách còn đang xem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_reel_slots', function (Blueprint $table) {
            $table->id();
            $table->string('reel_id', 64)->unique();
            $table->string('product_key', 191)->nullable()->index();
            $table->string('product_name')->nullable();
            $table->text('target_url')->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->text('caption')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_reel_slots');
    }
};
