<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Ghép hoa hồng + thưởng + lệnh rút thành một dòng thời gian biến động ví, kèm số dư trước/sau
 * từng dòng. Số dư tính đúng theo User::availableBalance(): cộng commission 'approved', trừ lệnh
 * rút chưa bị từ chối. Các dòng không đụng tới số dư (commission pending, lệnh rút bị từ chối)
 * vẫn hiện để khách hiểu, nhưng delta = 0.
 */
class WalletHistoryService
{
    /**
     * @return Collection<int, array<string, mixed>> mới nhất trước
     */
    public function timeline(User $user): Collection
    {
        $events = collect();

        foreach ($user->commissions()->get() as $c) {
            // 'bonus' gom cả thưởng người mới lẫn quà điểm danh: phía giao diện, 'kind' chỉ quyết định
            // màu và biểu tượng của dòng, mà hai loại này cùng là tiền tặng — thứ phân biệt chúng
            // là 'title'. Thêm một kind mới là thêm một ô trong kindStyles của WalletHistory.vue, quên
            // thì dòng đó mất sạch biểu tượng mà không báo gì.
            $isCheckIn = $c->type === Commission::TYPE_CHECKIN;
            $isBonus = $isCheckIn || $c->type === Commission::TYPE_WELCOME_BONUS;
            $counts = $c->status === 'approved';

            $events->push([
                'key' => 'c'.$c->id,
                'at' => $c->confirmed_at ?? $c->created_at,
                'kind' => $isBonus ? 'bonus' : 'cashback',
                'title' => match (true) {
                    $isCheckIn => 'Điểm danh nhận quà',
                    $isBonus => 'Thưởng người mới',
                    default => 'Hoàn tiền đơn hàng',
                },
                'note' => match (true) {
                    $isCheckIn => 'Quà điểm danh hằng ngày',
                    $isBonus => 'Quà tặng khi đăng ký tài khoản',
                    $c->status === 'pending' => 'Đang chờ duyệt — chưa cộng vào ví',
                    $c->status === 'paid' => 'Đã thanh toán ngoài ví',
                    default => $c->order_id ? 'Mã đơn '.$c->order_id : null,
                },
                'delta' => $counts ? (float) $c->amount : 0.0,
                'amount' => (float) $c->amount,
                'status' => $c->status,
            ]);
        }

        foreach ($user->withdrawals()->get() as $w) {
            $held = in_array($w->status, ['pending', 'approved', 'completed'], true);

            $events->push([
                'key' => 'w'.$w->id,
                'at' => $w->created_at,
                'kind' => 'withdrawal',
                'title' => 'Rút tiền về '.($w->provider === 'momo' ? 'MoMo' : 'ZaloPay'),
                'note' => match ($w->status) {
                    'pending' => 'Đang chờ duyệt — tiền được giữ lại',
                    'approved' => 'Đã duyệt, chờ chuyển khoản',
                    'completed' => 'Đã chuyển'.($w->transaction_ref ? ' · '.$w->transaction_ref : ''),
                    'rejected' => 'Bị từ chối — không trừ tiền'.($w->admin_note ? ' · '.$w->admin_note : ''),
                    default => null,
                },
                'delta' => $held ? -(float) $w->amount : 0.0,
                'amount' => (float) $w->amount,
                'status' => $w->status,
            ]);
        }

        // Chạy xuôi thời gian để cộng dồn số dư, rồi lật lại cho mới nhất lên đầu.
        $running = 0.0;

        return $events
            ->sortBy([['at', 'asc'], ['key', 'asc']])
            ->values()
            ->map(function (array $e) use (&$running) {
                $before = $running;
                $running += $e['delta'];

                return array_merge($e, [
                    'at' => $e['at']->toDateTimeString(),
                    'before' => $before,
                    'after' => $running,
                ]);
            })
            ->reverse()
            ->values();
    }
}
