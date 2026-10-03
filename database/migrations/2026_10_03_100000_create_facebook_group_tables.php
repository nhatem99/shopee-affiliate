<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Đăng deal vào các nhóm Facebook mà nick cá nhân đã tham gia. Graph API không đăng được
     * vào nhóm nữa (Meta gỡ Groups API 22/04/2024), nên việc bấm đăng do một bot trình duyệt
     * chạy trên máy nhà làm (deploy/fb-group-runner) — server chỉ giữ danh sách nhóm, hàng đợi
     * và luật nhịp đăng.
     *
     * Hàng đợi nằm trong bảng chứ không trong Cache: mỗi lần deploy chạy optimize:clear, mất
     * hàng đợi giữa chừng là mất dấu bài nào đã đăng — đúng thứ dẫn tới đăng trùng.
     */
    public function up(): void
    {
        Schema::create('facebook_groups', function (Blueprint $table) {
            $table->id();
            // Phần sau /groups/ trong link: id số hoặc tên rút gọn, viết thường.
            $table->string('fb_group_key', 191)->unique();
            $table->string('name')->nullable();
            $table->string('url', 500);
            // Nhóm bot lấy về mặc định TẮT — admin tự tích nhóm nào được đăng.
            $table->boolean('enabled')->default(false)->index();
            $table->string('source', 10)->default('manual');
            $table->string('disabled_reason', 500)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            // Lần bot NHẬN bài cho nhóm (tính khoảng nghỉ mỗi nhóm) — khác lần đăng được thật:
            // bài "không rõ đã đăng chưa" vẫn phải tính là đã đụng tới nhóm.
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('facebook_group_deals', function (Blueprint $table) {
            $table->id();
            // Giữ NGUYÊN VĂN link admin dán: chế độ mã YTB (ganma) chỉ nhận link rút gọn từ app.
            $table->string('shopee_url', 2000);
            $table->string('canonical_url', 2000)->nullable();
            $table->string('source', 20)->nullable();
            $table->json('product')->nullable();
            // Mẫu bài: {link} là chỗ đặt link mua, {a|b} để mỗi nhóm nhận câu chữ khác nhau.
            $table->text('caption');
            // Link tạo lúc soạn bài — dùng khi tới lượt đăng mà không lấy được link mới.
            $table->string('fallback_buy_url', 500)->nullable();
            $table->string('fallback_ytb_url', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('facebook_group_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_group_deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facebook_group_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index();
            // Lúc vào hàng đợi (cả khi admin bấm đăng lại) — bài chờ quá lâu thì hết hạn.
            $table->timestamp('queued_at')->nullable();
            // Bot tự sinh cho mỗi lượt nhận bài; gửi lại đúng key thì nhận lại đúng bài cũ.
            $table->string('claim_key', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('caption')->nullable();
            $table->string('buy_url', 500)->nullable();
            $table->string('link_kind', 10)->nullable();
            $table->foreignId('short_link_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error', 1000)->nullable();
            $table->timestamps();

            $table->unique(['facebook_group_deal_id', 'facebook_group_id']);
            $table->index(['facebook_group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_group_posts');
        Schema::dropIfExists('facebook_group_deals');
        Schema::dropIfExists('facebook_groups');
    }
};
