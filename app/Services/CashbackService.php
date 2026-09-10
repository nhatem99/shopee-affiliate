<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Setting;
use App\Models\ShopeeOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Biến đơn hàng đã nhập từ báo cáo Shopee thành tiền trong ví khách.
 *
 * Tiền BẮT BUỘC phải chảy vào bảng `commissions`: đó là thứ User::approvedCommissionTotal() đọc,
 * và cũng là thứ availableBalance() dùng để cho phép rút. Ghi vào chỗ khác thì khách không rút
 * được. shopee_orders chỉ là dữ liệu thô, không phải sổ tiền.
 */
class CashbackService
{
    /** Phần trăm hoa hồng ròng trả lại cho khách. 0 = chưa cấu hình, chưa trả đồng nào. */
    public const RATE_KEY = 'cashback_rate';

    /**
     * @return array{created: int, updated: int, revoked: int, rate: float, orders: int}
     */
    public function sync(): array
    {
        $rate = $this->rate();
        $summary = ['created' => 0, 'updated' => 0, 'revoked' => 0, 'rate' => $rate, 'orders' => 0];

        // Đơn bị huỷ được thu hồi TRƯỚC và không phụ thuộc tỉ lệ: kể cả khi admin vừa đặt tỉ lệ
        // về 0 để tạm dừng chương trình, tiền của đơn huỷ vẫn phải rút khỏi ví.
        $summary['revoked'] = $this->revokeCancelled();

        if ($rate <= 0) {
            Log::info('CashbackService: chưa đặt tỉ lệ hoàn tiền, không tạo hoa hồng nào.');

            return $summary;
        }

        // Gộp theo ĐƠN chứ không theo dòng: Shopee dồn hoa hồng cấp đơn vào dòng sản phẩm đầu
        // tiên và để các dòng sau bằng 0, nên cộng theo dòng mới ra đúng số của cả đơn.
        $orders = ShopeeOrder::query()
            ->selectRaw('order_id, user_id, SUM(net_commission) as net_total, MAX(completed_at) as completed_at')
            ->where('status', 'completed')
            ->whereNotNull('user_id')
            ->groupBy('order_id', 'user_id')
            ->get();

        $summary['orders'] = $orders->count();

        foreach ($orders as $order) {
            $amount = round(((float) $order->net_total) * $rate / 100, 2);

            if ($amount <= 0) {
                continue;
            }

            $this->award($order->order_id, (int) $order->user_id, $amount, $summary);
        }

        return $summary;
    }

    public function rate(): float
    {
        return max(0.0, (float) Setting::get(self::RATE_KEY, 0));
    }

    /**
     * Ghi hoa hồng cho một đơn.
     *
     * status 'approved' ngay chứ không phải 'pending': tới đây đơn ĐÃ "Hoàn thành" bên Shopee,
     * tức đã qua cửa sổ hoàn trả và hoa hồng chắc chắn về. Đơn chưa hoàn thành không bao giờ đi
     * tới hàm này — đó là điểm chặn duy nhất giữa "đơn còn huỷ được" và "khách rút được tiền".
     *
     * @param  array<string, int|float>  $summary
     */
    private function award(string $orderId, int $userId, float $amount, array &$summary): void
    {
        DB::transaction(function () use ($orderId, $userId, $amount, &$summary) {
            $commission = Commission::where('order_id', $orderId)->lockForUpdate()->first();

            if ($commission === null) {
                Commission::create([
                    'user_id' => $userId,
                    'affiliate_link_id' => null,
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'status' => 'approved',
                    'confirmed_at' => now(),
                ]);

                $summary['created']++;

                return;
            }

            // Đã trả rồi thì thôi. Shopee vẫn có thể chỉnh hoa hồng sau khi hoàn thành (đổi trả
            // một phần), nhưng đòi lại tiền đã chuyển thì không có đường nào — ghi log để đối
            // soát tay thay vì âm thầm để lệch sổ.
            if ($commission->status === 'paid') {
                if ((float) $commission->amount !== $amount) {
                    Log::warning('CashbackService: hoa hồng đổi sau khi đã chi trả, cần đối soát tay', [
                        'order_id' => $orderId,
                        'da_tra' => (float) $commission->amount,
                        'so_moi' => $amount,
                    ]);
                }

                return;
            }

            if ((float) $commission->amount === $amount && $commission->status === 'approved') {
                return;
            }

            $commission->update([
                'user_id' => $userId,
                'amount' => $amount,
                'status' => 'approved',
                'confirmed_at' => $commission->confirmed_at ?? now(),
            ]);

            $summary['updated']++;
        });
    }

    /**
     * Đơn bị huỷ sau khi đã ghi hoa hồng: phải rút lại, nếu không khách rút được tiền của một
     * đơn không tồn tại.
     *
     * Đơn đã 'paid' thì tiền ra khỏi hệ thống rồi — giữ nguyên bản ghi và kêu lên, vì xoá nó chỉ
     * làm sổ sách sai thêm chứ không lấy lại được đồng nào.
     */
    private function revokeCancelled(): int
    {
        $cancelled = ShopeeOrder::where('status', 'cancelled')->distinct()->pluck('order_id');

        if ($cancelled->isEmpty()) {
            return 0;
        }

        $revoked = 0;

        foreach (Commission::whereIn('order_id', $cancelled)->get() as $commission) {
            if ($commission->status === 'paid') {
                Log::warning('CashbackService: đơn bị huỷ nhưng hoa hồng đã chi trả', [
                    'order_id' => $commission->order_id,
                    'user_id' => $commission->user_id,
                    'amount' => (float) $commission->amount,
                ]);

                continue;
            }

            $commission->delete();
            $revoked++;
        }

        return $revoked;
    }
}
