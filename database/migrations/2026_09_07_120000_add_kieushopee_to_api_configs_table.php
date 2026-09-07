<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cho phép sửa tham số gọi kieushopee ngay trên /admin/api-config.
 *
 * next_action là ID Server Action do bản build Next.js của site nguồn sinh ra — họ deploy lại
 * là ID đổi và tính năng lấy mã chết ngay. Trước đây giá trị này nằm trong config/services.php,
 * nghĩa là mỗi lần họ đổi phải sửa code + deploy mới cứu được. Đưa vào DB để admin dán ID mới
 * vào là chạy lại ngay.
 */
return new class extends Migration
{
    private const ORIGINAL_PLATFORMS = ['shopee', 'lazada', 'tiktok', 'accesstrade', 'facebook'];

    private const NEW_PLATFORMS = ['shopee', 'lazada', 'tiktok', 'accesstrade', 'facebook', 'kieushopee'];

    private const DEFAULTS = [
        'name' => 'KieuShopee — nguồn lấy mã',
        'endpoint' => 'https://sansale.kieushopee.com/22',
        'meta' => [
            'next_action' => '40b7104a0118f057e6843f62bb2c67bc4824e3ec0a',
            'tool_id' => 'cmssikp0w000x01qaays5m55b',
            'action_payload' => '["$K1"]',
            // Link nút "Kiểm tra kết nối" đem ra gọi thử. Để sửa được vì sản phẩm có thể bị gỡ
            // khỏi Shopee, lúc đó nút báo hỏng dù tham số vẫn đúng.
            'test_url' => 'https://shopee.vn/Ao-Hoodie-i.564687320.29261186260',
        ],
    ];

    public function up(): void
    {
        $this->setPlatforms(self::NEW_PLATFORMS);

        // Tạo sẵn bản ghi với đúng giá trị đang chạy, để admin mở trang lên là thấy ô mà sửa
        // chứ không phải tự đoán cần điền gì. app_secret không dùng tới nhưng cột NOT NULL.
        DB::table('api_configs')->updateOrInsert(
            ['platform' => 'kieushopee'],
            [
                'name' => self::DEFAULTS['name'],
                'endpoint' => self::DEFAULTS['endpoint'],
                'app_id' => null,
                // Nguồn này không cần secret, nhưng cột NOT NULL và model cast 'encrypted' —
                // ghi chuỗi thô vào đây thì lần đọc đầu tiên sẽ ném DecryptException.
                'app_secret' => Crypt::encryptString(''),
                'meta' => json_encode(self::DEFAULTS['meta'], JSON_UNESCAPED_SLASHES),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('api_configs')->where('platform', 'kieushopee')->delete();

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
