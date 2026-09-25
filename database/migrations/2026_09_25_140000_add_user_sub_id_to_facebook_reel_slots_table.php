<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reel đang thuê là thuê cho KHÁCH NÀO, không chỉ cho sản phẩm nào.
     *
     * Trước đây slot chỉ khoá theo product_key, nên hai khách cùng xem một sản phẩm dùng chung
     * một reel — mà caption reel chứa short-link của khách BẤM TRƯỚC, và short-link đó mang mã
     * Sub_id của người đó. Khách thứ hai bấm vào chính link ấy, mua hàng, và tiền hoàn của đơn
     * đó chảy vào ví người thứ nhất. Không có gì báo lỗi: đơn về đủ, hoa hồng về đủ, chỉ là về
     * nhầm người — và khách thứ hai thì không bao giờ biết vì sao mình không được hoàn.
     *
     * Cột này là nửa DB của cái khoá đó. Nửa còn lại nằm ở FacebookReelSlotService::reelUrlFor()
     * (lúc thuê) và FacebookReelSyncService (lúc đối soát caption thật trên Facebook).
     *
     * nullable = reel đang phục vụ khách VÃNG LAI: link không mang mã ai cả nên dùng chung được,
     * không có gì để lẫn.
     */
    public function up(): void
    {
        Schema::table('facebook_reel_slots', function (Blueprint $table) {
            $table->string('user_sub_id', 32)->nullable()->after('product_name');
        });
    }

    public function down(): void
    {
        Schema::table('facebook_reel_slots', function (Blueprint $table) {
            $table->dropColumn('user_sub_id');
        });
    }
};
