<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token mờ (ref) phát cho frontend thay cho URL affiliate thật — xem VoucherRefService.
 *
 * Trước đây nằm trong cache với TTL 7 ngày, nhưng deploy chạy `optimize:clear` (bao gồm
 * `cache:clear`) nên mọi ref chết ngay sau mỗi lần deploy: nút "Mua ngay" trong lịch sử của
 * khách (localStorage, giữ ref) báo "Không thể tạo link" dù mới lấy mã hôm qua. Chuyển sang
 * bảng riêng để vòng đời của ref chỉ phụ thuộc expires_at, không phụ thuộc cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_refs', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 32)->unique();
            // URL voucher của Shopee dài hàng nghìn ký tự — text, không string.
            $table->text('url');
            $table->string('source', 32)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_refs');
    }
};
