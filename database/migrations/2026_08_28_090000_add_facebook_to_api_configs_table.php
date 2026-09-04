<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ORIGINAL_PLATFORMS = ['shopee', 'lazada', 'tiktok', 'accesstrade'];

    private const NEW_PLATFORMS = ['shopee', 'lazada', 'tiktok', 'accesstrade', 'facebook'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite biên dịch enum() thành "varchar check (... in (...))" ngay lúc tạo bảng — không có
            // ALTER COLUMN nên phải dựng lại bảng để đổi danh sách giá trị cho phép. Chỉ nhánh test
            // (sqlite :memory:) đi qua đây; production dùng MySQL đi nhánh else bên dưới.
            $this->rebuildSqliteTable(self::NEW_PLATFORMS, addMeta: true);

            return;
        }

        DB::statement('ALTER TABLE api_configs MODIFY platform ENUM(\''.implode('\', \'', self::NEW_PLATFORMS).'\') NOT NULL');

        Schema::table('api_configs', function (Blueprint $table) {
            // Cấu hình riêng theo provider mà các cột cố định (endpoint/app_id/app_secret)
            // không đủ diễn tả — ví dụ target_post_id của Facebook (bài viết sẽ nhận comment).
            $table->json('meta')->nullable()->after('platform');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('api_configs')->where('platform', 'facebook')->delete();

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(self::ORIGINAL_PLATFORMS, addMeta: false);

            return;
        }

        Schema::table('api_configs', function (Blueprint $table) {
            $table->dropColumn('meta');
        });

        DB::statement('ALTER TABLE api_configs MODIFY platform ENUM(\''.implode('\', \'', self::ORIGINAL_PLATFORMS).'\') NOT NULL');
    }

    private function rebuildSqliteTable(array $platforms, bool $addMeta): void
    {
        Schema::create('api_configs_tmp', function (Blueprint $table) use ($platforms, $addMeta) {
            $table->id();
            $table->string('name');
            $table->string('endpoint');
            $table->string('app_id')->nullable();
            $table->text('app_secret');
            $table->boolean('is_active')->default(true);
            $table->enum('platform', $platforms);
            if ($addMeta) {
                $table->json('meta')->nullable();
            }
            $table->timestamps();
        });

        // meta không có trong bảng cũ ở cả hai chiều (chưa tồn tại lúc up(), đã bị loại khỏi
        // danh sách cột chọn để xoá lúc down()) nên không cần liệt kê ở đây trong mọi trường hợp.
        $columns = 'id, name, endpoint, app_id, app_secret, is_active, platform, created_at, updated_at';

        DB::statement("INSERT INTO api_configs_tmp ({$columns}) SELECT {$columns} FROM api_configs");

        Schema::drop('api_configs');
        Schema::rename('api_configs_tmp', 'api_configs');
    }
};
