<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Đăng nhóm bằng nhiều danh tính: nick chính và các Trang (fanpage) nick đó quản lý. Bot chuyển
     * qua lại bằng "Chuyển trang cá nhân" của Facebook; mỗi page có trần bài/ngày riêng và bị
     * Facebook chặn thì chỉ page đó nghỉ (FacebookGroupPostScheduler).
     *  • facebook_profiles: các page theo thứ tự đăng — page đầu đăng đủ trần rồi mới tới page sau;
     *  • facebook_group_profile: page nào đã vào nhóm nào (bot lấy nhóm cho từng page);
     *  • facebook_group_posts.facebook_profile_id: bài do page nào đăng.
     *
     * Trước đây chỉ có nick chính: tạo sẵn dòng cho nó, gắn mọi nhóm và bài cũ vào.
     */
    public function up(): void
    {
        Schema::create('facebook_profiles', function (Blueprint $table) {
            $table->id();
            // uid Facebook (cookie c_user cho nick chính, i_user cho page). Nick chính để trống tới
            // lần đầu bot báo; page thêm bằng link tên rút gọn thì bot điền sau lần chuyển đầu.
            $table->string('fb_id', 32)->nullable()->unique();
            $table->string('name')->nullable();
            $table->string('url', 500)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->unsignedSmallInteger('max_per_day')->default(20);
            $table->timestamp('blocked_at')->nullable();
            $table->string('blocked_reason', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('facebook_group_profile', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facebook_profile_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['facebook_group_id', 'facebook_profile_id'], 'fb_group_profile_unique');
        });

        Schema::table('facebook_group_posts', function (Blueprint $table) {
            $table->foreignId('facebook_profile_id')->nullable()->after('facebook_group_id')->constrained()->nullOnDelete();
        });

        $now = now();
        $primaryId = DB::table('facebook_profiles')->insertGetId([
            'name' => 'Nick chính',
            'is_primary' => true,
            'enabled' => true,
            'position' => 0,
            'max_per_day' => 20,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('facebook_groups')->orderBy('id')->select('id')->chunk(500, function ($groups) use ($primaryId, $now) {
            DB::table('facebook_group_profile')->insert($groups->map(fn ($group) => [
                'facebook_group_id' => $group->id,
                'facebook_profile_id' => $primaryId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        DB::table('facebook_group_posts')->whereNotNull('claimed_at')->update(['facebook_profile_id' => $primaryId]);

        // "Facebook tạm chặn đăng bài" giờ là chuyện của từng page. Bot đang dừng cả cụm vì nick
        // chính bị chặn thì chuyển thành nick chính bị chặn — admin bấm "Mở lại" nick chính khi
        // hết chặn, các page khác đăng được luôn khi bấm "Chạy tiếp".
        $pausedReason = DB::table('settings')->where('key', 'fb_runner_paused_reason')->value('value');
        $paused = filter_var(DB::table('settings')->where('key', 'fb_runner_paused')->value('value'), FILTER_VALIDATE_BOOLEAN);
        if ($paused && $pausedReason === 'Facebook báo tạm chặn đăng bài') {
            DB::table('facebook_profiles')->where('id', $primaryId)->update([
                'blocked_at' => $now,
                'blocked_reason' => $pausedReason,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('facebook_group_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('facebook_profile_id');
        });
        Schema::dropIfExists('facebook_group_profile');
        Schema::dropIfExists('facebook_profiles');
    }
};
