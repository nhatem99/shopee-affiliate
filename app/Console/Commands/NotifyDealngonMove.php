<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\DealngonMoveNotification;
use Illuminate\Console\Command;

/**
 * Gửi chuông "Tietkiemvi đã chuyển sang dealngon" cho mọi tài khoản khách đã từng vào web
 * (last_seen_at có giá trị) — người đang đăng nhập thấy ngay ở lượt tải trang kế tiếp, người đã
 * đăng xuất thấy khi đăng nhập lại. Chạy lại không gửi trùng: ai đã có thông báo này thì bỏ qua.
 *
 *   php artisan users:notify-dealngon            # xem sẽ gửi cho ai, chưa gửi
 *   php artisan users:notify-dealngon --send     # gửi thật
 */
class NotifyDealngonMove extends Command
{
    protected $signature = 'users:notify-dealngon {--send : Gửi thật; bỏ trống thì chỉ liệt kê}';

    protected $description = 'Báo khách đã từng đăng nhập tietkiemvi chuyển sang dealngon (chuông thông báo)';

    public function handle(): int
    {
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
}
