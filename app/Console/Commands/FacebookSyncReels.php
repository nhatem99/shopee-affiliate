<?php

namespace App\Console\Commands;

use App\Services\FacebookReelSyncService;
use Illuminate\Console\Command;

/**
 * Đọc caption thật của từng reel trong nhóm và cập nhật bảng facebook_reel_slots cho khớp —
 * xem FacebookReelSyncService. Chạy theo lịch 10 phút/lần; chạy tay để xem trạng thái hiện tại:
 *
 *   php artisan facebook:sync-reels
 */
class FacebookSyncReels extends Command
{
    protected $signature = 'facebook:sync-reels';

    protected $description = 'Đối soát bảng slot reel với caption thật trên Facebook';

    public function handle(FacebookReelSyncService $sync): int
    {
        $results = $sync->sync();

        if (! $results) {
            $this->line('Chưa bật cấu hình Facebook hoặc chưa có reel nào trong nhóm — không có gì để đối soát.');

            return self::SUCCESS;
        }

        $this->table(
            ['Reel', 'Kết quả', 'Đang hiện sản phẩm', 'Lỗi'],
            array_map(fn (array $r) => [
                $r['reel_id'],
                $r['status'],
                $r['product_key'] ?? '(trống)',
                $r['error'] ? mb_strimwidth($r['error'], 0, 60, '…') : '',
            ], $results),
        );

        return self::SUCCESS;
    }
}
