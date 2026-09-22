<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Setting;
use App\Models\ShopeeOrder;
use App\Models\User;
use App\Notifications\CommissionCreditedNotification;
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
     * Con số hiển thị cho khách (trang chủ, /hoan-tien, bài mẫu quảng bá) — TÁCH khỏi tỉ lệ
     * thực trả ở trên theo yêu cầu admin. Để trống thì lấy đúng tỉ lệ thực; chỉ khi admin cố ý
     * nhập một số khác thì hai nơi mới nói khác nhau. Không bao giờ dùng số này để tính tiền.
     */
    public const DISPLAY_RATE_KEY = 'cashback_display_rate';

    /** Hạng thành viên cộng thêm điểm phần trăm vào tỉ lệ trên — xem MembershipTierService. */
    public function __construct(private MembershipTierService $tiers) {}

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
            CashbackLeaderboardService::forget();

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

        // Hạng của từng khách lấy MỘT lần cho cả vòng lặp: một người thường có nhiều đơn trong
        // cùng lượt chạy, tra hạng trong vòng lặp là N+1 ngay giữa khâu ghi tiền.
        $bonuses = $this->tiers->bonusRatesFor(
            $orders->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all()
        );

        foreach ($orders as $order) {
            if ((float) $order->net_total <= 0) {
                continue;
            }

            $this->award(
                $order->order_id,
                (int) $order->user_id,
                (float) $order->net_total,
                $rate,
                $bonuses[(int) $order->user_id] ?? 0.0,
                $summary,
            );
        }

        // Bảng xếp hạng công khai đọc từ cache — vừa ghi/thu hồi tiền xong thì phải xoá, không
        // thì khách vừa được cộng tiền vào /hoan-tien vẫn thấy mình chưa có tên.
        CashbackLeaderboardService::forget();

        return $summary;
    }

    public function rate(): float
    {
        return max(0.0, (float) Setting::get(self::RATE_KEY, 0));
    }

    /**
     * Tỉ lệ đưa ra cho khách xem. Vẫn bám vào công tắc của tỉ lệ thực: chương trình tắt
     * (rate = 0) thì số hiển thị cũng phải về 0 để toàn bộ nội dung hoàn tiền tự ẩn — không
     * được để một con số quảng bá còn sáng trong khi hệ thống trả 0đ cho tất cả mọi người.
     */
    public function displayRate(): float
    {
        $rate = $this->rate();

        if ($rate <= 0) {
            return 0.0;
        }

        $display = Setting::get(self::DISPLAY_RATE_KEY);

        if ($display === null || $display === '') {
            return $rate;
        }

        return max(0.0, (float) $display);
    }

    /**
     * Ghi hoa hồng cho một đơn.
     *
     * status 'approved' ngay chứ không phải 'pending': tới đây đơn ĐÃ "Hoàn thành" bên Shopee,
     * tức đã qua cửa sổ hoàn trả và hoa hồng chắc chắn về. Đơn chưa hoàn thành không bao giờ đi
     * tới hàm này — đó là điểm chặn duy nhất giữa "đơn còn huỷ được" và "khách rút được tiền".
     *
     * @param  float  $bonus  điểm phần trăm thưởng theo hạng thành viên, chỉ áp cho khoản GHI MỚI
     * @param  array<string, int|float>  $summary
     */
    private function award(string $orderId, int $userId, float $netTotal, float $rate, float $bonus, array &$summary): void
    {
        DB::transaction(function () use ($orderId, $userId, $netTotal, $rate, $bonus, &$summary) {
            $commission = Commission::where('order_id', $orderId)->lockForUpdate()->first();

            // Khoản đã ghi giữ nguyên phần thưởng hạng của LÚC GHI (cột tier_bonus_rate), không
            // lấy hạng hiện tại: sync() tính lại toàn bộ đơn ở mỗi lượt chạy, nên đọc hạng hiện
            // tại là mỗi lần khách lên hạng thì tiền của các quý cũ tự phình theo. NULL = khoản
            // ghi từ trước khi có tính năng hạng, đọc ra 0 nên số tiền cũ đứng yên.
            $bonus = $commission !== null ? (float) ($commission->tier_bonus_rate ?? 0) : $bonus;

            // Cộng ĐIỂM phần trăm vào tỉ lệ nền, chặn trần 100%: tỉ lệ nền là phần hoa hồng ròng
            // chia lại, trả quá 100% là trả nhiều hơn số Shopee đưa cho mình.
            $amount = round($netTotal * min(100.0, $rate + $bonus) / 100, 2);

            if ($amount <= 0) {
                return;
            }

            if ($commission === null) {
                Commission::create([
                    'user_id' => $userId,
                    'affiliate_link_id' => null,
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'tier_bonus_rate' => $bonus,
                    'status' => 'approved',
                    'confirmed_at' => now(),
                ]);

                $summary['created']++;

                // Báo cho khách đúng lúc tiền vào ví — chỉ khi TẠO mới. Shopee chỉnh lại số
                // sau đó (đổi trả một phần) đi nhánh update dưới, không báo: một chuông "trừ
                // 3.000 đ" chỉ gây hoang mang mà khách cũng chẳng làm gì được.
                User::find($userId)?->notify(new CommissionCreditedNotification($amount, $orderId));

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
