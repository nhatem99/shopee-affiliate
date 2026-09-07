<?php

namespace App\Console\Commands;

use App\Models\ApiConfig;
use App\Services\FacebookPageService;
use App\Services\FacebookPostTarget;
use Illuminate\Console\Command;

/**
 * Đọc và (tuỳ chọn) đổi caption của một reel trên fanpage, rồi ĐỌC LẠI để xác nhận.
 *
 * Lý do có lệnh này: Meta không tài liệu hoá đường sửa caption reel sau khi đăng — trang Reels
 * Publishing chỉ cho đặt `description` lúc publish, node Video không liệt kê thao tác cập nhật.
 * Chạy thử ngày 2026-09-08 thì POST /{reel_id} với field `description` ĂN, nhưng vì không có
 * tài liệu nên Meta có thể bỏ bất cứ lúc nào — giữ lệnh này để kiểm tra lại khi cần.
 *
 * Lệnh này cố tình đọc lại caption sau khi đổi thay vì tin vào giá trị trả về, vì Graph API có
 * thể trả {"success": true} mà nội dung không hề thay đổi.
 *
 *   php artisan facebook:reel-caption                       # liệt kê reels của page + ID
 *   php artisan facebook:reel-caption https://www.facebook.com/reel/123456
 *   php artisan facebook:reel-caption 123456 --message="Caption mới"
 */
class FacebookReelCaption extends Command
{
    protected $signature = 'facebook:reel-caption
        {reel? : Reel ID hoặc link reel. Bỏ trống thì liệt kê reels của page để bạn lấy ID}
        {--message= : Caption mới. Bỏ trống thì chỉ đọc caption hiện tại, không sửa gì}';

    protected $description = 'Đọc/đổi caption reel trên fanpage và kiểm chứng caption đã đổi thật chưa';

    public function handle(): int
    {
        $config = ApiConfig::where('platform', 'facebook')->where('is_active', true)->first();

        if (! $config || ! $config->app_id || ! $config->app_secret) {
            $this->error('Chưa có cấu hình Facebook đang bật (Page ID + Page Access Token) ở /admin/api-config.');

            return self::FAILURE;
        }

        $service = new FacebookPageService($config->app_id, $config->app_secret);
        $reelArgument = $this->argument('reel');

        // Không truyền reel nào thì liệt kê để lấy ID thật — reels không nằm ở edge /posts nên
        // không thể lấy ID từ danh sách bài viết trong trang admin.
        if ($reelArgument === null) {
            return $this->listReels($service, $config->app_id);
        }

        // Nhận cả link reel lẫn id trần — dán thẳng link copy từ app cho tiện.
        $reelId = FacebookPostTarget::parse($reelArgument)->graphId;

        $this->line("Reel ID: <info>{$reelId}</info>");
        $this->line("Page ID: <info>{$config->app_id}</info>");
        $this->newLine();

        $before = $service->fetchReelCaption($reelId);

        if ($before === null) {
            $this->error('Không đọc được caption hiện tại.');
            $this->line($service->lastError ?? '(không có chi tiết lỗi)');
            $this->newLine();
            $this->comment('Thường do: token không phải Page Access Token của page sở hữu reel, reel ID sai, hoặc token thiếu quyền đọc.');

            return self::FAILURE;
        }

        $this->line('Caption hiện tại:');
        $this->line('<comment>'.$before.'</comment>');
        $this->newLine();

        $message = $this->option('message');

        if ($message === null) {
            $this->info('Chỉ đọc, không sửa. Thêm --message="..." để thử đổi.');

            return self::SUCCESS;
        }

        $this->line('Đang gửi POST /'.$reelId.' với field description...');

        if (! $service->updateReelCaption($reelId, $message)) {
            $this->error('Graph API từ chối lệnh đổi caption.');
            $this->line($service->lastError ?? '(không có chi tiết lỗi)');
            $this->newLine();
            $this->comment('Nếu lỗi là OAuth #200 / "Insufficient permission": token thiếu quyền pages_manage_posts.');
            $this->comment('Nếu lỗi là "Unsupported post request": Meta đã bỏ endpoint không tài liệu hoá này (ngày 2026-09-08 nó còn chạy).');

            return self::FAILURE;
        }

        // Không tin giá trị trả về — đọc lại từ Facebook mới là bằng chứng.
        $after = $service->fetchReelCaption($reelId);

        $this->newLine();
        $this->line('Caption đọc lại sau khi đổi:');
        $this->line('<comment>'.($after ?? '(không đọc được)').'</comment>');
        $this->newLine();

        if ($after === $message) {
            $this->info('ĐỔI ĐƯỢC — caption trên Facebook đã khớp nội dung vừa gửi.');

            return self::SUCCESS;
        }

        $this->warn('Graph API báo thành công NHƯNG caption đọc lại không khớp.');
        $this->comment('Nghĩa là lệnh bị nuốt lặng lẽ — coi như không đổi được, đừng xây tiếp lên trên nó.');

        return self::FAILURE;
    }

    private function listReels(FacebookPageService $service, string $pageId): int
    {
        $this->line("Page ID: <info>{$pageId}</info>");
        $this->line('Đang lấy danh sách reels/video của page...');
        $this->newLine();

        $reels = $service->listReels();

        if (! $reels) {
            $this->error('Không lấy được reel nào.');
            $this->line($service->lastError ?? '(không có chi tiết lỗi)');
            $this->newLine();
            $this->comment('Nếu page thật sự chưa đăng reel nào thì đăng thử một cái rồi chạy lại.');

            return self::FAILURE;
        }

        $this->table(
            ['Reel ID', 'Caption (rút gọn)', 'Đăng lúc'],
            array_map(fn (array $reel) => [
                $reel['id'] ?? '?',
                mb_strimwidth((string) ($reel['description'] ?? ''), 0, 50, '...'),
                $reel['created_time'] ?? '',
            ], $reels),
        );

        $this->comment('Chạy lại kèm một Reel ID ở trên để đọc/đổi caption của nó.');

        return self::SUCCESS;
    }
}
