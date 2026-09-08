<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thêm nguồn lấy mã thứ hai: ganma.vn (mã YouTube), chạy song song kieushopee (mã Facebook/
 * Instagram). Admin chọn nguồn đang dùng bằng công tắc is_active ở /admin/api-config — xem
 * VoucherSourceResolver.
 *
 * Tạo sẵn bản ghi ở TRẠNG THÁI TẮT: bật lên là đổi nguồn mã cho toàn bộ khách đang dùng, phải
 * do admin chủ động quyết chứ không phải hệ quả phụ của một lần deploy.
 */
return new class extends Migration
{
    private const ORIGINAL_PLATFORMS = ['shopee', 'lazada', 'tiktok', 'accesstrade', 'facebook', 'kieushopee'];

    private const NEW_PLATFORMS = ['shopee', 'lazada', 'tiktok', 'accesstrade', 'facebook', 'kieushopee', 'ganma'];

    public function up(): void
    {
        $this->setPlatforms(self::NEW_PLATFORMS);

        DB::table('api_configs')->updateOrInsert(
            ['platform' => 'ganma'],
            [
                'name' => 'Ganma — nguồn mã YouTube',
                'endpoint' => 'https://ganma.vn',
                'app_id' => null,
                // Nguồn này không cần secret, nhưng cột NOT NULL và model cast 'encrypted' —
                // ghi chuỗi thô vào đây thì lần đọc đầu tiên sẽ ném DecryptException.
                'app_secret' => Crypt::encryptString(''),
                'meta' => json_encode([
                    // Nút "Kiểm tra kết nối" đem link này ra chạy trọn một job thật (~20 giây).
                    // BẮT BUỘC là link ngắn từ app Shopee: ganma từ chối link shopee.vn đầy đủ.
                    // Để trống vì link ngắn gắn với từng sản phẩm và hay hết hạn — admin tự dán.
                    'test_url' => null,
                ], JSON_UNESCAPED_SLASHES),
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('api_configs')->where('platform', 'ganma')->delete();

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
