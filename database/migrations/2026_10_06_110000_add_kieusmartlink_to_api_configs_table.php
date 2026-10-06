<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thêm nguồn kieushopee.com/smart-links — link affiliate rút gọn chính thức của Shopee, đích
 * /opaanlp/ (xem KieuSmartLinkService). Admin chọn nguồn đang dùng bằng công tắc is_active ở
 * /admin/api-config — xem VoucherSourceResolver.
 *
 * Tạo sẵn bản ghi ở TRẠNG THÁI TẮT, cùng lý do với laymavoucher: bật lên là đổi nguồn cho toàn
 * bộ khách, phải do admin chủ động quyết chứ không phải hệ quả phụ của một lần deploy.
 */
return new class extends Migration
{
    private const ORIGINAL_PLATFORMS = ['shopee', 'lazada', 'tiktok', 'accesstrade', 'facebook', 'kieushopee', 'ganma', 'laymavoucher'];

    private const NEW_PLATFORMS = ['shopee', 'lazada', 'tiktok', 'accesstrade', 'facebook', 'kieushopee', 'ganma', 'laymavoucher', 'kieusmartlink'];

    public function up(): void
    {
        $this->setPlatforms(self::NEW_PLATFORMS);

        DB::table('api_configs')->updateOrInsert(
            ['platform' => 'kieusmartlink'],
            [
                'name' => 'Kieu Smart Link — link affiliate voucher tự áp',
                'endpoint' => 'https://kieushopee.com/smart-links',
                'app_id' => null,
                // Không cần secret, nhưng cột NOT NULL và model cast 'encrypted' — xem migration
                // laymavoucher.
                'app_secret' => Crypt::encryptString(''),
                'meta' => json_encode([
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
        $wasActive = DB::table('api_configs')->where('platform', 'kieusmartlink')->value('is_active');

        DB::table('api_configs')->where('platform', 'kieusmartlink')->delete();

        if ($wasActive) {
            DB::table('api_configs')->where('platform', 'kieushopee')->update(['is_active' => true]);
        }

        $this->setPlatforms(self::ORIGINAL_PLATFORMS);
    }

    /** Y hệt migration laymavoucher — SQLite phải dựng lại bảng để đổi danh sách enum. */
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
