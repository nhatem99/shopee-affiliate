<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bài đã đăng có được admin nhóm duyệt không: lúc rảnh, bot mở "Nội dung của bạn" trong nhóm
     * (đang chờ / đã đăng / bị từ chối / đã gỡ) và báo về (FacebookGroupReviewChecker).
     *  • review_state: bài đang nằm ở đâu — null là chưa kiểm tra;
     *  • post_url: link bài trên nhóm, nếu bot thấy;
     *  • facebook_groups.last_checked_at: lần gần nhất giao việc kiểm tra nhóm cho bot.
     */
    public function up(): void
    {
        Schema::table('facebook_group_posts', function (Blueprint $table) {
            $table->string('review_state', 20)->nullable()->after('error');
            $table->timestamp('reviewed_at')->nullable()->after('review_state');
            $table->string('post_url', 500)->nullable()->after('reviewed_at');
        });

        Schema::table('facebook_groups', function (Blueprint $table) {
            $table->timestamp('last_checked_at')->nullable()->after('last_posted_at');
        });
    }

    public function down(): void
    {
        Schema::table('facebook_group_posts', function (Blueprint $table) {
            $table->dropColumn(['review_state', 'reviewed_at', 'post_url']);
        });

        Schema::table('facebook_groups', function (Blueprint $table) {
            $table->dropColumn('last_checked_at');
        });
    }
};
