<?php

namespace App\Console\Commands;

use App\Services\VoucherCatalogSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Làm mới kho mã của trang /ma-giam-gia.
 *
 * Output in đủ ba con số cho mỗi sàn vì mỗi con số hỏng theo một kiểu khác nhau và chỉ
 * nhìn tổng thì không phân biệt được:
 *
 *  • fetched = 0        → nguồn không trả gì: đổi endpoint, chặn IP, hoặc sập.
 *  • dropped cao        → nguồn còn trả mã nhưng link không đổi được affiliate nữa
 *                         (họ đổi cấu trúc link). Lưu tiếp là kiếm tiền hộ họ, nên mã
 *                         bị bỏ — trang vơi dần mà nguồn vẫn "sống", kiểu hỏng dễ bỏ sót
 *                         nhất trong ba kiểu.
 *  • kept = 0 mà fetched > 0 → hai trường hợp trên gộp lại.
 *  • duplicated cao     → nguồn trả cùng một mã ở nhiều trang. Không phải lỗi bên mình,
 *                         nhưng giải thích vì sao "nhận về" luôn nhiều hơn số mã trên trang.
 */
class SyncVoucherCatalog extends Command
{
    protected $signature = 'vouchers:sync';

    protected $description = 'Đồng bộ kho mã giảm giá toàn sàn cho trang /ma-giam-gia';

    public function handle(VoucherCatalogSyncService $sync): int
    {
        $report = $sync->sync();

        if ($report === []) {
            $this->warn('Chưa bật sàn nào trong config services.voucher_catalog.platforms.');

            return self::SUCCESS;
        }

        $rows = [];
        $failed = false;

        foreach ($report as $platform => $stat) {
            $rows[] = [
                $platform,
                $stat['fetched'],
                $stat['kept'],
                $stat['dropped'],
                $stat['duplicated'],
                $stat['error'] ?? '',
            ];

            if ($stat['error'] !== null || $stat['kept'] === 0) {
                $failed = true;
            }
        }

        $this->table(['Sàn', 'Nhận về', 'Đã lưu', 'Bỏ', 'Trùng', 'Lỗi'], $rows);

        // Số đếm và mốc đồng bộ đang nằm trong cache 5 phút — xoá để trang hiện số mới
        // ngay, thay vì hiện số của lần chạy trước thêm 5 phút nữa.
        Cache::forget('voucher_offers:counts');
        Cache::forget('voucher_offers:last_synced');

        if ($failed) {
            // Thoát khác 0 để /admin/scheduler đánh dấu lượt chạy này là hỏng — mã cũ vẫn
            // còn trong bảng nên khách không thấy gì bất thường, phải có chỗ kêu lên.
            $this->error('Có sàn không lấy được mã nào — xem /admin/logs, tìm "VoucherCatalogSyncService".');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
