<?php

namespace App\Services;

use App\Models\ShopeeOrder;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kéo đơn TikTok Shop từ ACCESSTRADE (GET /v1/order-list) về bảng shopee_orders, quy về đúng
 * khách qua ô utm_content, rồi để CashbackService cộng tiền như đơn Shopee.
 *
 * Khác đường Shopee ở một điểm lớn: Shopee không có API cho tài khoản affiliate cá nhân nên
 * admin phải xuất CSV rồi tải lên tay (ShopeeReportImportService). Bên này có API, nên chạy
 * được theo lịch và khách không phải chờ ai nhớ tải báo cáo.
 *
 * Ràng buộc của họ, đo thật 25-09-2026:
 *   • Mỗi lần gọi chỉ lấy được khoảng thời gian NGẮN HƠN 31 NGÀY — quá thì trả HTTP 500
 *     "sales date range must be less than 31 days". Khoảng dài hơn phải tự cắt thành nhiều lượt.
 *   • Phân trang: page bắt đầu từ 1, limit mặc định 30, tối đa 300.
 *   • Toàn tài khoản giới hạn 30 request/phút.
 *
 * Một dòng trả về = một ĐƠN (không phải một dòng sản phẩm như báo cáo Shopee), nên không có
 * chuyện gộp nhiều dòng theo order_id ở đây.
 */
class AccessTradeOrderImportService
{
    /** Ngắn hơn 31 ngày theo đúng lời API. Để 30 cho tròn và còn biên. */
    private const MAX_WINDOW_DAYS = 30;

    /** Trần của họ là 300. Lấy sát trần để ít vòng gọi nhất — mỗi vòng là một nhịp trong quota 30/phút. */
    private const PAGE_SIZE = 300;

    /** Chặn vòng lặp vô tận nếu phân trang của họ không bao giờ cạn. */
    private const MAX_PAGES = 50;

    private const TIMEOUT = 30;

    /**
     * Đơn lần đầu được gán cho khách trong lượt chạy này — để báo chuông đúng một lần mỗi đơn.
     * Cùng ý đồ với ShopeeReportImportService::$newOrders.
     *
     * @var array<string, array{user_id: int, order_id: string, product_name: ?string, net_total: float, status: string}>
     */
    private array $newOrders = [];

    public function __construct(
        private AccessTradeService $accessTrade,
        private CashbackService $cashback,
    ) {}

    /**
     * @param  int  $days  Số ngày lùi về kể từ bây giờ. Đơn có thể đổi trạng thái (chờ → duyệt/
     *                     từ chối) hàng tuần sau khi đặt, nên phải quét lại quá khứ chứ không
     *                     chỉ lấy đơn mới.
     * @return array{fetched: int, created: int, updated: int, matched: int, unmatched: int, windows: int}
     */
    public function import(int $days = 30): array
    {
        $summary = ['fetched' => 0, 'created' => 0, 'updated' => 0, 'matched' => 0, 'unmatched' => 0, 'windows' => 0];
        $this->newOrders = [];

        // Tra sub_id => user một lần cho cả lượt chạy, không tra từng đơn: lượt quét 30 ngày có
        // thể trả về hàng trăm đơn, tra trong vòng lặp là N+1 ngay giữa khâu ghi tiền.
        $users = User::whereNotNull('sub_id')->pluck('id', 'sub_id')->all();

        $until = now();
        $since = now()->subDays(max(1, $days));

        foreach ($this->windows($since, $until) as [$from, $to]) {
            $summary['windows']++;

            foreach ($this->fetchWindow($from, $to) as $row) {
                $summary['fetched']++;
                $this->storeOrder($row, $users, $summary);
            }
        }

        $this->notifyNewOrders();

        // Cộng tiền ngay trong cùng lượt chạy: nhập xong mà không cộng thì đơn nằm im trong bảng
        // tới lần đồng bộ sau, khách thấy đơn "Hoàn thành" mà ví không nhúc nhích.
        $this->cashback->sync();

        Log::info('AccessTradeOrderImportService: đồng bộ xong', $summary);

        return $summary;
    }

    /**
     * Cắt khoảng thời gian thành từng lát < 31 ngày.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function windows(Carbon $since, Carbon $until): array
    {
        $windows = [];
        $cursor = $since->copy();

        while ($cursor->lt($until)) {
            $end = $cursor->copy()->addDays(self::MAX_WINDOW_DAYS);
            $windows[] = [$cursor->copy(), $end->gt($until) ? $until->copy() : $end];
            $cursor = $end;
        }

        return $windows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchWindow(Carbon $from, Carbon $to): array
    {
        $params = $this->accessTrade->apiParams();

        if ($params['api_key'] === '') {
            Log::warning('AccessTradeOrderImportService: chưa cấu hình API key, không đồng bộ được đơn');

            return [];
        }

        $orders = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            try {
                $response = Http::withHeaders(['Authorization' => 'Token '.$params['api_key']])
                    ->timeout(self::TIMEOUT)
                    ->get($params['endpoint'].'/order-list', [
                        'since' => $from->toIso8601ZuluString(),
                        'until' => $to->toIso8601ZuluString(),
                        'page' => $page,
                        'limit' => self::PAGE_SIZE,
                    ]);
            } catch (\Exception $e) {
                Log::warning('AccessTradeOrderImportService: lỗi khi gọi order-list: '.$e->getMessage());

                return $orders;
            }

            if (! $response->successful()) {
                // Dừng hẳn lát này thay vì đi tiếp: đọc thiếu một trang giữa chừng mà vẫn chạy
                // tiếp là tự tạo ra khoảng trống dữ liệu không ai biết.
                Log::warning('AccessTradeOrderImportService: order-list trả lỗi', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 300),
                    'since' => $from->toDateString(),
                    'until' => $to->toDateString(),
                ]);

                return $orders;
            }

            $rows = $response->json('data') ?? [];
            $orders = array_merge($orders, $rows);

            if (count($rows) < self::PAGE_SIZE) {
                return $orders;
            }
        }

        Log::warning('AccessTradeOrderImportService: chạm trần số trang, có thể còn đơn chưa lấy', [
            'since' => $from->toDateString(),
            'until' => $to->toDateString(),
        ]);

        return $orders;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $users
     * @param  array<string, int>  $summary
     */
    private function storeOrder(array $row, array $users, array &$summary): void
    {
        $orderId = (string) ($row['order_id'] ?? '');

        if ($orderId === '') {
            return;
        }

        $subId = $this->extractUserSubId($row);
        $userId = $subId !== null ? ($users[$subId] ?? null) : null;
        $summary[$userId !== null ? 'matched' : 'unmatched']++;

        $status = $this->status($row);

        $order = ShopeeOrder::updateOrCreate(
            [
                'platform' => ShopeeOrder::PLATFORM_TIKTOK,
                'order_id' => $orderId,
                // Một dòng = một đơn, không có dòng sản phẩm riêng. Để chuỗi rỗng chứ không NULL:
                // MySQL cho nhiều NULL trùng nhau trong unique index, để NULL là khoá chống trùng
                // mất tác dụng đúng ở mọi đơn TikTok.
                'item_id' => '',
                'model_id' => '',
            ],
            [
                'product_name' => $this->productName($row),
                'quantity' => (int) ($row['products_count'] ?? 0),
                'order_value' => (float) ($row['billing'] ?? 0),
                // pub_commission là số về túi mình, tương đương cột "Hoa hồng ròng" của Shopee —
                // mọi phép chia cho khách phải tính trên nó.
                'net_commission' => (float) ($row['pub_commission'] ?? 0),
                'total_order_commission' => (float) ($row['pub_commission'] ?? 0),
                'order_status_raw' => $this->rawStatus($row),
                'status' => $status,
                'sub_id_raw' => ($row['utm_content'] ?? '') !== '' ? (string) $row['utm_content'] : null,
                'user_sub_id' => $subId,
                'user_id' => $userId,
                'channel' => ($row['merchant'] ?? '') !== '' ? (string) $row['merchant'] : null,
                'clicked_at' => $this->time($row['click_time'] ?? null),
                'ordered_at' => $this->time($row['sales_time'] ?? null),
                'completed_at' => $status === 'completed' ? $this->time($row['confirmed_time'] ?? null) : null,
            ],
        );

        $summary[$order->wasRecentlyCreated ? 'created' : 'updated']++;

        if ($userId !== null && $order->wasRecentlyCreated) {
            $this->newOrders[$userId.':'.$orderId] = [
                'user_id' => $userId,
                'order_id' => $orderId,
                'product_name' => $this->productName($row),
                'net_total' => (float) ($row['pub_commission'] ?? 0),
                'status' => $status,
            ];
        }
    }

    /**
     * Quy đơn về ba trạng thái của bảng mình.
     *
     * ACCESSTRADE không có một ô "trạng thái" duy nhất: mỗi đơn mang ba con số ĐẾM SỐ MÓN theo
     * từng trạng thái (order_pending / order_approved / order_reject) cộng với is_confirmed —
     * cờ báo giao dịch đã qua ĐỐI SOÁT của họ với sàn.
     *
     * 'completed' cố ý đòi is_confirmed = 1, chặt hơn mức tối thiểu:
     *   • 'completed' là cánh cửa duy nhất dẫn tới việc cộng tiền vào ví khách
     *     (CashbackService::sync), và tiền đã ghi 'paid' thì không thu hồi lại được.
     *   • Đơn ở trạng thái "approved" nhưng chưa đối soát vẫn còn lật về reject được — lúc đó
     *     mình đã trả tiền cho khách bằng tiền chưa bao giờ về.
     * Đổi lại khách nhận tiền chậm hơn (theo kỳ đối soát của ACCESSTRADE). Đây là đánh đổi cố ý,
     * nếu muốn trả sớm thì nới ở đúng một dòng dưới đây.
     *
     * @param  array<string, mixed>  $row
     */
    private function status(array $row): string
    {
        $pending = (int) ($row['order_pending'] ?? 0);
        $approved = (int) ($row['order_approved'] ?? 0);
        $rejected = (int) ($row['order_reject'] ?? 0);
        $confirmed = (int) ($row['is_confirmed'] ?? 0);

        // Cả đơn bị từ chối: thu hồi tiền nếu lỡ ghi rồi (CashbackService::revokeCancelled).
        if ($rejected > 0 && $approved === 0 && $pending === 0) {
            return 'cancelled';
        }

        if ($confirmed === 1 && $approved > 0 && $pending === 0) {
            return 'completed';
        }

        return 'pending';
    }

    /** Giữ nguyên văn mấy con số của họ để đối chiếu khi cách đánh dấu thay đổi. */
    private function rawStatus(array $row): string
    {
        return sprintf(
            'pending=%d approved=%d reject=%d confirmed=%d',
            (int) ($row['order_pending'] ?? 0),
            (int) ($row['order_approved'] ?? 0),
            (int) ($row['order_reject'] ?? 0),
            (int) ($row['is_confirmed'] ?? 0),
        );
    }

    /**
     * Mã khách nằm ở utm_content — ô duy nhất mình gửi đi mà CHẮC CHẮN quay về trong báo cáo
     * này (sub1..sub4 không có tên trong danh sách trường trả về của họ).
     *
     * Nhận cả hai dạng: đúng mã khách (dạng mình gửi cho TikTok), hoặc chuỗi 5 khe kiểu Shopee
     * "fb-u7k2m9---" phòng khi cùng một ô được dùng lại cho đường khác.
     *
     * @param  array<string, mixed>  $row
     */
    private function extractUserSubId(array $row): ?string
    {
        $raw = trim((string) ($row['utm_content'] ?? ''));

        if ($raw === '') {
            return null;
        }

        if (! str_contains($raw, '-')) {
            return $raw;
        }

        $parts = explode('-', $raw);

        return isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;
    }

    /** @param array<string, mixed> $row */
    private function productName(array $row): ?string
    {
        foreach (['product_category', 'category_name', 'merchant'] as $key) {
            if (($row[$key] ?? '') !== '') {
                return (string) $row[$key];
            }
        }

        return null;
    }

    private function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Báo chuông "có đơn mới" cho khách — chỉ với đơn còn ĐANG CHỜ. Đơn đã duyệt xong thì
     * CashbackService::sync() cộng tiền và tự gửi thông báo tiền vào ví, báo thêm ở đây là hai
     * chuông cho một lần mua.
     */
    private function notifyNewOrders(): void
    {
        $pending = array_filter($this->newOrders, fn (array $o) => $o['status'] === 'pending');

        if ($pending === []) {
            $this->newOrders = [];

            return;
        }

        $rate = $this->cashback->rate();
        $users = User::whereIn('id', array_column($pending, 'user_id'))->get()->keyBy('id');

        foreach ($pending as $order) {
            $users->get($order['user_id'])?->notify(new NewOrderNotification(
                $order['order_id'],
                $order['product_name'],
                // Một dòng = một đơn nên không có "sản phẩm khác" để đếm thêm như bên Shopee.
                0,
                $rate > 0 ? round($order['net_total'] * $rate / 100, 2) : null,
            ));
        }

        $this->newOrders = [];
    }
}
