<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dữ liệu THÔ từ báo cáo hoa hồng affiliate Shopee (xuất CSV tay ở trang affiliate).
     *
     * Cố ý KHÔNG nhét vào bảng `commissions`: bảng đó đang mang nghĩa khác hẳn — cashback DỰ
     * KIẾN sinh lúc khách quét link (AffiliateScanOrchestrator), chưa phải đơn có thật. Trộn
     * hai thứ vào một bảng là biểu đồ ở /admin/dashboard cộng nhầm tiền chưa tồn tại vào doanh
     * thu thật. `commissions` vẫn là nơi tiền THẬT chảy vào, nhưng chỉ được sinh ra TỪ bảng này
     * sau khi đơn đã "Hoàn thành".
     *
     * Một dòng ở đây = một DÒNG SẢN PHẨM trong báo cáo, không phải một đơn. Đơn nhiều sản phẩm
     * chiếm nhiều dòng cùng order_id, và Shopee dồn toàn bộ hoa hồng cấp đơn vào dòng đầu (các
     * dòng sau bằng 0) — nên mọi phép cộng tiền phải gộp theo order_id.
     */
    public function up(): void
    {
        Schema::create('shopee_orders', function (Blueprint $table) {
            $table->id();

            // ── Danh tính dòng báo cáo ────────────────────────────────────────────────
            $table->string('order_id', 64);
            $table->string('item_id', 32);
            // Shopee để trống ID Model với sản phẩm không phân loại. Lưu chuỗi rỗng chứ không
            // NULL: MySQL cho phép nhiều NULL trùng nhau trong unique index, nên để NULL là
            // khoá chống trùng mất tác dụng đúng ở những dòng đó.
            $table->string('model_id', 32)->default('');
            $table->string('checkout_id', 64)->nullable();

            // ── Sản phẩm / shop ──────────────────────────────────────────────────────
            $table->string('shop_id', 32)->nullable();
            $table->string('shop_name')->nullable();
            $table->text('product_name')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('order_value', 15, 2)->default(0);

            // ── Tiền ─────────────────────────────────────────────────────────────────
            // net_commission = "Hoa hồng ròng tiếp thị liên kết" — đã trừ phí quản lý MCN, tức
            // số thật sự về túi mình. Mọi phép chia hoa hồng cho khách phải tính trên cột này,
            // KHÔNG phải "Tổng hoa hồng đơn hàng" (chưa trừ phí) hay "Giá trị đơn hàng".
            $table->decimal('net_commission', 15, 2)->default(0);
            $table->decimal('total_order_commission', 15, 2)->default(0);

            // ── Trạng thái ───────────────────────────────────────────────────────────
            // Giữ nguyên văn trạng thái Shopee để đối chiếu khi họ đổi cách gọi, và một cột
            // đã chuẩn hoá để code truy vấn.
            $table->string('order_status_raw')->nullable();
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending');

            // ── Quy về khách hàng nào ────────────────────────────────────────────────
            // sub_id_raw: nguyên văn ô Sub_id1 của Shopee. user_sub_id: mã khách tách ra từ đó.
            // Giữ cả hai vì cách tách còn phụ thuộc việc Shopee có tự cắt 5 khe hay không — có
            // bản gốc thì lúc phát hiện tách sai còn chạy lại được, không phải xin xuất lại báo cáo.
            $table->string('sub_id_raw')->nullable();
            $table->string('user_sub_id', 32)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('channel')->nullable();
            $table->string('content_type')->nullable();

            // ── Mốc thời gian của Shopee (không phải của mình) ───────────────────────
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // Nhập lại cùng một file phải là no-op, và báo cáo tải lại sau vài ngày sẽ mang
            // đúng các dòng cũ với trạng thái đã đổi — khoá này là chỗ nhận ra "vẫn dòng đó".
            $table->unique(['order_id', 'item_id', 'model_id'], 'shopee_orders_line_unique');

            $table->index('user_sub_id');
            $table->index('status');
            $table->index('ordered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_orders');
    }
};
