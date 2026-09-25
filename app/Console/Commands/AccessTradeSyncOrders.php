<?php

namespace App\Console\Commands;

use App\Services\AccessTradeOrderImportService;
use Illuminate\Console\Command;

/**
 * Kéo đơn TikTok Shop từ ACCESSTRADE về (xem AccessTradeOrderImportService). Chạy theo lịch,
 * cũng chạy tay được khi cần lấy bù một khoảng dài.
 *
 * Khác hẳn đường Shopee: bên đó admin phải tự xuất CSV rồi tải lên ở /admin/don-hang vì Shopee
 * không có API cho tài khoản affiliate cá nhân.
 */
class AccessTradeSyncOrders extends Command
{
    protected $signature = 'accesstrade:sync-orders {--days=30 : Số ngày lùi về cần quét lại}';

    protected $description = 'Đồng bộ đơn TikTok Shop từ ACCESSTRADE và cộng tiền hoàn cho khách';

    public function handle(AccessTradeOrderImportService $importer): int
    {
        $days = max(1, (int) $this->option('days'));

        $this->line("Đang lấy đơn {$days} ngày gần nhất...");

        $summary = $importer->import($days);

        $this->table(['Chỉ số', 'Giá trị'], [
            ['Lượt gọi theo lát thời gian', $summary['windows']],
            ['Đơn đọc được', $summary['fetched']],
            ['Ghi mới', $summary['created']],
            ['Cập nhật', $summary['updated']],
            ['Quy được về khách', $summary['matched']],
            ['Không quy được về ai', $summary['unmatched']],
        ]);

        // Không có đơn nào KHÔNG phải lỗi (ngày vắng khách), nhưng lấy được đơn mà không quy
        // được về ai thì đáng nói to: đó là dấu hiệu ô utm_content không quay về như mong đợi,
        // tức mọi đơn TikTok đang rơi vào hư không thay vì vào ví khách.
        if ($summary['fetched'] > 0 && $summary['matched'] === 0) {
            $this->warn('⚠️ Có đơn nhưng KHÔNG đơn nào quy được về khách — kiểm tra ô utm_content trong báo cáo.');
        }

        return self::SUCCESS;
    }
}
