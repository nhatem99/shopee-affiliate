<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * URL Shopee (canonical với kieushopee, link ngắn gốc với ganma) đã dùng để lấy `url` lần đầu.
 * Cần lưu riêng để ShortLinkController::store() gọi lại đúng nguồn khi khách bấm "Mua ngay"
 * trên một ref cũ — không có cột này thì không còn gì để gọi lại, chỉ có mỗi link đích cuối.
 *
 * Nullable vì ref đã phát trước migration này không có giá trị — chỉ ref phát ra SAU khi deploy
 * mới có refetch, ref cũ vẫn rơi về hành vi cũ (dùng thẳng url đã lưu) cho tới khi hết hạn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_refs', function (Blueprint $table) {
            $table->text('source_url')->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_refs', function (Blueprint $table) {
            $table->dropColumn('source_url');
        });
    }
};
