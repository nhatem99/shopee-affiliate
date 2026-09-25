<?php

namespace App\Services;

use App\Models\ApiConfig;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sinh link affiliate cho sản phẩm TikTok Shop qua mạng affiliate ACCESSTRADE.
 *
 * Khác hẳn đường Shopee: bên Shopee mình lấy link đã áp mã từ nguồn ngoài rồi tự đổi `mmp_pid`
 * về tài khoản của mình (xem AffiliateLinkRewriterService). Bên TikTok thì ACCESSTRADE là mạng
 * affiliate thật sự — họ cấp thẳng link tracking gắn publisher id của mình, không phải đi bóc
 * link của ai cả.
 *
 * Đã đo thật trên production ngày 25-09-2026 bằng API key của tài khoản:
 *
 *   POST https://api.accesstrade.vn/v1/product_link/create
 *   Authorization: Token {api_key}
 *   {"campaign_id": "...", "urls": ["https://shop.tiktok.com/view/product/..."], "sub1": "u7k2m9"}
 *
 *   → {"success": true, "data": {"success_link": [{"aff_link": "https://go.isclix.com/deep_link/...",
 *      "short_link": "https://shorten.asia/hY56S26r", "url_origin": "..."}], "error_link": [], "suspend_url": []}}
 *
 * `sub1` là ô định danh khách của mình — kiểm chứng trong lần gọi thật: giá trị truyền vào nằm
 * nguyên trong `aff_link` và sẽ quay về trong báo cáo đơn hàng, đúng vai trò mà `utm_content`
 * đang đảm nhiệm bên Shopee (xem AffiliateLinkRewriterService::buildSubId). Không có nó thì đơn
 * phát sinh không quy được về ai để hoàn tiền.
 *
 * ACCESSTRADE cũng tự thêm `sub4=oneatweb&sub5=pub-api` vào link — của họ, đừng đụng vào.
 *
 * Giới hạn của họ: 30 request/phút cho cả tài khoản.
 */
class AccessTradeService
{
    /** Giá trị `platform` của bản ghi cấu hình ở /admin/api-config. */
    public const PLATFORM = 'accesstrade';

    /** Tạo link là một round-trip sang mạng affiliate, không phải job dài như ganma. */
    private const TIMEOUT = 15;

    /**
     * Tiền tố của mấy ô mẫu do ApiConfigSeeder điền ('YOUR_ACCESSTRADE_API_KEY'...). Coi như
     * chưa cấu hình — xem params().
     */
    private const PLACEHOLDER_PREFIX = 'YOUR_';

    /**
     * Đường TikTok có đang bật không. Công tắc là chính ô is_active của thẻ AccessTrade ở
     * /admin/api-config — chỗ admin vốn đã nhìn vào để bật/tắt từng nhà cung cấp.
     *
     * Trước đây không có cửa nào cả: lịch accesstrade:sync-orders chạy vô điều kiện, gạt công
     * tắc trên giao diện không tắt được gì, và cách duy nhất để dừng là sửa routes/console.php
     * rồi deploy. Với một đường vừa gọi API bên ngoài vừa ghi tiền vào ví khách thì phải có
     * phanh bấm được từ giao diện.
     *
     * Chưa có bản ghi nào = chưa cài đặt = tắt. Mặc định TẮT theo lệ chung của repo cho mọi
     * hành vi tự động.
     */
    public function enabled(): bool
    {
        return ApiConfig::where('platform', self::PLATFORM)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Sinh link affiliate cho MỘT url sản phẩm TikTok Shop.
     *
     * @param  string|null  $subId  Mã định danh khách đang đăng nhập (User::$sub_id) — null là
     *                              khách vãng lai, link vẫn chạy nhưng đơn không quy về ai được.
     * @return array{aff_link: string, short_link: ?string, url_origin: ?string}|null
     *                                                                                null = không sinh được (chưa cấu hình, API lỗi, hoặc họ từ chối url).
     */
    public function createProductLink(string $url, ?string $subId = null): ?array
    {
        $params = $this->params();

        if ($params['api_key'] === '' || $params['campaign_id'] === '') {
            Log::warning('AccessTradeService: chưa cấu hình API key hoặc campaign_id', [
                'co_key' => $params['api_key'] !== '',
                'co_campaign' => $params['campaign_id'] !== '',
            ]);

            return null;
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'Token '.$params['api_key']])
                ->timeout(self::TIMEOUT)
                ->post($params['endpoint'].'/product_link/create', array_filter([
                    'campaign_id' => $params['campaign_id'],
                    'urls' => [$url],
                    'utm_source' => $params['utm_source'],
                    // Mã khách gửi ở CẢ HAI ô, cố ý.
                    //
                    // `utm_content` là ô chắc chắn quay về: nó nằm trong danh sách trường của
                    // báo cáo đơn hàng (/v1/order-list v2) cùng với utm_source/medium/campaign.
                    // `sub1` thì tài liệu báo cáo KHÔNG hề nhắc tới — chỉ thấy nó trong tham số
                    // lúc tạo link và trong chuỗi aff_link trả về.
                    //
                    // Chưa có đơn TikTok nào để đối chiếu thật, mà gửi thừa một ô thì không tốn
                    // gì; gửi thiếu ô đúng thì mọi đơn phát sinh không quy được về ai, và chỉ lộ
                    // ra ở kỳ đối soát đầu tiên — lúc đó tiền đã tiêu, khách đã mua xong.
                    'utm_content' => $subId,
                    'sub1' => $subId,
                ]));
        } catch (\Exception $e) {
            Log::warning('AccessTradeService: lỗi khi gọi tạo link: '.$e->getMessage(), ['url' => $url]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('AccessTradeService: API từ chối', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
                'url' => $url,
            ]);

            return null;
        }

        $link = $response->json('data.success_link.0');

        if (! $link || empty($link['aff_link'])) {
            // error_link/suspend_url là hai giỏ ACCESSTRADE trả về khi url bị từ chối (sai
            // campaign, domain không thuộc chiến dịch, sản phẩm bị treo). Log nguyên văn vì đó
            // là chỗ duy nhất nói được VÌ SAO — response vẫn là HTTP 200.
            Log::warning('AccessTradeService: không có link nào được tạo', [
                'url' => $url,
                'error_link' => $response->json('data.error_link'),
                'suspend_url' => $response->json('data.suspend_url'),
            ]);

            return null;
        }

        return [
            'aff_link' => $link['aff_link'],
            'short_link' => $link['short_link'] ?? null,
            'url_origin' => $link['url_origin'] ?? null,
        ];
    }

    /**
     * Nút "Kiểm tra kết nối" ở /admin/api-config.
     *
     * Tạo thử một link thật thay vì chỉ gọi một endpoint đọc: chỉ có lượt tạo link mới đi qua
     * ĐỦ ba thứ có thể hỏng — key còn sống, campaign_id đúng, và tài khoản đã được DUYỆT vào
     * chiến dịch đó. Gọi /campaigns thì key hỏng mới báo, còn chưa duyệt vẫn trả 200.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        $result = $this->createProductLink($this->params()['test_url']);

        if ($result === null) {
            return [
                'ok' => false,
                'message' => 'Không tạo được link. Kiểm tra API key và Campaign ID (ô "App ID"), và tài khoản đã được duyệt vào chiến dịch chưa. Chi tiết ở /admin/logs (tìm "AccessTradeService").',
            ];
        }

        return ['ok' => true, 'message' => 'Tạo link thành công — '.($result['short_link'] ?: $result['aff_link'])];
    }

    /**
     * Cùng bộ tham số đó, cho service khác gọi endpoint khác của ACCESSTRADE (đồng bộ đơn hàng —
     * xem AccessTradeOrderImportService). Mở ra một cửa đọc thay vì để mỗi nơi tự đọc ApiConfig:
     * hai chỗ tự suy ra độc lập là sớm muộn một bên đọc key ở DB còn bên kia đọc ở config.
     *
     * @return array{endpoint: string, api_key: string, campaign_id: string, utm_source: string, test_url: string}
     */
    public function apiParams(): array
    {
        return $this->params();
    }

    /**
     * Tham số gọi API, ưu tiên bản ghi ở /admin/api-config rồi mới tới config/services.php.
     *
     * Ánh xạ các ô của form admin (form dùng chung cho mọi provider nên tên ô là tên chung):
     *   • app_secret → API key  (Công cụ > Tích hợp > API Key trên pub2.accesstrade.vn)
     *   • app_id     → campaign_id của chiến dịch đã được duyệt
     *   • endpoint   → gốc API, mặc định https://api.accesstrade.vn/v1
     *
     * KHÔNG lọc theo is_active, cùng lý do đã ghi ở KieuShopeeService::params(): is_active trả
     * lời "nguồn nào đang phục vụ khách", không phải "gọi nguồn này bằng tham số gì". Việc
     * bật/tắt hẳn đường TikTok nằm ở enabled() bên dưới.
     *
     * GIÁ TRỊ GIẢ CỦA SEEDER BỊ COI NHƯ RỖNG — đây không phải chi tiết vụn:
     * ApiConfigSeeder tạo sẵn bản ghi 'accesstrade' với app_id='YOUR_ACCESSTRADE_PUBLISHER_ID',
     * app_secret='YOUR_ACCESSTRADE_API_KEY', và bản ghi đó ĐANG NẰM TRÊN PRODUCTION. Vì chuỗi
     * giả không rỗng nên nó vừa che mất campaign_id thật ở config, vừa lọt qua cửa "chưa cấu
     * hình" trong createProductLink() — kết quả là lịch 2 giờ/lần bắn request thật bằng token
     * rác, nhận 401, và Log::warning bị LOG_LEVEL=error trên prod nuốt mất. Hỏng im lặng vĩnh
     * viễn, đúng loại lỗi không ai phát hiện cho tới khi ngồi đọc báo cáo.
     *
     * @return array{endpoint: string, api_key: string, campaign_id: string, utm_source: string, test_url: string}
     */
    private function params(): array
    {
        $row = ApiConfig::where('platform', self::PLATFORM)->first();
        $meta = $row->meta ?? [];

        $pick = function (?string $fromDb, string $configKey): string {
            $value = trim((string) $fromDb);

            if ($value !== '' && ! str_starts_with($value, self::PLACEHOLDER_PREFIX)) {
                return $value;
            }

            return (string) config("services.accesstrade.{$configKey}");
        };

        return [
            'endpoint' => rtrim($pick($row->endpoint ?? null, 'endpoint'), '/'),
            'api_key' => $pick($row->app_secret ?? null, 'api_key'),
            'campaign_id' => $pick($row->app_id ?? null, 'campaign_id'),
            'utm_source' => $pick($meta['utm_source'] ?? null, 'utm_source'),
            'test_url' => $pick($meta['test_url'] ?? null, 'test_url'),
        ];
    }

    // --- Luồng quét cũ (/affiliate/scan, AffiliateScanOrchestrator) ---
    //
    // Hai hàm dưới đây thuộc luồng quét đa nền tảng đời đầu, KHÔNG phải luồng lấy mã đang phục
    // vụ khách (/voucher/resolve). Chúng trỏ tới endpoint `/link_generate` vốn không có trong
    // API thật của ACCESSTRADE — tức luồng đó chưa bao giờ sinh được link thật, chỉ lặng lẽ trả
    // về url gốc. Giữ nguyên ở đây để không đụng vào luồng cũ trong lúc làm phần TikTok; khi nào
    // luồng cũ được dùng lại thì chuyển nó sang createProductLink() ở trên.

    private array $cashbackRates = [
        'shopee' => 0.05,
        'lazada' => 0.07,
        'tiki' => 0.06,
        'tiktok' => 0.04,
    ];

    /** Đăng ký request sinh affiliate link vào pool dùng chung của orchestrator (chạy song song với các API khác). */
    public function registerPoolRequest(Pool $pool, string $originalUrl): bool
    {
        $config = ApiConfig::where('platform', self::PLATFORM)
            ->where('is_active', true)
            ->first();

        if (! $config) {
            Log::info('AccessTrade not configured, returning original URL');

            return false;
        }

        $pool->as('accesstrade')->withHeaders([
            'Authorization' => 'Token '.$config->app_secret,
        ])->timeout(10)->post($config->endpoint.'/link_generate', [
            'url' => $originalUrl,
        ]);

        return true;
    }

    /** Đọc kết quả từ pool ở trên; trả về URL gốc nếu không sinh được affiliate link. */
    public function parsePoolResponse(mixed $response, string $originalUrl): string
    {
        if ($response instanceof Response && $response->successful() && $response->json('data.url')) {
            return $response->json('data.url');
        }

        if ($response instanceof \Throwable) {
            Log::warning('AccessTrade link generation failed: '.$response->getMessage());
        }

        return $originalUrl;
    }

    public function getCashbackRate(string $platform): float
    {
        return $this->cashbackRates[$platform] ?? 0.03;
    }
}
