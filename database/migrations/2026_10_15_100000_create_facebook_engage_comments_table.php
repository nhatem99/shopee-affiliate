<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bình luận dạo trong các nhóm follow chéo (FacebookEngageQueue): lúc rảnh, bot mở bảng tin
     * một nhóm, chọn bài mới nhất chưa bình luận rồi bình luận một câu mẫu bằng page admin chọn.
     *  • facebook_groups.engage_enabled: nhóm được bình luận dạo — riêng với "enabled" (đăng deal);
     *  • facebook_engage_comments: mỗi lượt giao việc một dòng — đếm trần mỗi ngày, nhớ bài đã
     *    bình luận để không bình luận lại, và là lịch sử cho trang admin.
     */
    public function up(): void
    {
        Schema::table('facebook_groups', function (Blueprint $table) {
            $table->boolean('engage_enabled')->default(false)->index()->after('disabled_reason');
            $table->string('engage_disabled_reason', 500)->nullable()->after('engage_enabled');
            $table->timestamp('last_engaged_at')->nullable()->after('last_checked_at');
        });

        Schema::create('facebook_engage_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facebook_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->index();
            // Câu server bốc lúc giao việc; bot báo lại câu đã gửi thật ({ten} đã thay tên người đăng).
            $table->text('comment');
            $table->string('post_url', 500)->nullable();
            // Số cuối link bài (…/posts/<số>/) — nhận ra bài đã bình luận.
            $table->string('post_key', 40)->nullable();
            $table->string('author', 255)->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('claimed_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['facebook_group_id', 'post_key']);
            $table->index('claimed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_engage_comments');

        Schema::table('facebook_groups', function (Blueprint $table) {
            $table->dropIndex(['engage_enabled']);
            $table->dropColumn(['engage_enabled', 'engage_disabled_reason', 'last_engaged_at']);
        });
    }
};
