<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use App\Models\ShopeeOrder;
use App\Services\CashbackService;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lịch sử từng đơn mua kèm số tiền hoàn của đơn đó, cho phía khách.
 *
 * Trước trang này khách chỉ thấy MỘT con số tổng ở ví, nên ba tình huống dưới đây không có cách
 * nào tự giải thích được — và cả ba đều dẫn thẳng tới khiếu nại:
 *
 *  - Đơn chưa "Hoàn thành" bên Shopee: CashbackService chưa tạo hoa hồng, ví vẫn ₫0, nhìn y hệt
 *    như đơn bị rơi mất.
 *  - Đơn bị huỷ sau khi đã cộng tiền: CashbackService::revokeCancelled() XOÁ bản ghi hoa hồng,
 *    số dư tụt xuống mà không để lại dấu vết nào.
 *  - Đơn không gắn được sub_id: không ai được tính tiền cả.
 *
 * Nguồn dữ liệu là shopee_orders (đơn thô) ghép với commissions (sổ tiền) theo order_id. Tiền
 * hiển thị LẤY TỪ commissions khi có — đó mới là thứ quyết định khách rút được bao nhiêu; phần
 * tính từ tỉ lệ chỉ là ước tính và luôn được gắn nhãn "dự kiến".
 */
class OrderHistoryController extends Controller
{
    public function __construct(private CashbackService $cashback) {}

    public function index(Request $request): Response
    {
        abort_if($request->user()->isAdmin(), 403, 'Trang này dành cho khách hàng.');

        $user = $request->user();
        $rate = $this->cashback->rate();

        $orders = $this->groupedOrders($user->id);
        $commissions = $this->commissionsFor($user->id, $orders);

        $orders->through(fn ($order) => $this->present($order, $commissions->get($order->order_id), $rate));

        // Đơn đang chờ tính trên TOÀN BỘ đơn chứ không riêng trang hiện tại: đây là con số khách
        // đem so với ví, thấy lệch theo trang là mất tin ngay.
        $pendingNet = (float) ShopeeOrder::where('user_id', $user->id)->where('status', 'pending')->sum('net_commission');

        return Inertia::render('Orders', [
            'orders' => $orders,
            'summary' => [
                // Lấy đúng nguồn mà trang Tài khoản dùng, để hai trang không bao giờ nói khác nhau.
                'credited' => $user->approvedCommissionTotal(),
                'pending_estimate' => $rate > 0 ? round($pendingNet * $rate / 100, 2) : null,
                'cancelled_count' => ShopeeOrder::where('user_id', $user->id)
                    ->where('status', 'cancelled')
                    ->distinct()
                    ->count('order_id'),
            ],
            'cashbackRate' => $rate,
        ]);
    }

    /**
     * Gộp theo ĐƠN chứ không theo dòng sản phẩm: một đơn nhiều món nằm trên nhiều dòng trong báo
     * cáo Shopee, và hoa hồng cấp đơn bị dồn hết vào dòng đầu tiên (xem CashbackService::sync).
     */
    private function groupedOrders(int $userId): LengthAwarePaginator
    {
        return ShopeeOrder::query()
            ->where('user_id', $userId)
            ->groupBy('order_id')
            ->select('order_id')
            ->selectRaw('SUM(net_commission) as net_total')
            ->selectRaw('COUNT(*) as line_count')
            ->selectRaw('MIN(product_name) as product_name')
            ->selectRaw('MIN(shop_name) as shop_name')
            ->selectRaw('MAX(ordered_at) as ordered_at')
            ->selectRaw('MAX(completed_at) as completed_at')
            // Quy trạng thái về số trước khi lấy MAX: cột này là enum, mà MAX() trên enum của
            // MySQL so theo thứ tự khai báo còn SQLite (dùng khi chạy test) so theo bảng chữ cái
            // — cùng một dữ liệu ra hai kết quả khác nhau. Đơn có dòng bị huỷ thì tính là huỷ,
            // khớp với cách revokeCancelled() thu hồi tiền theo cả đơn.
            ->selectRaw("MAX(CASE WHEN status = 'cancelled' THEN 2 WHEN status = 'completed' THEN 1 ELSE 0 END) as status_rank")
            ->orderByRaw('MAX(ordered_at) DESC')
            ->orderBy('order_id')
            ->paginate(20);
    }

    /**
     * @return Collection<string, Commission>
     */
    private function commissionsFor(int $userId, LengthAwarePaginator $orders): Collection
    {
        $orderIds = collect($orders->items())->pluck('order_id')->all();

        if ($orderIds === []) {
            return collect();
        }

        return Commission::where('user_id', $userId)
            ->whereIn('order_id', $orderIds)
            ->get()
            ->keyBy('order_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(object $order, ?Commission $commission, float $rate): array
    {
        $cancelled = (int) $order->status_rank === 2;
        $completed = (int) $order->status_rank === 1;

        $status = match (true) {
            $cancelled => 'cancelled',
            $commission?->status === 'paid' => 'paid',
            $commission?->status === 'approved' => 'credited',
            // Đơn đã xong bên Shopee nhưng chưa có dòng tiền: hoặc báo cáo chưa nhập tới kỳ này,
            // hoặc tỉ lệ đang là 0. Không hứa con số nào, chỉ nói là đang đối soát.
            $completed => 'reconciling',
            default => 'waiting',
        };

        $amount = match (true) {
            $cancelled => 0.0,
            $commission !== null => (float) $commission->amount,
            $rate > 0 => round(((float) $order->net_total) * $rate / 100, 2),
            default => null,
        };

        return [
            'order_id' => $order->order_id,
            'product_name' => $order->product_name,
            'shop_name' => $order->shop_name,
            'other_items' => max(0, (int) $order->line_count - 1),
            'amount' => $amount,
            // Chưa có bản ghi hoa hồng nghĩa là con số trên chỉ được suy ra từ tỉ lệ hiện tại —
            // giao diện phải nói rõ "dự kiến", vì tỉ lệ đổi là số đó đổi theo.
            'is_estimate' => $commission === null && $amount !== null && ! $cancelled,
            'status' => $status,
            'ordered_at' => $this->date($order->ordered_at),
            'completed_at' => $this->date($order->completed_at),
        ];
    }

    /**
     * ordered_at/completed_at vẫn đi qua cast datetime của model dù được chọn bằng MAX() — nhưng
     * chỉ khi driver trả về đúng định dạng; nhận cả hai kiểu cho chắc.
     */
    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof DateTimeInterface
            ? $value->format('d/m/Y')
            : Carbon::parse((string) $value)->format('d/m/Y');
    }
}
