<?php

namespace App\Console\Commands;

use App\Services\SourceHealthService;
use Illuminate\Console\Command;

/**
 * Lượt kiểm tra sức khoẻ định kỳ của nguồn kieushopee (xem SourceHealthService). Chạy theo lịch
 * 5 phút/lần trong routes/console.php, cũng chạy tay được khi muốn thử ngay.
 *
 * Khác `kieushopee:check` ở chỗ lệnh đó chỉ IN ra kết quả cho người đọc; lệnh này còn tự bật/tắt
 * chế độ bảo trì theo kết quả.
 */
class KieuShopeeHealthCheck extends Command
{
    protected $signature = 'kieushopee:health {--force : Chạy kể cả khi công tắc tự bảo trì đang tắt}';

    protected $description = 'Kiểm tra nguồn kieushopee, tự bật/tắt chế độ bảo trì theo kết quả';

    public function handle(SourceHealthService $health): int
    {
        // Công tắc tắt = không gọi gì cả. Vừa khỏi đập vào server của nguồn 288 lượt/ngày cho
        // một kết quả không ai dùng, vừa giữ đúng nghĩa "tắt là không có gì tự chạy".
        if (! SourceHealthService::enabled() && ! $this->option('force')) {
            $this->line('Công tắc "Tự bảo trì khi nguồn mã lỗi" đang tắt — bỏ qua. Dùng --force để chạy thử.');

            return self::SUCCESS;
        }

        $status = $health->check();

        $this->line(match ($status['action']) {
            'da_bat_bao_tri' => '🔴 Đã TỰ BẬT chế độ bảo trì — khách không vào được trang nữa.',
            'da_tat_bao_tri' => '🟢 Nguồn sống lại — đã TỰ TẮT chế độ bảo trì.',
            'cho_them_luot' => 'Lỗi lần đầu, chờ thêm một lượt nữa mới bật bảo trì (tránh đóng trang vì một nhịp timeout).',
            default => 'Không đổi gì.',
        });

        if (! $status['ok']) {
            $this->error("❌ Nguồn lỗi ({$status['consecutive_failures']} lần liên tiếp): {$status['message']}");

            return self::FAILURE;
        }

        $this->info("✅ {$status['message']}");

        return self::SUCCESS;
    }
}
