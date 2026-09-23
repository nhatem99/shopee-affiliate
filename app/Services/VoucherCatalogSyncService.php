<?php

namespace App\Services;

use App\Models\VoucherOffer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Đồng bộ kho mã giảm giá toàn sàn cho trang /ma-giam-gia.
 *
 * NGUỒN LÀ API CỦA MỘT WEBSITE KHÁC, không phải của mình. Hệ quả phải nhớ:
 *
 *  • Mọi `claimLink` họ trả về đều mang affiliate ID của HỌ. Lưu nguyên xi là mỗi đơn
 *    khách mình đặt lại trả hoa hồng cho họ. Nên ở đây mã nào KHÔNG đổi được affiliate
 *    sang của mình thì bị BỎ HẲN, không lưu — thà thiếu mã còn hơn bày một nút bấm
 *    kiếm tiền hộ người khác. Số mã bỏ được đếm và báo lại trong output của lệnh.
 *
 *  • Đổi được affiliate là vì `signature` của Shopee chỉ ký `promotionId` + `voucherCode`,
 *    còn `mmp_pid`/`utm_*` là tham số tracking rời (đo thật 23-09-2026: bấm một mã rồi
 *    so URL trước/sau redirect, bộ ba đã ký đi qua nguyên vẹn). Cùng tính chất mà
 *    AffiliateLinkRewriterService đang dựa vào cho link kieushopee.
 *
 *  • Họ đổi đường dẫn / đổi cấu trúc JSON / chặn IP là đồng bộ chết. Lúc đó trang KHÔNG
 *    sập: bảng `voucher_offers` vẫn còn mã của lần chạy cuối, chỉ cũ dần rồi tự hết hạn.
 *    Đây là đánh đổi có chủ ý khi chọn nguồn này thay vì Shopee Affiliate Open API.
 */
class VoucherCatalogSyncService
{
    private const TIMEOUT = 20;

    private const USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    /**
     * Kéo toàn bộ mã của mọi sàn đang bật rồi ghi vào bảng.
     *
     * Trả thống kê theo TỪNG SÀN chứ không phải một con số tổng, để lệnh artisan in ra bảng
     * và admin thấy ngay sàn nào đang không ra mã.
     *
     * @return array<string, array{fetched: int, kept: int, dropped: int, duplicated: int, error: ?string}>
     */
    public function sync(): array
    {
        $report = [];

        foreach ((array) config('services.voucher_catalog.platforms', []) as $platform) {
            $report[$platform] = $this->syncPlatform((string) $platform);
        }

        $this->pruneExpired();

        return $report;
    }

    /**
     * @return array{fetched: int, kept: int, dropped: int, duplicated: int, error: ?string}
     */
    private function syncPlatform(string $platform): array
    {
        $fetched = 0;
        $kept = 0;
        $dropped = 0;
        $duplicated = 0;
        $syncedAt = now();

        // external_id đã gặp trong LƯỢT NÀY. Nguồn trả trùng id giữa các trang (đo thật
        // 23-09-2026: 104 item nhận về chỉ ra 102 mã), và một lô upsert chứa hai dòng cùng
        // khoá là thứ mỗi hệ CSDL xử lý một kiểu. Lọc ở đây để `kept` đếm đúng số mã thật
        // có trong bảng — nếu không thì con số báo cáo luôn đẹp hơn sự thật một ít, đúng
        // kiểu sai số khiến người đọc tin nhầm là mọi thứ ổn.
        $seen = [];

        try {
            foreach ($this->pages($platform) as $items) {
                $rows = [];

                foreach ($items as $item) {
                    $fetched++;

                    if (! is_array($item)) {
                        $dropped++;

                        continue;
                    }

                    $row = $this->toRow($item, $platform, $syncedAt);

                    if ($row === null) {
                        $dropped++;

                        continue;
                    }

                    if (isset($seen[$row['external_id']])) {
                        $duplicated++;

                        continue;
                    }

                    $seen[$row['external_id']] = true;
                    $rows[] = $row;
                }

                if ($rows !== []) {
                    // upsert theo (platform, external_id): mã cũ được cập nhật lượt dùng /
                    // hạn mới thay vì nhân bản, và KHÔNG xoá trắng bảng trước khi ghi —
                    // đồng bộ hỏng giữa chừng thì trang vẫn còn mã cũ để hiện.
                    VoucherOffer::upsert(
                        $rows,
                        ['platform', 'external_id'],
                        ['code', 'title', 'subtitle', 'applies_text', 'shop_name', 'voucher_image',
                            'claim_url', 'category', 'usage_percent', 'usage_text', 'ends_at', 'synced_at'],
                    );
                    $kept += count($rows);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('VoucherCatalogSyncService: lỗi khi đồng bộ', [
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);

            return ['fetched' => $fetched, 'kept' => $kept, 'dropped' => $dropped, 'duplicated' => $duplicated, 'error' => $e->getMessage()];
        }

        if ($dropped > 0) {
            Log::warning('VoucherCatalogSyncService: bỏ mã không dùng được', [
                'platform' => $platform,
                'dropped' => $dropped,
                'fetched' => $fetched,
            ]);
        }

        return ['fetched' => $fetched, 'kept' => $kept, 'dropped' => $dropped, 'duplicated' => $duplicated, 'error' => null];
    }

    /**
     * Đi hết các trang của nguồn, mỗi lượt trả về mảng item thô.
     *
     * Dừng khi nguồn báo hết (`hasMore` khác true), khi một trang rỗng, hoặc khi chạm trần
     * `max_pages` — trần là để nguồn trả `hasMore` sai (luôn true) không kéo lệnh chạy mãi.
     *
     * @return \Generator<int, array<int, mixed>>
     */
    private function pages(string $platform): \Generator
    {
        $pageSize = max(1, (int) config('services.voucher_catalog.page_size', 50));
        $maxPages = max(1, (int) config('services.voucher_catalog.max_pages', 20));

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = Http::timeout(self::TIMEOUT)
                ->withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'])
                ->get((string) config('services.voucher_catalog.source_url'), [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'platform' => $platform,
                    'category' => 'all',
                    'keyword' => '',
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException("Nguồn trả HTTP {$response->status()} ở trang {$page}");
            }

            $items = $response->json('data.items');

            if (! is_array($items) || $items === []) {
                return;
            }

            yield $items;

            if ($response->json('data.pagination.hasMore') !== true) {
                return;
            }
        }

        Log::warning('VoucherCatalogSyncService: chạm trần số trang, có thể còn mã chưa lấy', [
            'platform' => $platform,
            'max_pages' => $maxPages,
        ]);
    }

    /**
     * Đổi một item của nguồn thành dòng sẵn sàng ghi vào bảng.
     *
     * Trả null = bỏ mã này: thiếu trường bắt buộc, là mã test của nguồn, hoặc link không
     * đổi được sang affiliate của mình.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function toRow(array $item, string $platform, Carbon $syncedAt): ?array
    {
        $externalId = trim((string) ($item['id'] ?? ''));
        $code = trim((string) ($item['code'] ?? ''));
        $claimLink = trim((string) ($item['claimLink'] ?? ''));

        if ($externalId === '' || $code === '' || $claimLink === '') {
            return null;
        }

        // Mã test của nguồn — họ tự đánh dấu, không có lý do gì bày cho khách mình.
        if (($item['isTest'] ?? false) === true) {
            return null;
        }

        $claimUrl = $this->rewriteToOwnAffiliate($claimLink);

        if ($claimUrl === null) {
            return null;
        }

        return [
            'platform' => $platform,
            'external_id' => mb_substr($externalId, 0, 64),
            'code' => mb_substr($code, 0, 100),
            'title' => $this->clip($item['title'] ?? null, 120),
            'subtitle' => $this->clip($item['subtitle'] ?? null, 255),
            'applies_text' => $this->clip($item['appliesText'] ?? null, 100),
            'shop_name' => $this->clip($item['shopName'] ?? null, 120),
            'voucher_image' => is_string($item['voucherImage'] ?? null) ? $item['voucherImage'] : null,
            'claim_url' => $claimUrl,
            'category' => $this->clip($item['category'] ?? null, 32),
            'usage_percent' => max(0, min(100, (int) ($item['usagePercent'] ?? 0))),
            'usage_text' => $this->clip($item['usageText'] ?? null, 120),
            'ends_at' => $this->parseEndTime($item['endTime'] ?? null),
            'synced_at' => $syncedAt,
            'created_at' => $syncedAt,
            'updated_at' => $syncedAt,
        ];
    }

    /**
     * Đổi affiliate ID trong link của nguồn sang của mình.
     *
     * Trả null khi không làm được — link không trỏ shopee.vn, hoặc không có sẵn `mmp_pid`
     * để thay. KHÔNG tự thêm tham số mới vào URL (giữ đúng kỷ luật của
     * AffiliateLinkRewriterService): thiếu mmp_pid nghĩa là link này không đi qua cơ chế
     * hoa hồng mình biết, đoán thêm vào chỉ tạo ra link hỏng mà vẫn trông như chạy được.
     *
     * `utm_campaign` của nguồn bị XOÁ: đó là ID chiến dịch của họ, giữ lại thì báo cáo
     * affiliate của mình mang nhãn của người khác.
     *
     * Không đụng tới `promotionId`, `signature`, `voucherCode` — bộ đã ký, sai một ký tự
     * là mất mã. Cũng giữ nguyên đường dẫn `/universal-link/...`: đó là cổng deep-link mở
     * thẳng app Shopee trên điện thoại, đúng thứ cần cho tỉ lệ ra đơn.
     */
    private function rewriteToOwnAffiliate(string $url): ?string
    {
        $parts = parse_url($url);

        if (($parts['host'] ?? '') !== 'shopee.vn' || ! isset($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $query);

        if (! isset($query['mmp_pid'])) {
            return null;
        }

        $mmpPid = (string) config('services.shopee_affiliate.mmp_pid');

        if ($mmpPid === '') {
            return null;
        }

        $query['mmp_pid'] = $mmpPid;

        // utm_source đi kèm mmp_pid cho khớp nhau, y như link kieushopee.
        if (isset($query['utm_source'])) {
            $query['utm_source'] = $mmpPid;
        }

        unset($query['utm_campaign']);

        // Nhãn kênh dạng gốc, CHƯA gắn sub_id khách — ghép lúc trả response cho người xem,
        // xem VoucherCatalogService::publicUrlFor().
        $label = (string) config('services.voucher_catalog.utm_content');

        if ($label === '') {
            unset($query['utm_content']);
        } else {
            $query['utm_content'] = $label;
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].($parts['path'] ?? '').'?'.http_build_query($query);
    }

    /** `endTime` của nguồn là epoch mili-giây. 0/null = mã không ghi hạn. */
    private function parseEndTime(mixed $endTime): ?Carbon
    {
        if (! is_numeric($endTime) || (int) $endTime <= 0) {
            return null;
        }

        return Carbon::createFromTimestampMs((int) $endTime);
    }

    private function clip(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }

    /**
     * Dọn mã đã hết hạn quá lâu. Giữ lại 2 ngày sau hạn thay vì xoá ngay: nguồn thỉnh
     * thoảng trả `endTime` lệch, xoá sát nút là mã còn dùng được cũng bay.
     */
    private function pruneExpired(): void
    {
        VoucherOffer::whereNotNull('ends_at')
            ->where('ends_at', '<', now()->subDays(2))
            ->delete();
    }
}
