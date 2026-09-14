<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link YouTube của ganma lấy cùng lúc với `url` (kieushopee) ở chế độ mã YTB. Lúc khách bấm
 * mua, ShortLinkController xâu link này vào TRƯỚC link đích, để trình duyệt của khách tự đi qua
 * nó rồi mới tới Shopee — xem AffiliateLinkRewriterService::chainThroughYoutubeLink().
 *
 * Lưu vào ref chứ không gọi lại ganma lúc bấm: một job ganma mất 20-45 giây, bắt khách đợi
 * chừng đó ngay sau cú bấm "Mua ngay" là không được.
 *
 * Nullable: chế độ kieushopee thường không có, ref phát trước migration này cũng không có.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_refs', function (Blueprint $table) {
            $table->text('ytb_url')->nullable()->after('source_url');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_refs', function (Blueprint $table) {
            $table->dropColumn('ytb_url');
        });
    }
};
