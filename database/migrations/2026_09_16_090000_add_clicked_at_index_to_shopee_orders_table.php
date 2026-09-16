<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * /admin/activities đối chiếu cú bấm link với đơn Shopee theo `clicked_at` (xem
 * ClickOrderMatchService) — truy vấn khoảng thời gian này chạy mỗi lần mở trang, nên cột
 * phải có index thay vì quét cả bảng báo cáo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_orders', function (Blueprint $table) {
            $table->index('clicked_at');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_orders', function (Blueprint $table) {
            $table->dropIndex(['clicked_at']);
        });
    }
};
