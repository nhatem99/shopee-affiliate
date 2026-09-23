<?php

namespace App\Console\Commands;

use App\Services\FlashSaleSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Làm mới kho sản phẩm Flash Sale của trang /flashsale.
 *
 * Bốn con số vì mỗi con số hỏng theo một kiểu khác nhau:
 *
 *  • fetched = 0/lỗi   → nguồn không trả gì: đổi endpoint, chặn IP, hoặc sập.
 *  • dropped cao       → hết suất hoặc thiếu dữ liệu bắt buộc — bình thường có một ít
 *                        mỗi lượt, cao bất thường mới đáng nhìn kỹ.
 *  • kept = 0 mà fetched > 0 → nguồn trả dữ liệu hỏng cấu trúc; lệnh CỐ Ý không dọn
 *                        bảng trong tình huống này (xem FlashSaleSyncService::sync()).
 *  • pruned cao        → nhiều suất đã đóng cùng lúc, bình thường khi qua khung giờ mới.
 */
class SyncFlashSale extends Command
{
    protected $signature = 'flashsale:sync';

    protected $description = 'Đồng bộ kho sản phẩm Flash Sale cho trang /flashsale';

    public function handle(FlashSaleSyncService $sync): int
    {
        $report = $sync->sync();

        $this->table(
            ['Nhận về', 'Đã lưu', 'Bỏ', 'Đã dọn', 'Lỗi'],
            [[$report['fetched'], $report['kept'], $report['dropped'], $report['pruned'], $report['error'] ?? '']],
        );

        Cache::forget('flash_sale_items:slots');
        Cache::forget('flash_sale_items:last_synced');

        if ($report['error'] !== null || ($report['fetched'] > 0 && $report['kept'] === 0)) {
            $this->error('Đồng bộ không lưu được sản phẩm nào — xem /admin/logs, tìm "FlashSaleSyncService".');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
