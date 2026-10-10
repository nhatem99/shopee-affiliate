<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link mua để ở bình luận thay vì trong bài: bài có link dễ bị nhóm/Facebook coi là spam. Bài
     * chỉ ghi "link ở bình luận", đăng xong bot vào bài bình luận link (FacebookGroupCommentQueue).
     *  • facebook_group_deals.link_in_comment: admin chọn lúc soạn bài;
     *  • facebook_group_posts.comment: nội dung bình luận dựng lúc bot nhận bài — null là bài
     *    không cần bình luận;
     *  • comment_status: pending (chờ bài lên nhóm rồi bình luận) / done / unknown (đã bấm gửi
     *    nhưng không xác nhận được) / failed (thử đủ lượt vẫn hỏng).
     */
    public function up(): void
    {
        Schema::table('facebook_group_deals', function (Blueprint $table) {
            $table->boolean('link_in_comment')->default(false)->after('caption');
        });

        Schema::table('facebook_group_posts', function (Blueprint $table) {
            $table->text('comment')->nullable()->after('caption');
            $table->string('comment_status', 20)->nullable()->after('post_url');
            $table->unsignedTinyInteger('comment_attempts')->default(0)->after('comment_status');
            $table->timestamp('comment_claimed_at')->nullable()->after('comment_attempts');
            $table->timestamp('commented_at')->nullable()->after('comment_claimed_at');
            $table->string('comment_error', 1000)->nullable()->after('commented_at');
        });
    }

    public function down(): void
    {
        Schema::table('facebook_group_posts', function (Blueprint $table) {
            $table->dropColumn(['comment', 'comment_status', 'comment_attempts', 'comment_claimed_at', 'commented_at', 'comment_error']);
        });

        Schema::table('facebook_group_deals', function (Blueprint $table) {
            $table->dropColumn('link_in_comment');
        });
    }
};
