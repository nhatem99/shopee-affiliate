<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hoa hồng nhập từ báo cáo Shopee KHÔNG gắn với bản ghi affiliate_links nào.
     *
     * affiliate_links chỉ có khi khách tự dán link trên web và đăng nhập; còn đơn đến từ caption
     * reel là do người lạ lướt Facebook bấm vào — mình chỉ biết mã sub_id của người đã thuê reel,
     * không có link nào để trỏ tới. Cột này bắt buộc thì không tạo nổi Commission cho những đơn
     * đó, mà đấy lại là phần lớn đơn.
     */
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->foreignId('affiliate_link_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->foreignId('affiliate_link_id')->nullable(false)->change();
        });
    }
};
