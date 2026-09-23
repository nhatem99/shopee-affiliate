<?php

namespace App\Services;

use App\Models\FlashSaleItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Đồng bộ kho sản phẩm Flash Sale cho trang /flashsale.
 *
 * NGUỒN LÀ API CỦA MỘT WEBSITE KHÁC (api.thichsansale.click — khác cả bloghoantien.com
 * dùng cho /ma-giam-gia, tức đây là bên thứ 3 thứ hai). Hệ quả phải nhớ, và khác hẳn
 * VoucherCatalogSyncService ở CÁCH LẤY LINK:
 *
 *  • Nguồn chỉ cấp `affiliate_link` cho ~9% sản phẩm (đo thật 23-09-2026: 237/2659), và
 *    khi có thì đó là short-link `s.shopee.vn/...` — KHÔNG redirect qua HTTP header
 *    Location như shp.ee/shope.ee, mà nhúng đích thật trong một khối
 *    `<script>var CONFIG={httpUrl:"..."}</script>` (Universal Link SDK của Shopee).
 *    AffiliateLinkRewriterService::followToShopee() chỉ đọc header Location nên KHÔNG
 *    theo được domain này — dùng nó ở đây sẽ âm thầm giữ nguyên affiliate của nguồn.
 *
 *  • Thay vì vá rewriter để đọc JS, ở đây bỏ qua HẲN field `affiliate_link` của nguồn.
 *    Một trang sản phẩm Shopee bình thường (khác link ÁP MỘT VOUCHER cụ thể) không cần
 *    `credential_token`/`signature` đã ký — chỉ cần `mmp_pid` để Shopee tính hoa hồng
 *    (đo thật: `https://shopee.vn/product/{shopid}/{itemid}?mmp_pid=...` mở ra đúng
 *    trang sản phẩm dù không có credential_token). Nguồn đã có sẵn shopid+itemid cho
 *    MỌI sản phẩm nên TỰ DỰNG link được cho 100%, không phụ thuộc nguồn có cấp hay
 *    không — đơn giản và chắc chắn hơn việc theo dõi một chuỗi redirect của bên khác.
 *
 *  • Nguồn trả TOÀN BỘ danh sách trong MỘT lần gọi, không phân trang (khác voucher).
 *    Vì vậy mỗi lượt đồng bộ là một bức ảnh chụp đầy đủ tại thời điểm gọi: sản phẩm
 *    KHÔNG còn trong lượt chụp mới nhất bị DỌN NGAY (xem pruneStale) — flash sale không
 *    có mốc hết hạn tường minh như voucher (`endTime`), "biến mất khỏi nguồn" là tín
 *    hiệu duy nhất biết suất đó đã đóng.
 */
class FlashSaleSyncService
{
    private const TIMEOUT = 30;

    private const USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    // Nguồn trả ~2.700 dòng trong một lần gọi — chia nhỏ khi upsert để một câu lệnh
    // không mang quá nhiều tham số ràng buộc (mỗi dòng ~10 cột).
    private const CHUNK_SIZE = 500;

    /**
     * @return array{fetched: int, kept: int, dropped: int, pruned: int, error: ?string}
     */
    public function sync(): array
    {
        $syncedAt = now();
        // Nhãn riêng của LƯỢT NÀY — xem lý do ở migration flash_sale_items (không dùng
        // synced_at để nhận biết lượt vì mất độ chính xác micro giây khi qua upsert()).
        $batch = (string) Str::uuid();
        $fetched = 0;
        $kept = 0;
        $dropped = 0;

        try {
            $items = $this->fetchAll();
        } catch (\Throwable $e) {
            Log::warning('FlashSaleSyncService: lỗi khi gọi nguồn', ['error' => $e->getMessage()]);

            return ['fetched' => 0, 'kept' => 0, 'dropped' => 0, 'pruned' => 0, 'error' => $e->getMessage()];
        }

        $rows = [];

        foreach ($items as $item) {
            $fetched++;

            if (! is_array($item)) {
                $dropped++;

                continue;
            }

            $row = $this->toRow($item, $batch, $syncedAt);

            if ($row === null) {
                $dropped++;

                continue;
            }

            $rows[] = $row;
        }

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            FlashSaleItem::upsert(
                $chunk,
                ['shopid', 'itemid', 'time_slot'],
                ['title', 'image', 'price', 'original_price', 'percent', 'amount', 'claim_url', 'sync_batch', 'synced_at'],
            );
        }

        $kept = count($rows);

        // KHÔNG dọn khi lượt này không lưu được dòng nào. Nguồn trả toàn bộ danh sách mỗi
        // lần gọi nên bình thường luôn có hàng trăm-nghìn dòng; kept = 0 nhiều khả năng là
        // nguồn đang lỗi/trả rỗng tạm thời chứ không phải "cả ngày hết flash sale" — dọn
        // trong tình huống đó là xoá sạch bảng vì một lần gọi hỏng, mất trắng dữ liệu tốt
        // của lượt trước trong lúc lẽ ra phải giữ nguyên và chờ lượt kế tiếp.
        $pruned = $kept > 0 ? $this->pruneStale($batch) : 0;

        if ($dropped > 0) {
            Log::info('FlashSaleSyncService: bỏ sản phẩm không dùng được (hết suất/thiếu dữ liệu)', [
                'dropped' => $dropped,
                'fetched' => $fetched,
            ]);
        }

        $error = ($fetched > 0 && $kept === 0)
            ? 'Nguồn trả về dữ liệu nhưng không có dòng nào dùng được — bỏ qua bước dọn bảng.'
            : null;

        return ['fetched' => $fetched, 'kept' => $kept, 'dropped' => $dropped, 'pruned' => $pruned, 'error' => $error];
    }

    /**
     * Nguồn trả về một mảng JSON thô (không bọc trong {data: ...} như API voucher).
     *
     * @return list<mixed>
     */
    private function fetchAll(): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'])
            ->get((string) config('services.flash_sale.source_url'));

        if (! $response->successful()) {
            throw new \RuntimeException("Nguồn trả HTTP {$response->status()}");
        }

        $items = $response->json();

        if (! is_array($items)) {
            throw new \RuntimeException('Nguồn không trả về một mảng JSON');
        }

        return $items;
    }

    /**
     * Đổi một item của nguồn thành dòng sẵn sàng ghi vào bảng.
     *
     * Trả null = bỏ item này: thiếu trường bắt buộc, hoặc đã hết suất
     * (`amount` <= ngưỡng cấu hình).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function toRow(array $item, string $batch, Carbon $syncedAt): ?array
    {
        $shopId = (int) ($item['shopid'] ?? 0);
        $itemId = (int) ($item['itemid'] ?? 0);
        $title = trim((string) ($item['title'] ?? ''));
        $timeSlot = trim((string) ($item['time'] ?? ''));

        if ($shopId <= 0 || $itemId <= 0 || $title === '' || $timeSlot === '') {
            return null;
        }

        $amount = (int) ($item['amount'] ?? 0);
        $minAmount = (int) config('services.flash_sale.min_amount', 1);

        if ($amount < $minAmount) {
            return null;
        }

        $price = (int) ($item['price'] ?? 0);

        if ($price <= 0) {
            return null;
        }

        return [
            'shopid' => $shopId,
            'itemid' => $itemId,
            'time_slot' => mb_substr($timeSlot, 0, 5),
            'title' => mb_substr($title, 0, 255),
            'image' => is_string($item['img'] ?? null) ? $item['img'] : null,
            'price' => $price,
            'original_price' => is_numeric($item['original_price'] ?? null) ? (int) $item['original_price'] : null,
            'percent' => max(0, min(100, (int) ($item['percent'] ?? 0))),
            'amount' => $amount,
            'claim_url' => $this->buildClaimUrl($shopId, $itemId),
            'sync_batch' => $batch,
            'synced_at' => $syncedAt,
            'created_at' => $syncedAt,
            'updated_at' => $syncedAt,
        ];
    }

    /**
     * Dựng link sản phẩm mang affiliate ID của mình, KHÔNG dùng `affiliate_link` của nguồn.
     *
     * Chưa gắn sub_id của khách ở đây — bảng dùng chung cho mọi người xem, sub_id ghép
     * lúc trả response, xem FlashSaleService::publicUrlFor().
     */
    private function buildClaimUrl(int $shopId, int $itemId): string
    {
        $mmpPid = (string) config('services.shopee_affiliate.mmp_pid');

        $query = [
            'mmp_pid' => $mmpPid,
            'utm_source' => $mmpPid,
            'utm_medium' => 'affiliates',
        ];

        $label = (string) config('services.flash_sale.utm_content');

        if ($label !== '') {
            $query['utm_content'] = $label;
        }

        return "https://shopee.vn/product/{$shopId}/{$itemId}?".http_build_query($query);
    }

    /**
     * Xoá sản phẩm không còn xuất hiện trong lượt đồng bộ mới nhất — nguồn trả toàn bộ
     * danh sách mỗi lần gọi nên "vắng mặt ở lượt này" nghĩa là suất đó đã đóng.
     *
     * So theo `sync_batch` chứ không theo thời gian: mọi dòng vừa upsert xong đều mang
     * đúng $batch của lượt này, nên dòng nào KHÁC $batch chắc chắn là của lượt trước.
     */
    private function pruneStale(string $batch): int
    {
        return FlashSaleItem::where('sync_batch', '!=', $batch)->delete();
    }
}
