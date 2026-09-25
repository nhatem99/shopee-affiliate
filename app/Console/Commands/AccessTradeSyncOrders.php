<?php

namespace App\Console\Commands;

use App\Models\ShopeeOrder;
use App\Services\AccessTradeOrderImportService;
use App\Services\AccessTradeService;
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
    protected $signature = 'accesstrade:sync-orders
        {--days=30 : Số ngày lùi về cần quét lại}
        {--force : Chạy kể cả khi thẻ AccessTrade đang tắt}';

    protected $description = 'Đồng bộ đơn TikTok Shop từ ACCESSTRADE và cộng tiền hoàn cho khách';

    public function handle(AccessTradeOrderImportService $importer, AccessTradeService $accessTrade): int
    {
        // Thẻ AccessTrade ở /admin/api-config đang tắt = không gọi gì sang họ, không ghi đơn,
        // không cộng tiền. Trước đây lệnh này chạy vô điều kiện nên gạt công tắc trên giao diện
        // không tắt được gì — với một đường vừa gọi API ngoài vừa ghi tiền vào ví khách thì
        // phải có phanh bấm được từ giao diện, không phải sửa code rồi deploy.
        if (! $accessTrade->enabled() && ! $this->option('force')) {
            $this->line('Thẻ AccessTrade ở /admin/api-config đang tắt — bỏ qua. Dùng --force để chạy thử.');

            return self::SUCCESS;
        }

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

        // Lưới an toàn cho đúng cái bẫy của API này: since/until lọc theo NGÀY PHÁT SINH ĐƠN,
        // trong khi cờ đối soát (is_confirmed — điều kiện để tiền chảy vào ví) về theo kỳ, có
        // thể sau cả tháng. Đơn nào rơi ra khỏi cửa sổ quét trước khi được xác nhận sẽ treo
        // 'pending' vĩnh viễn: không log, không lỗi, khách chỉ đơn giản là không bao giờ nhận
        // được tiền. Đếm ở đây để chuyện đó không thể xảy ra im lặng.
        $treo = ShopeeOrder::where('platform', ShopeeOrder::PLATFORM_TIKTOK)
            ->where('status', 'pending')
            ->where('ordered_at', '<', now()->subDays($days))
            ->count();

        if ($treo > 0) {
            $this->warn("⚠️ {$treo} đơn TikTok quá {$days} ngày vẫn 'đang chờ' — chúng nằm NGOÀI cửa sổ quét nên sẽ không bao giờ được cập nhật nữa. Chạy lại với --days lớn hơn.");
        }

        return self::SUCCESS;
    }
}
