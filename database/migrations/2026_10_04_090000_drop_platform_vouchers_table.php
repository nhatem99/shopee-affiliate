<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bỏ bảng mã nhập tay (trang /admin/vouchers và mục "Mã giảm giá gợi ý" ở trang chủ đã gỡ).
 * Mã khách thấy giờ đến từ API nguồn mã và từ `voucher_offers` (/ma-giam-gia). down() chỉ dựng
 * lại cấu trúc, không khôi phục dữ liệu đã xoá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('platform_vouchers');
    }

    public function down(): void
    {
        Schema::create('platform_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('platform')->default('shopee'); // shopee|lazada|tiki|tiktok|all
            $table->string('source')->default('manual');   // facebook|youtube|manual
            $table->string('code');
            $table->string('title')->nullable();
            $table->string('discount_type')->default('flat'); // flat|percent|freeship
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('minimum_order', 12, 2)->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'created_at']);
            $table->index('expires_at');
        });
    }
};
