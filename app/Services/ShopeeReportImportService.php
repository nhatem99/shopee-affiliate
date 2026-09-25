<?php

namespace App\Services;

use App\Models\ShopeeOrder;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Đọc file CSV "Báo cáo hoa hồng affiliate" xuất tay từ trang affiliate Shopee, đổ vào bảng
 * shopee_orders, và quy từng dòng về đúng khách hàng qua cột Sub_id.
 *
 * Vì sao phải nhập tay bằng file: Shopee KHÔNG có API trả báo cáo hoa hồng cho tài khoản
 * affiliate cá nhân. Cho tới khi có, đây là nguồn dữ liệu doanh thu duy nhất.
 */
class ShopeeReportImportService
{
    /**
     * Tên cột (đã chuẩn hoá) => khoá nội bộ. Đọc theo TÊN chứ không theo vị trí: Shopee thêm/bớt
     * cột giữa các phiên bản báo cáo, mà đọc theo vị trí thì lúc đó dữ liệu lệch cột một cách
     * âm thầm — tiền vẫn nhập vào được, chỉ là sai số.
     */
    private const COLUMNS = [
        'id don hang' => 'order_id',
        'trang thai dat hang' => 'order_status_raw',
        'checkout id' => 'checkout_id',
        'thoi gian dat hang' => 'ordered_at',
        'thoi gian hoan thanh' => 'completed_at',
        'thoi gian click' => 'clicked_at',
        'ten shop' => 'shop_name',
        'shop id' => 'shop_id',
        'item id' => 'item_id',
        'ten item' => 'product_name',
        'id model' => 'model_id',
        'gia' => 'price',
        'so luong' => 'quantity',
        'gia tri don hang' => 'order_value',
        'tong hoa hong don hang' => 'total_order_commission',
        'hoa hong rong tiep thi lien ket' => 'net_commission',
        'sub_id1' => 'sub_id1',
        'sub_id2' => 'sub_id2',
        'kenh' => 'channel',
        'content type' => 'content_type',
    ];

    /** Thiếu một trong những cột này thì không có gì để nhập — dừng ngay thay vì nhập nửa vời. */
    private const REQUIRED = ['order_id', 'item_id', 'net_commission', 'order_status_raw'];

    public function __construct(private CashbackService $cashback) {}

    /**
     * Đơn lần đầu được gán cho khách trong lần nhập này, gom theo "user_id:order_id" để báo cho
     * khách MỘT lần cho cả đơn — một đơn nhiều món nằm trên nhiều dòng báo cáo, báo theo dòng là
     * khách nhận 3 chuông cho một lần mua.
     *
     * @var array<string, array{user_id: int, order_id: string, product_name: ?string, lines: int, net_total: float, status: string}>
     */
    private array $newOrders = [];

    /**
     * Nguyên văn trạng thái Shopee => trạng thái chuẩn hoá.
     *
     * So khớp sau khi bỏ dấu và viết thường, nên "Đã huỷ" và "Đã hủy" (Shopee dùng lẫn cả hai)
     * cùng ra một khoá.
     */
    private const STATUS_MAP = [
        'hoan thanh' => 'completed',
        'dang cho xu ly' => 'pending',
        'da huy' => 'cancelled',
    ];

    /**
     * @return array{rows: int, created: int, updated: int, matched: int, unmatched: int}
     */
    public function import(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Không mở được file báo cáo.');
        }

        try {
            $map = $this->readHeader($handle);
            $summary = ['rows' => 0, 'created' => 0, 'updated' => 0, 'matched' => 0, 'unmatched' => 0];

            // Tra sub_id => user một lần cho cả file. Báo cáo vài nghìn dòng mà tra từng dòng là
            // vài nghìn truy vấn cho một tập user rất nhỏ.
            $users = User::whereNotNull('sub_id')->pluck('id', 'sub_id')->all();

            // Cặp (khách, đơn) đã có từ trước, để biết dòng nào là đơn MỚI của khách đó. Tra một
            // lần thay vì mỗi dòng một truy vấn — cùng lý do với $users ở trên.
            $known = ShopeeOrder::whereNotNull('user_id')
                ->distinct()
                ->get(['user_id', 'order_id'])
                ->map(fn (ShopeeOrder $o) => $o->user_id.':'.$o->order_id)
                ->flip()
                ->all();
            $this->newOrders = [];

            while (($raw = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                // fgetcsv trả [null] cho dòng trắng cuối file.
                if ($raw === [null] || $raw === []) {
                    continue;
                }

                $row = $this->mapRow($raw, $map);

                if (($row['order_id'] ?? '') === '' || ($row['item_id'] ?? '') === '') {
                    continue;
                }

                $summary['rows']++;
                $this->save($row, $users, $known, $summary);
            }

            $this->notifyNewOrders();

            return $summary;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Chỉ báo đơn còn "Chờ Shopee xác nhận". Đơn nhập vào đã hoàn thành thì ngay sau import
     * CashbackService::sync() cộng tiền và tự gửi CommissionCreditedNotification — báo thêm "đơn
     * mới" nữa là hai chuông cho một đơn. Đơn huỷ thì không có gì đáng mừng để báo.
     */
    private function notifyNewOrders(): void
    {
        $rate = $this->cashback->rate();

        $pending = array_filter($this->newOrders, fn (array $o) => $o['status'] === 'pending');

        if ($pending === []) {
            return;
        }

        $users = User::whereIn('id', array_column($pending, 'user_id'))->get()->keyBy('id');

        foreach ($pending as $o) {
            $users->get($o['user_id'])?->notify(new NewOrderNotification(
                $o['order_id'],
                $o['product_name'],
                max(0, $o['lines'] - 1),
                $rate > 0 ? round($o['net_total'] * $rate / 100, 2) : null,
            ));
        }

        $this->newOrders = [];
    }

    /**
     * @param  resource  $handle
     * @return array<int, string> chỉ số cột => khoá nội bộ
     */
    private function readHeader($handle): array
    {
        $header = fgetcsv($handle, 0, ',', '"', '');

        if ($header === false) {
            throw new RuntimeException('File báo cáo rỗng.');
        }

        // Shopee xuất UTF-8 kèm BOM; không cắt thì tên cột đầu tiên mang 3 byte rác ở đầu và
        // không khớp với bất cứ thứ gì.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

        $map = [];

        foreach ($header as $index => $name) {
            $key = self::COLUMNS[$this->normalize((string) $name)] ?? null;

            if ($key !== null) {
                $map[$index] = $key;
            }
        }

        $missing = array_diff(self::REQUIRED, array_values($map));

        if ($missing !== []) {
            // Kèm danh sách cột đọc được: khi Shopee đổi tên cột, đây là thứ duy nhất cho biết
            // phải sửa hằng số COLUMNS thành gì.
            throw new RuntimeException(
                'File không đúng định dạng báo cáo hoa hồng Shopee. Thiếu cột: '
                .implode(', ', $missing)
                .'. Các cột đọc được: '
                .implode(' | ', array_map(fn ($n) => $this->normalize((string) $n), $header))
            );
        }

        return $map;
    }

    /**
     * Bỏ dấu, bỏ phần trong ngoặc, viết thường.
     *
     * Bỏ ngoặc là để không phụ thuộc ký hiệu tiền tệ: "Giá(₫)" và "Giá trị đơn hàng (₫)" đều có
     * đuôi "(₫)" mà ký tự ₫ không chuyển sang ASCII được một cách ổn định.
     */
    private function normalize(string $name): string
    {
        $name = preg_replace('/\(.*?\)/u', '', $name);
        $name = Str::ascii($name);
        $name = preg_replace('/\s+/', ' ', (string) $name);

        return mb_strtolower(trim((string) $name));
    }

    /**
     * @param  array<int, string|null>  $raw
     * @param  array<int, string>  $map
     * @return array<string, string>
     */
    private function mapRow(array $raw, array $map): array
    {
        $row = [];

        foreach ($map as $index => $key) {
            $row[$key] = trim((string) ($raw[$index] ?? ''));
        }

        return $row;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, int>  $users
     * @param  array<string, int>  $known
     * @param  array<string, int>  $summary
     */
    private function save(array $row, array $users, array &$known, array &$summary): void
    {
        $subId = $this->extractUserSubId($row);
        $userId = $subId !== null ? ($users[$subId] ?? null) : null;

        $summary[$userId !== null ? 'matched' : 'unmatched']++;

        if ($userId !== null) {
            $this->rememberNewOrder($userId, $row, $known);
        }

        $order = ShopeeOrder::updateOrCreate(
            [
                // Bảng này giờ chứa cả đơn TikTok (AccessTradeOrderImportService). Thiếu
                // `platform` ở khoá tìm thì một đơn TikTok trùng order_id sẽ bị dòng Shopee ghi
                // đè — hai sàn đánh số đơn trong hai không gian khác nhau, không có gì bảo đảm
                // không trùng.
                'platform' => ShopeeOrder::PLATFORM_SHOPEE,
                'order_id' => $row['order_id'],
                'item_id' => $row['item_id'],
                'model_id' => $row['model_id'] ?? '',
            ],
            [
                'checkout_id' => $row['checkout_id'] ?? null,
                'shop_id' => $row['shop_id'] ?? null,
                'shop_name' => $row['shop_name'] ?? null,
                'product_name' => $row['product_name'] ?? null,
                'quantity' => (int) ($row['quantity'] ?? 0),
                'price' => $this->money($row['price'] ?? ''),
                'order_value' => $this->money($row['order_value'] ?? ''),
                'net_commission' => $this->money($row['net_commission'] ?? ''),
                'total_order_commission' => $this->money($row['total_order_commission'] ?? ''),
                'order_status_raw' => $row['order_status_raw'] ?? null,
                'status' => $this->status($row['order_status_raw'] ?? ''),
                'sub_id_raw' => ($row['sub_id1'] ?? '') !== '' ? $row['sub_id1'] : null,
                'user_sub_id' => $subId,
                'user_id' => $userId,
                'channel' => ($row['channel'] ?? '') !== '' ? $row['channel'] : null,
                'content_type' => ($row['content_type'] ?? '') !== '' ? $row['content_type'] : null,
                'clicked_at' => $this->time($row['clicked_at'] ?? ''),
                'ordered_at' => $this->time($row['ordered_at'] ?? ''),
                'completed_at' => $this->time($row['completed_at'] ?? ''),
            ],
        );

        $summary[$order->wasRecentlyCreated ? 'created' : 'updated']++;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, int>  $known
     */
    private function rememberNewOrder(int $userId, array $row, array &$known): void
    {
        $key = $userId.':'.$row['order_id'];
        $status = $this->status($row['order_status_raw'] ?? '');

        if (isset($known[$key])) {
            // Dòng tiếp theo của một đơn nhiều món vừa thấy ở trên: cộng dồn, không thêm đơn mới.
            if (isset($this->newOrders[$key])) {
                $this->newOrders[$key]['lines']++;
                $this->newOrders[$key]['net_total'] += $this->money($row['net_commission'] ?? '');
                // Một dòng huỷ/hoàn thành là cả đơn không còn "đang chờ" — xem OrderHistoryController.
                if ($status !== 'pending') {
                    $this->newOrders[$key]['status'] = $status;
                }
            }

            return;
        }

        $known[$key] = 1;
        $this->newOrders[$key] = [
            'user_id' => $userId,
            'order_id' => $row['order_id'],
            'product_name' => ($row['product_name'] ?? '') !== '' ? $row['product_name'] : null,
            'lines' => 1,
            'net_total' => $this->money($row['net_commission'] ?? ''),
            'status' => $status,
        ];
    }

    /**
     * Lấy mã khách ra khỏi ô Sub_id.
     *
     * Mình gửi đi chuỗi 5 khe "fb-u7k2m9---" với mã khách CỐ ĐỊNH ở khe 2
     * (AffiliateLinkRewriterService::buildSubId). Báo cáo trả về theo một trong hai dạng, và
     * đọc được cả hai chính là lý do cách xếp đó an toàn:
     *
     *  1. Shopee tự tách khe => mã nằm nguyên ở cột Sub_id2.
     *  2. Shopee không tách  => cả chuỗi rơi vào Sub_id1, mình tự cắt bằng dấu "-".
     *
     * Trả null khi không có mã: đơn của khách vãng lai, hoặc đơn phát sinh trước khi tính năng
     * này chạy. Null KHÔNG phải lỗi — phần lớn đơn cũ sẽ như vậy.
     *
     * @param  array<string, string>  $row
     */
    private function extractUserSubId(array $row): ?string
    {
        $slot2 = trim($row['sub_id2'] ?? '');

        if ($slot2 !== '') {
            return $slot2;
        }

        $parts = explode('-', trim($row['sub_id1'] ?? ''));

        return isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;
    }

    private function status(string $raw): string
    {
        $key = mb_strtolower(trim(Str::ascii($raw)));

        if (isset(self::STATUS_MAP[$key])) {
            return self::STATUS_MAP[$key];
        }

        // Shopee thêm trạng thái mới thì phải thấy ngay: coi là pending (an toàn — không tự động
        // duyệt tiền) nhưng kêu lên để còn bổ sung vào STATUS_MAP.
        if ($raw !== '') {
            Log::warning('ShopeeReportImportService: trạng thái đơn lạ, tạm coi là pending', ['status' => $raw]);
        }

        return 'pending';
    }

    /** "84937.6" / "772160" / "" => float. Bỏ mọi thứ không phải chữ số, dấu chấm hay dấu trừ. */
    private function money(string $value): float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', $value);

        return $clean === '' || $clean === '-' ? 0.0 : (float) $clean;
    }

    private function time(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception $e) {
            Log::warning('ShopeeReportImportService: không đọc được mốc thời gian', ['value' => $value]);

            return null;
        }
    }
}
