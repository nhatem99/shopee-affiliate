<?php

namespace App\Console\Commands;

use App\Services\SalesOcService;
use Illuminate\Console\Command;

/**
 * Bắn thử tất cả đường ra tới salesoc.vn từ chính server đang chạy.
 *
 * salesoc.vn chặn theo nguồn gọi ở tầng nginx và đã chặn lần lượt IP VPS rồi Cloudflare Worker.
 * Mỗi lần họ chặn thêm, câu hỏi luôn là "đường nào còn sống" — command này trả lời trong 1 lệnh,
 * thay vì phải tự dựng curl với đủ header giả.
 */
class SalesOcCheck extends Command
{
    protected $signature = 'salesoc:check {url : Link sản phẩm Shopee dùng để thử}';

    protected $description = 'Kiểm tra từng đường ra (relay/proxy/direct) tới salesoc.vn';

    public function handle(SalesOcService $salesOc): int
    {
        $this->line('Đang thử từng đường tới salesoc.vn...');
        $this->newLine();

        $results = $salesOc->diagnose($this->argument('url'));

        $this->table(
            ['Đường', 'Kết quả', 'HTTP', 'Thời gian', 'Chi tiết'],
            array_map(fn (array $r) => [
                $r['route'],
                $r['ok'] ? '✅ OK' : '❌ Hỏng',
                $r['status'] ?? '—',
                $r['duration_ms'].'ms',
                $r['detail'],
            ], $results),
        );

        $working = array_values(array_filter($results, fn (array $r) => $r['ok']));

        if ($working === []) {
            $this->error('Không đường nào hoạt động — tính năng lấy mã đang chết hoàn toàn.');

            return self::FAILURE;
        }

        $this->info("Đường đang dùng được: {$working[0]['route']}");

        return self::SUCCESS;
    }
}
