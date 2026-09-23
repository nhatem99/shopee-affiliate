<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kho mã giảm giá toàn sàn hiển thị ở /ma-giam-gia — đồng bộ định kỳ từ nguồn ngoài
 * (xem VoucherCatalogSyncService), khác hẳn bảng `platform_vouchers` vốn là mã admin
 * gõ tay để gợi ý ở trang chủ.
 *
 * Để trong BẢNG chứ không phải cache, cùng lý do đã rút ra ở voucher_refs: deploy chạy
 * `optimize:clear` nên cache bay sạch sau mỗi lần lên bản mới. Nằm ở cache thì cứ deploy
 * xong là /ma-giam-gia trống trơn cho tới nhịp cron kế tiếp — một trang SEO mà trắng
 * trang vài chục phút sau mỗi lần deploy thì thà đừng làm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_offers', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20);
            // promotionId bên Shopee — khoá để nhận ra mã cũ khi đồng bộ lại.
            $table->string('external_id', 64);
            $table->string('code', 100);
            $table->string('title', 120)->nullable();
            $table->string('subtitle', 255)->nullable();
            $table->string('applies_text', 100)->nullable();
            $table->string('shop_name', 120)->nullable();
            $table->text('voucher_image')->nullable();
            // Link đã đổi sang affiliate của mình, CHƯA gắn sub_id của từng khách —
            // một dòng phục vụ mọi người xem, sub_id ghép lúc trả response.
            $table->text('claim_url');
            $table->string('category', 32)->nullable();
            $table->unsignedTinyInteger('usage_percent')->default(0);
            $table->string('usage_text', 120)->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // Một mã chỉ tồn tại một lần trên mỗi sàn: đồng bộ dùng upsert theo cặp này.
            $table->unique(['platform', 'external_id']);
            // Truy vấn chính của trang: lọc sàn + loại mã hết hạn, sắp theo hạn dùng.
            $table->index(['platform', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_offers');
    }
};
