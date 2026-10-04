<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bài đăng nhóm Facebook không chỉ là deal Shopee nữa:
     *  • shopee_url null = "bài tự soạn" — admin tự viết, không có link mua;
     *  • images = ảnh admin tự tải lên (tên file trong FacebookPostImages::DIR), null = không có;
     *  • with_product_image = có kèm ảnh sản phẩm Shopee không (mặc định có, như trước).
     */
    public function up(): void
    {
        Schema::table('facebook_group_deals', function (Blueprint $table) {
            $table->string('shopee_url', 2000)->nullable()->change();
            $table->json('images')->nullable()->after('product');
            $table->boolean('with_product_image')->default(true)->after('images');
        });
    }

    public function down(): void
    {
        // Bản cũ không có chỗ cho bài không link — xoá chúng (bài theo nhóm xoá theo khoá ngoại).
        DB::table('facebook_group_deals')->whereNull('shopee_url')->delete();

        Schema::table('facebook_group_deals', function (Blueprint $table) {
            $table->dropColumn(['images', 'with_product_image']);
        });
        Schema::table('facebook_group_deals', function (Blueprint $table) {
            $table->string('shopee_url', 2000)->nullable(false)->change();
        });
    }
};
