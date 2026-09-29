<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thêm nguồn lấy mã dự phòng laymavoucher.afp.ad — cùng nền tảng afp.ad với kieushopee, gọi y
 * hệt (xem LaymaVoucherService). Admin chọn nguồn đang dùng bằng công tắc is_active ở
 * /admin/api-config — xem VoucherSourceResolver.
 *
 * Tạo sẵn bản ghi ở TRẠNG THÁI TẮT, cùng lý do với ganma: bật lên là đổi nguồn mã cho toàn bộ
 * khách, phải do admin chủ động quyết chứ không phải hệ quả phụ của một lần deploy. Điền sẵn
 * tham số đọc từ request thật để admin mở form ra là thấy ô mà sửa, không phải tự đoán.
 */
return new class extends Migration
{
    private const ORIGINAL_PLATFORMS = ['shopee', 'lazada', 'tiktok', 'accesstrade', 'facebook', 'kieushopee', 'ganma'];

    private const NEW_PLATFORMS = ['shopee', 'lazada', 'tiktok', 'accesstrade', 'facebook', 'kieushopee', 'ganma', 'laymavoucher'];

    public function up(): void
    {
        $this->setPlatforms(self::NEW_PLATFORMS);

        DB::table('api_configs')->updateOrInsert(
            ['platform' => 'laymavoucher'],
            [
                'name' => 'Laymavoucher — nguồn dự phòng',
                'endpoint' => 'https://laymavoucher.afp.ad/',
                'app_id' => null,
                // Nguồn này không cần secret, nhưng cột NOT NULL và model cast 'encrypted' —
                // ghi chuỗi thô vào đây thì lần đọc đầu tiên sẽ ném DecryptException.
                'app_secret' => Crypt::encryptString(''),
                'meta' => json_encode([
                    'next_action' => '4011f2fcab27c9becc215b92bff62e07ab779531d4',
                    'tool_id' => 'cmulhbxmh007301qqu62phntq',
                    'action_payload' => '["$K1"]',
                    'test_url' => 'https://shopee.vn/Ao-Hoodie-i.564687320.29261186260',
                ], JSON_UNESCAPED_SLASHES),
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        // Đang bật nguồn này mà rollback thì trả quyền phục vụ về kieushopee, không để trống.
        $wasActive = DB::table('api_configs')->where('platform', 'laymavoucher')->value('is_active');

        DB::table('api_configs')->where('platform', 'laymavoucher')->delete();

        if ($wasActive) {
            DB::table('api_configs')->where('platform', 'kieushopee')->update(['is_active' => true]);
        }

        $this->setPlatforms(self::ORIGINAL_PLATFORMS);
    }

    /**
     * SQLite biên dịch enum() thành "varchar check (... in (...))" ngay lúc tạo bảng — không có
     * ALTER COLUMN nên phải dựng lại bảng để đổi danh sách giá trị cho phép. Chỉ nhánh test
     * (sqlite :memory:) đi qua đây; production dùng MySQL đi nhánh else.
     */
    private function setPlatforms(array $platforms): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE api_configs MODIFY platform ENUM(\''.implode('\', \'', $platforms).'\') NOT NULL');

            return;
        }

        Schema::create('api_configs_tmp', function ($table) use ($platforms) {
            $table->id();
            $table->string('name');
            $table->string('endpoint');
            $table->string('app_id')->nullable();
            $table->text('app_secret');
            $table->boolean('is_active')->default(true);
            $table->enum('platform', $platforms);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        $columns = 'id, name, endpoint, app_id, app_secret, is_active, platform, meta, created_at, updated_at';

        DB::statement("INSERT INTO api_configs_tmp ({$columns}) SELECT {$columns} FROM api_configs");

        Schema::drop('api_configs');
        Schema::rename('api_configs_tmp', 'api_configs');
    }
};
