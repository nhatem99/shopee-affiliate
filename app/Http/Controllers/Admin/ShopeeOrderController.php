<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopeeOrder;
use App\Services\CashbackService;
use App\Services\ShopeeReportImportService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Nhập báo cáo hoa hồng affiliate Shopee (file CSV xuất tay) và xem lại những gì đã nhập.
 *
 * Shopee không có API trả báo cáo cho tài khoản affiliate cá nhân, nên upload tay là đường duy
 * nhất đưa số liệu doanh thu thật vào hệ thống.
 */
class ShopeeOrderController extends Controller
{
    private const STATUSES = ['pending', 'completed', 'cancelled'];

    public function __construct(
        private ShopeeReportImportService $importer,
        private CashbackService $cashback,
    ) {}

    public function index(Request $request): Response
    {
        $orders = $this->filteredQuery($request)
            ->with('user')
            ->latest('ordered_at')
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (ShopeeOrder $o) => [
                'id' => $o->id,
                'order_id' => $o->order_id,
                'product_name' => $o->product_name,
                'shop_name' => $o->shop_name,
                'quantity' => $o->quantity,
                'net_commission' => $o->net_commission,
                'status' => $o->status,
                'order_status_raw' => $o->order_status_raw,
                'sub_id_raw' => $o->sub_id_raw,
                'user_sub_id' => $o->user_sub_id,
                'user' => $o->user?->name,
                'channel' => $o->channel,
                'ordered_at' => $o->ordered_at?->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Admin/ShopeeOrders', [
            'orders' => $orders,
            'filters' => $this->activeFilters($request),
            'stats' => $this->stats($request),
            'cashbackRate' => $this->cashback->rate(),
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        // Cố ý KHÔNG chặn theo mime: trình duyệt trả mime của .csv rất tuỳ máy (text/csv,
        // application/vnd.ms-excel, application/octet-stream...) và chặn ở đây thì admin bị từ
        // chối một file hoàn toàn hợp lệ mà không hiểu vì sao. Việc kiểm tra đúng định dạng đã
        // do ShopeeReportImportService::readHeader() làm, kèm thông báo nói rõ thiếu cột nào.
        $request->validate([
            'report' => ['required', 'file', 'max:20480'],
        ], [
            'report.max' => 'File quá lớn (tối đa 20MB).',
        ]);

        try {
            $summary = $this->importer->import($request->file('report')->getRealPath());
        } catch (RuntimeException $e) {
            return back()->withErrors(['report' => $e->getMessage()]);
        }

        // Chạy luôn khâu chia tiền: nhập xong mà tiền chưa vào ví thì admin không có cách nào
        // biết là còn thiếu một bước.
        $cashback = $this->cashback->sync();

        Log::info('ShopeeOrderController: đã nhập báo cáo hoa hồng', $summary + ['cashback' => $cashback]);

        return back()->with('success', $this->message($summary, $cashback));
    }

    // ── Lọc ───────────────────────────────────────────────────────────────────

    /**
     * Lọc theo NGÀY ĐẶT HÀNG (ordered_at), không phải ngày nhập file hay ngày hoàn thành: đó là
     * mốc admin nhớ được khi đối chiếu với trang affiliate Shopee, vì báo cáo bên đó cũng chia kỳ
     * theo ngày đặt.
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = $this->dateScopedQuery($request);

        if (in_array($request->input('status'), self::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        // Lọc riêng những dòng CÓ mã khách nhưng chưa khớp tài khoản nào — đó là dấu hiệu mã bị
        // cắt sai hoặc tài khoản đã bị xoá, tức tiền đang không về được tay ai.
        if ($request->boolean('unmatched')) {
            $query->whereNotNull('user_sub_id')->whereNull('user_id');
        }

        return $query;
    }

    /**
     * Chỉ áp khoảng ngày. Tách riêng để phần thống kê đi theo kỳ đang xem mà KHÔNG bị bó theo
     * trạng thái đang lọc — thống kê vốn để chia nhỏ theo trạng thái, lọc sẵn thì các ô kia
     * luôn bằng 0 và nhìn như mất dữ liệu.
     */
    private function dateScopedQuery(Request $request): Builder
    {
        $query = ShopeeOrder::query();

        if ($from = $this->date($request->input('from'))) {
            $query->whereDate('ordered_at', '>=', $from);
        }

        if ($to = $this->date($request->input('to'))) {
            $query->whereDate('ordered_at', '<=', $to);
        }

        return $query;
    }

    /**
     * @return array<string, string|bool>
     */
    private function activeFilters(Request $request): array
    {
        $filters = [
            'status' => in_array($request->input('status'), self::STATUSES, true) ? $request->input('status') : null,
            'unmatched' => $request->boolean('unmatched') ?: null,
            'from' => $this->date($request->input('from')),
            'to' => $this->date($request->input('to')),
        ];

        return array_filter($filters, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Chỉ nhận đúng dạng YYYY-MM-DD và phải là ngày CÓ THẬT.
     *
     * checkdate() chặn '2026-02-31': dạng chuỗi thì hợp lệ nhưng MySQL coi là ngày rỗng và trả
     * về không dòng nào — admin sẽ tưởng kỳ đó không có đơn thay vì biết mình gõ sai.
     */
    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }

    // ── Thống kê / thông báo ──────────────────────────────────────────────────

    /**
     * @param  array{rows: int, created: int, updated: int, matched: int, unmatched: int}  $summary
     * @param  array{created: int, updated: int, revoked: int, rate: float, orders: int}  $cashback
     */
    private function message(array $summary, array $cashback): string
    {
        $parts = [
            "Đã đọc {$summary['rows']} dòng ({$summary['created']} mới, {$summary['updated']} cập nhật).",
            "Quy được về khách: {$summary['matched']} dòng, chưa quy được: {$summary['unmatched']}.",
        ];

        if ($cashback['rate'] <= 0) {
            // Nói thẳng vì đây là lý do số 1 khiến "nhập xong mà không thấy tiền đâu".
            $parts[] = 'CHƯA đặt tỉ lệ hoàn tiền nên chưa trả đồng nào — vào Cài đặt để đặt tỉ lệ.';
        } else {
            $parts[] = "Hoàn tiền: {$cashback['created']} mới, {$cashback['updated']} cập nhật, {$cashback['revoked']} thu hồi.";
        }

        return implode(' ', $parts);
    }

    /**
     * Thống kê của ĐÚNG kỳ đang xem — đổi khoảng ngày thì mọi con số đổi theo. Thống kê toàn thời
     * gian trong khi bảng bên dưới chỉ hiện một tháng là kiểu sai lệch rất khó nhận ra.
     *
     * @return array<string, int|float>
     */
    private function stats(Request $request): array
    {
        // Đếm theo ĐƠN (order_id) chứ không theo dòng: một đơn nhiều sản phẩm chiếm nhiều dòng,
        // đếm dòng sẽ thổi phồng số đơn lên.
        $ordersByStatus = fn (string $status) => $this->dateScopedQuery($request)
            ->where('status', $status)
            ->distinct()
            ->count('order_id');

        return [
            'rows' => $this->dateScopedQuery($request)->count(),
            'orders' => $this->dateScopedQuery($request)->distinct()->count('order_id'),
            'completed_orders' => $ordersByStatus('completed'),
            'pending_orders' => $ordersByStatus('pending'),
            'cancelled_orders' => $ordersByStatus('cancelled'),
            'completed_commission' => (float) $this->dateScopedQuery($request)
                ->where('status', 'completed')
                ->sum('net_commission'),
            'unmatched_rows' => $this->dateScopedQuery($request)
                ->whereNotNull('user_sub_id')
                ->whereNull('user_id')
                ->count(),
            'no_code_rows' => $this->dateScopedQuery($request)->whereNull('user_sub_id')->count(),
        ];
    }
}
