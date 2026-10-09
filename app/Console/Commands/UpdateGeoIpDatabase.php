<?php

namespace App\Console\Commands;

use App\Services\GeoIpDatabase;
use Illuminate\Console\Command;

/**
 * Tải file dữ liệu IP → quốc gia cho GeoBlock (xem GeoIpDatabase). Chạy hàng ngày nhưng đã có
 * bản tháng này thì thoát ngay, không gọi mạng. Lỗi thì GeoBlock vẫn chạy bằng bản cũ, hoặc
 * bằng ip-api nếu máy chưa từng có file.
 */
class UpdateGeoIpDatabase extends Command
{
    protected $signature = 'geoip:update {--force : Tải lại kể cả khi đã có bản tháng này}';

    protected $description = 'Tải file dữ liệu IP → quốc gia (DB-IP Lite) để chặn IP nước ngoài';

    public function handle(GeoIpDatabase $database): int
    {
        $result = $database->update((bool) $this->option('force'));

        if ($result['status'] === 'failed') {
            $this->error('Không tải được file dữ liệu IP — '.$result['error']);
            $this->line($result['month'] !== null
                ? "Vẫn dùng bản {$result['month']}."
                : 'Máy chưa có file nào: GeoBlock đang tra ip-api, IP lạ lọt lượt đầu.');

            return self::FAILURE;
        }

        $this->info($result['status'] === 'installed'
            ? "Đã cài bản {$result['month']}."
            : "Đang dùng bản {$result['month']}, chưa có bản mới hơn.");

        return self::SUCCESS;
    }
}
