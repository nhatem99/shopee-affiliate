<?php

namespace App\Services;

use App\Models\ApiConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Lấy link đã áp mã giảm giá Shopee từ ganma.vn — nguồn mã YouTube, chạy song song với
 * KieuShopeeService (mã Facebook/Instagram). Admin chọn nguồn nào đang dùng ở /admin/api-config.
 *
 * Trả về CÙNG SHAPE với KieuShopeeService::fetchProductAndVoucherLink() để hai nguồn thay thế
 * được cho nhau ở phía controller, dù hai API không giống nhau chút nào.
 *
 * KHÁC KIEUSHOPEE Ở BA ĐIỂM PHẢI NHỚ:
 *
 * 1. BẤT ĐỒNG BỘ. kieushopee trả link ngay trong một request; ganma tạo job rồi bắt hỏi lại
 *    tới khi xong. Đo thật 08-09-2026: ~17-20 giây, có hàng đợi (queue_position đếm lùi).
 *
 * 2. CHỈ NHẬN LINK NGẮN. Đo thật: vn.shp.ee / shope.ee / s.shopee.vn đều tạo được job, còn
 *    link shopee.vn đầy đủ bị từ chối thẳng ("Vui lòng sử dụng link sản phẩm từ ứng dụng
 *    Shopee"). Không có đường chuyển ngược từ link đầy đủ về link ngắn, nên với khách dán link
 *    đầy đủ thì nguồn này không dùng được — xem canHandle().
 *
 * 3. LINK TRẢ VỀ CÓ SHAPE KHÁC. kieushopee trả s.afp.ad/... (redirect tới shopee.vn kèm
 *    mmp_pid); ganma trả s.shopee.vn/an_redir?affiliate_id=...&origin_link=...&sub_id=...
 *    Việc đổi affiliate về tài khoản mình nằm ở AffiliateLinkRewriterService::swapAnRedirAffiliate().
 */
class GanmaService
{
    /** Giá trị `source` ghi vào short_links + tracking cho mọi link lấy từ đây. */
    public const SOURCE = 'ganma';

    /** Domain link ngắn của Shopee mà ganma chấp nhận — đo thật, cả ba đều tạo được job. */
    private const ACCEPTED_HOSTS = ['vn.shp.ee', 'shp.ee', 'shope.ee', 's.shopee.vn'];

    /**
     * Nguồn này chỉ làm việc được với link chia sẻ từ ứng dụng Shopee. Kiểm tra TRƯỚC khi gọi
     * để khỏi đốt một round-trip chỉ để nhận về lỗi biết trước, và để phía gọi còn kịp chuyển
     * sang nguồn khác.
     */
    public function canHandle(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);

        foreach (self::ACCEPTED_HOSTS as $accepted) {
            if ($host === $accepted || str_ends_with($host, '.'.$accepted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{voucher_link: string, shop_id: ?string, item_id: ?string, product: ?array}|null
     */
    public function fetchProductAndVoucherLink(string $shopeeUrl, bool $useCache = true): ?array
    {
        if (! $this->canHandle($shopeeUrl)) {
            Log::info('GanmaService: bỏ qua vì không phải link ngắn từ app Shopee', ['url' => $shopeeUrl]);

            return null;
        }

        if (! $useCache) {
            return $this->fetch($shopeeUrl);
        }

        // Cache dài tay hơn kieushopee (15 phút): mỗi lần miss là khách phải đợi ~20 giây chứ
        // không phải vài giây, nên tránh gọi lại được lần nào là đáng lần đó.
        return Cache::remember(
            'ganma:'.md5($shopeeUrl),
            now()->addMinutes(30),
            fn () => $this->fetch($shopeeUrl),
        );
    }

    /**
     * Nút "Kiểm tra kết nối" ở /admin/api-config. Mất ~20 giây vì phải chạy trọn một job thật —
     * không có endpoint health-check nào rẻ hơn, mà kiểm tra nửa vời (chỉ tạo job rồi bỏ) thì
     * không phát hiện được ca hỏng hay gặp nhất là job chạy xong nhưng không ra mã.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        $row = ApiConfig::where('platform', self::SOURCE)->first();
        $testUrl = trim((string) ($row->meta['test_url'] ?? ''));

        if ($testUrl === '') {
            return [
                'ok' => false,
                'message' => 'Chưa có link kiểm tra. Điền một link ngắn từ app Shopee (vn.shp.ee/...) vào ô "Link kiểm tra" rồi lưu lại — nguồn này không nhận link shopee.vn đầy đủ.',
            ];
        }

        if (! $this->canHandle($testUrl)) {
            return [
                'ok' => false,
                'message' => 'Link kiểm tra phải là link chia sẻ từ app Shopee (vn.shp.ee / shope.ee / s.shopee.vn). Link shopee.vn đầy đủ luôn bị nguồn này từ chối.',
            ];
        }

        $result = $this->fetch($testUrl);

        if ($result === null) {
            return [
                'ok' => false,
                'message' => 'Không lấy được mã. Chi tiết lý do ở /admin/logs (tìm "GanmaService") — thường là sản phẩm không có mã chứ không phải hỏng kết nối.',
            ];
        }

        $name = $result['product']['product_name'] ?? 'không đọc được tên sản phẩm';

        return ['ok' => true, 'message' => "Lấy mã thành công — {$name}"];
    }

    /**
     * @return array{voucher_link: string, shop_id: ?string, item_id: ?string, product: ?array}|null
     */
    private function fetch(string $shopeeUrl): ?array
    {
        $jobId = $this->createJob($shopeeUrl);

        if ($jobId === null) {
            return null;
        }

        $payload = $this->waitForJob($jobId, $shopeeUrl);

        if ($payload === null) {
            return null;
        }

        $link = $payload['youtube_link'] ?? null;

        if (! is_string($link) || $link === '') {
            // Job báo xong nhưng không có link — khách thấy y hệt lúc API lỗi, nên phải phân
            // biệt được trong log.
            Log::warning('GanmaService: job hoàn tất nhưng không có youtube_link', [
                'shopee_url' => $shopeeUrl,
                'payload' => $payload,
            ]);

            return null;
        }

        $product = $this->normalizeProductInfo($payload['product_info'] ?? []);
        $ids = $this->extractIds($link);

        return [
            'voucher_link' => $link,
            'shop_id' => $ids['shop_id'] ?? null,
            // product_info trả sẵn item_id; ưu tiên nó rồi mới tới id moi từ link.
            'item_id' => isset($payload['product_info']['item_id'])
                ? (string) $payload['product_info']['item_id']
                : ($ids['item_id'] ?? null),
            'product' => $product,
        ];
    }

    private function createJob(string $shopeeUrl): ?string
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->timeout($this->config('request_timeout_seconds'))
                ->asJson()
                ->post($this->endpoint().'/yt/request-conversion', ['url' => $shopeeUrl]);
        } catch (\Exception $e) {
            Log::error('GanmaService: lỗi kết nối khi tạo job: '.$e->getMessage(), ['shopee_url' => $shopeeUrl]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('GanmaService: bị từ chối khi tạo job', [
                'status' => $response->status(),
                'shopee_url' => $shopeeUrl,
                'body' => Str::limit($response->body(), 300),
            ]);

            return null;
        }

        $data = $response->json();
        $jobId = is_array($data) ? ($data['job_id'] ?? null) : null;

        if (! is_string($jobId) || $jobId === '') {
            // Ca thật hay gặp: HTTP 200 kèm {"job_id":null,"status":"error","error":"..."} —
            // lỗi nghiệp vụ chứ không phải lỗi mạng, và message của họ đã đủ rõ cho khách.
            Log::warning('GanmaService: không tạo được job', [
                'shopee_url' => $shopeeUrl,
                'error' => is_array($data) ? ($data['error'] ?? null) : null,
                'body' => Str::limit($response->body(), 300),
            ]);

            return null;
        }

        return $jobId;
    }

    /**
     * Hỏi lại trạng thái job cho tới khi xong, hỏng, hoặc hết thời gian chờ.
     *
     * @return array<string, mixed>|null
     */
    private function waitForJob(string $jobId, string $shopeeUrl): ?array
    {
        $interval = max(1, $this->config('poll_interval_seconds'));
        $deadline = $this->config('max_wait_seconds');
        $waited = 0;

        // PHP-FPM trên production đặt max_execution_time = 30 (đo 08-09-2026), thấp hơn thời
        // gian chờ ở đây. Trên Unix thì sleep() và chờ I/O KHÔNG bị tính vào hạn mức đó, nên
        // thực tế có thể không sao — nhưng đó là một chi tiết tinh vi của nền tảng, không phải
        // thứ đáng đem ra bảo lãnh cho đường mua hàng của khách. Nới hạn mức tường minh để
        // hành vi không phụ thuộc vào nó.
        if (function_exists('set_time_limit')) {
            @set_time_limit($deadline + 15);
        }

        while ($waited < $deadline) {
            // Sleep của Laravel chứ không phải sleep() thuần: test giả lập được (Sleep::fake())
            // nên bộ test không phải đứng chờ thật hàng chục giây.
            Sleep::for($interval)->seconds();
            $waited += $interval;

            try {
                $response = Http::withHeaders($this->headers())
                    ->timeout($this->config('request_timeout_seconds'))
                    ->get($this->endpoint().'/yt/check-status', ['job_id' => $jobId]);
            } catch (\Exception $e) {
                Log::error('GanmaService: lỗi kết nối khi hỏi trạng thái: '.$e->getMessage(), [
                    'job_id' => $jobId,
                ]);

                return null;
            }

            if (! $response->successful()) {
                Log::error('GanmaService: hỏi trạng thái bị từ chối', [
                    'status' => $response->status(),
                    'job_id' => $jobId,
                ]);

                return null;
            }

            $data = $response->json();

            if (! is_array($data)) {
                Log::error('GanmaService: trạng thái không đọc được', [
                    'job_id' => $jobId,
                    'body' => Str::limit($response->body(), 300),
                ]);

                return null;
            }

            $status = (string) ($data['status'] ?? '');

            if ($status === 'complete') {
                return $data;
            }

            // 'error' là trạng thái KẾT THÚC, không phải tạm thời — hỏi tiếp chỉ tốn thời gian
            // của khách. Ví dụ thật: "Shop bạn gửi không hỗ trợ mã, vui lòng tìm sản phẩm này
            // trên shop khác và thử lại."
            if ($status === 'error' || ! empty($data['error'])) {
                Log::info('GanmaService: job kết thúc bằng lỗi nghiệp vụ', [
                    'job_id' => $jobId,
                    'shopee_url' => $shopeeUrl,
                    'error' => $data['error'] ?? null,
                ]);

                return null;
            }
        }

        Log::warning('GanmaService: hết thời gian chờ job', [
            'job_id' => $jobId,
            'shopee_url' => $shopeeUrl,
            'da_cho_giay' => $waited,
        ]);

        return null;
    }

    /**
     * Moi shop_id/item_id ra khỏi origin_link nằm bên trong link an_redir — ganma chỉ trả
     * item_id trong product_info, không trả shop_id, mà phía sau cần cả cặp để hỏi Shopee khi
     * product_info thiếu.
     *
     * @return array{shop_id?: string, item_id?: string}
     */
    private function extractIds(string $anRedirLink): array
    {
        parse_str((string) parse_url($anRedirLink, PHP_URL_QUERY), $query);

        $origin = (string) ($query['origin_link'] ?? '');

        if ($origin !== '' && preg_match('#/product/(\d+)/(\d+)#', $origin, $m)) {
            return ['shop_id' => $m[1], 'item_id' => $m[2]];
        }

        return [];
    }

    /**
     * Shape thật đo được 08-09-2026:
     *   {"product_name":"Loa Bluetooth JBL Flip 6 ...","product_image":"https://cf.shopee.vn/file/vn-...",
     *    "product_price":547500,"item_id":27236640960}
     *
     * Giữ ĐÚNG shape của ShopeeLinkResolverService::fetchProductInfo() để phía controller và
     * frontend dùng chung một khuôn với nguồn kieushopee.
     *
     * Ganma KHÔNG trả giá gốc, nên original_price = discounted_price và discount_percent = 0.
     * Cố tình không bịa: hiện "giảm 0%" còn hơn hiện một con số sai.
     */
    private function normalizeProductInfo(mixed $info): ?array
    {
        if (! is_array($info)) {
            return null;
        }

        $name = trim((string) ($info['product_name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $price = isset($info['product_price']) ? (float) $info['product_price'] : null;
        $image = trim((string) ($info['product_image'] ?? ''));

        return [
            'product_name' => $name,
            'product_image' => $image !== '' ? $image : null,
            'original_price' => $price,
            'discounted_price' => $price,
            'discount_percent' => 0,
            'sold_count' => 0,
            'rating' => 0.0,
        ];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $endpoint = $this->endpoint();

        return [
            'Accept' => 'application/json',
            'Origin' => $endpoint,
            'Referer' => $endpoint.'/yt',
            // Giả lập trình duyệt mobile: UA mặc định của Guzzle là thứ dễ bị lọc nhất ở tầng
            // CDN/WAF, mà đổi UA thì không ảnh hưởng gì tới cách họ xử lý job.
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
        ];
    }

    /**
     * Endpoint ưu tiên bản ghi ở /admin/api-config rồi mới tới config/services.php — cùng lý do
     * với kieushopee: đổi được ngay từ trang admin khi nguồn đổi domain, không phải deploy.
     */
    private function endpoint(): string
    {
        $row = ApiConfig::where('platform', self::SOURCE)->where('is_active', true)->first();
        $fromDb = trim((string) ($row->endpoint ?? ''));

        return rtrim($fromDb !== '' ? $fromDb : (string) config('services.ganma.endpoint'), '/');
    }

    private function config(string $key): int
    {
        return (int) config("services.ganma.{$key}");
    }
}
