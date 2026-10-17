<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\DealngonMoveNotification;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Gửi chuông "Hoàn tiền đã chuyển sang dealngon" cho mọi tài khoản khách đã từng vào web
 * (last_seen_at có giá trị) — người đang đăng nhập thấy ngay ở lượt tải trang kế tiếp, người đã
 * đăng xuất thấy khi đăng nhập lại. Chạy lại không gửi trùng: ai đã có thông báo này thì bỏ qua.
 *
 *   php artisan users:notify-dealngon            # xem sẽ gửi cho ai, chưa gửi
 *   php artisan users:notify-dealngon --send     # gửi thật
 *   php artisan users:notify-dealngon --refresh  # đổi nội dung thông báo ĐÃ gửi sang câu chữ hiện tại,
 *                                                # đánh dấu chưa đọc để khách thấy lại
 */
class NotifyDealngonMove extends Command
{
    protected $signature = 'users:notify-dealngon
        {--send : Gửi thật; bỏ trống thì chỉ liệt kê}
        {--refresh : Viết lại nội dung các thông báo đã gửi theo câu chữ hiện tại}';

    protected $description = 'Báo khách đã từng đăng nhập tietkiemvi chuyển sang dealngon (chuông thông báo)';

    public function handle(): int
    {
        if ($this->option('refresh')) {
            return $this->refresh();
        }

        $send = (bool) $this->option('send');

        $users = User::query()
            ->where('role', '!=', 'admin')
            ->whereNotNull('last_seen_at')
            ->whereDoesntHave('notifications', fn ($q) => $q->where('type', DealngonMoveNotification::class))
            ->orderBy('id')
            ->get();

        foreach ($users as $user) {
            $this->line(sprintf('%s <%s>', $user->name, $user->email));

            if ($send) {
                $user->notify(new DealngonMoveNotification);
            }
        }

        $this->info($send
            ? "Đã gửi {$users->count()} thông báo."
            : "Sẽ gửi {$users->count()} thông báo. Thêm --send để gửi thật.");

        return self::SUCCESS;
    }

    /**
     * Nội dung thông báo được chụp vào cột `data` lúc gửi — đổi câu chữ trong
     * DealngonMoveNotification không tự đổi những cái đã gửi. Lệnh này viết lại cho khớp.
     */
    private function refresh(): int
    {
        $count = 0;

        DatabaseNotification::query()
            ->where('type', DealngonMoveNotification::class)
            ->with('notifiable')
            ->each(function (DatabaseNotification $n) use (&$count) {
                $n->forceFill([
                    'data' => (new DealngonMoveNotification)->toArray($n->notifiable),
                    'read_at' => null,
                ])->save();
                $count++;
            });

        $this->info("Đã cập nhật {$count} thông báo.");

        return self::SUCCESS;
    }
}
