<?php

namespace App\Console\Commands;

use App\Models\ShopeeOrder;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use App\Services\CashbackService;
use Illuminate\Console\Command;

/**
 * Gửi bù chuông "Đã ghi nhận đơn hàng mới" cho các đơn đang chờ đã nhập TRƯỚC khi có tính năng
 * thông báo (ShopeeReportImportService chỉ báo cho đơn mới xuất hiện trong lần nhập). Chạy một
 * lần sau khi deploy; chạy lại cũng không sao — đơn đã báo rồi thì bỏ qua nhờ khoá order_id
 * trong data của thông báo.
 *
 *   php artisan orders:notify-backfill            # xem sẽ gửi cho ai, chưa gửi
 *   php artisan orders:notify-backfill --send     # gửi thật
 */
class NotifyPendingOrdersBackfill extends Command
{
    protected $signature = 'orders:notify-backfill {--send : Gửi thật; bỏ trống thì chỉ liệt kê}';

    protected $description = 'Gửi bù thông báo "đơn mới" cho các đơn đang chờ Shopee xác nhận chưa từng được báo';

    public function handle(CashbackService $cashback): int
    {
        $rate = $cashback->rate();
        $send = $this->option('send');

        // Gộp theo đơn như OrderHistoryController: một đơn nhiều món là nhiều dòng. Chỉ lấy đơn
        // mà KHÔNG dòng nào đã hoàn thành/huỷ — đơn đó không còn là "đang chờ" nữa.
        $orders = ShopeeOrder::query()
            ->selectRaw('user_id, order_id, MIN(product_name) as product_name, COUNT(*) as line_count, SUM(net_commission) as net_total')
            ->whereNotNull('user_id')
            ->groupBy('user_id', 'order_id')
            ->havingRaw("SUM(CASE WHEN status <> 'pending' THEN 1 ELSE 0 END) = 0")
            ->get();

        $users = User::whereIn('id', $orders->pluck('user_id')->unique())->get()->keyBy('id');
        $sent = 0;

        foreach ($orders->groupBy('user_id') as $userId => $userOrders) {
            $user = $users->get($userId);

            if (! $user) {
                continue;
            }

            $notified = $user->notifications()
                ->where('type', NewOrderNotification::class)
                ->get()
                ->map(fn ($n) => $n->data['order_id'] ?? null)
                ->filter()
                ->flip();

            foreach ($userOrders as $o) {
                if ($notified->has($o->order_id)) {
                    continue;
                }

                $estimate = $rate > 0 ? round(((float) $o->net_total) * $rate / 100, 2) : null;
                $this->line(sprintf('%s <%s> — đơn %s (%s dòng) dự kiến %s', $user->name, $user->email, $o->order_id, $o->line_count, $estimate === null ? '—' : number_format($estimate, 0, ',', '.').' đ'));

                if ($send) {
                    $user->notify(new NewOrderNotification($o->order_id, $o->product_name, max(0, (int) $o->line_count - 1), $estimate));
                }

                $sent++;
            }
        }

        $this->info($send ? "Đã gửi {$sent} thông báo." : "Sẽ gửi {$sent} thông báo. Thêm --send để gửi thật.");

        return self::SUCCESS;
    }
}
