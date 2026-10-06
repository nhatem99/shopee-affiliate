<?php

namespace App\Services;

use App\Models\ApiConfig;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Nguồn lấy link từ tool "Rút gọn link" của kieushopee.com/smart-links — KHÁC HẲN nguồn
 * kieushopee (sansale.kieushopee.com/22, nền tảng afp.ad) dù cùng chủ.
 *
 * Tool này không trả link có mã giảm giá gắn sẵn: nó đổi link sản phẩm thành link rút gọn
 * affiliate CHÍNH THỨC của Shopee (s.shopee.vn/XXXX) dưới tài khoản của họ, Sub_id1 = "Facebook".
 * Đo thật 04-10-2026: link đó 301 tới shopee.vn/opaanlp/{shop}/{item}?exp_group=rollout&
 * gads_t_sig=...&mmp_pid=an_<ID của họ>&utm_content=Facebook---- — trang landing affiliate mà họ
 * quảng cáo là "voucher độc quyền tự động áp dụng". Trong khi link an_redir của mình
 * (DirectAffiliateLinkService) đo cùng ngày lại về /product/ thường, không qua /opaanlp/ — đó là
 * lý do có nguồn này.
 *
 * Phần đổi affiliate về của mình vẫn do AffiliateLinkRewriterService lo như mọi nguồn khác:
 * s.shopee.vn nằm sẵn trong chuỗi redirect nó biết đi theo, tới shopee.vn thì đổi mmp_pid.
 *
 * Endpoint là route Laravel thường (JSON), có CSRF: phải GET trang tool trước để lấy token
 * trong thẻ meta + cookie phiên, rồi mới POST /convert trong cùng phiên đó.
 */
class KieuSmartLinkService
{
    /** Giá trị `source` ghi vào short_links/voucher_refs, `platform` của api_configs, khoá config. */
    public const SOURCE = 'kieusmartlink';

    // Bên họ phải gọi ngược sang Shopee để tạo link rút gọn — cùng mức với KieuShopeeService.
    private const TIMEOUT = 20;

    private const MOBILE_USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    /** Link mặc định cho nút "Kiểm tra kết nối" khi admin chưa đặt meta.test_url. */
    private const FALLBACK_TEST_URL = 'https://shopee.vn/Ao-Hoodie-i.564687320.29261186260';

    public function __construct(
        private UrlValidationService $urls,
    ) {}

    /**
     * Nút "Kiểm tra kết nối" ở /admin/api-config và lượt kiểm tra sức khoẻ (SourceHealthService)
     * — cùng shape với KieuShopeeService::testConnection().
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        $row = ApiConfig::where('platform', static::SOURCE)->first();
        $testUrl = $row->meta['test_url'] ?? null;

        $result = $this->fetchProductAndVoucherLink($testUrl ?: self::FALLBACK_TEST_URL);

        if ($result === null) {
            return [
                'ok' => false,
                'message' => 'Không lấy được link. Mở thử '.$this->endpoint().' trên trình duyệt xem tool còn chạy không. Chi tiết ở /admin/logs (tìm "KieuSmartLinkService").',
            ];
        }

        return ['ok' => true, 'message' => 'Lấy link thành công — '.$result['voucher_link']];
    }

    /**
     * Không cache, cùng lý do với KieuShopeeService: mỗi lượt dán link là một link mới.
     *
     * Không trả thông tin sản phẩm (tool không có) — `product` null để nơi gọi tự hỏi Shopee
     * bằng shop_id/item_id, y như khi nguồn kieushopee không kèm productInfo.
     *
     * @return array{voucher_link: string, shop_id: ?string, item_id: ?string, product: ?array}|null
     */
    public function fetchProductAndVoucherLink(string $shopeeUrl): ?array
    {
        $endpoint = $this->endpoint();
        $jar = new CookieJar;

        try {
            $page = $this->client($jar)->get($endpoint);
        } catch (\Exception $e) {
            Log::error('KieuSmartLinkService: lỗi kết nối tới '.$endpoint.': '.$e->getMessage(), ['shopee_url' => $shopeeUrl]);

            return null;
        }

        $token = $this->csrfToken($page->body());

        if (! $page->successful() || $token === null) {
            Log::error('KieuSmartLinkService: không đọc được CSRF token trên trang tool', [
                'status' => $page->status(),
                'shopee_url' => $shopeeUrl,
                'body' => Str::limit($page->body(), 300),
            ]);

            return null;
        }

        try {
            $response = $this->client($jar)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'X-CSRF-TOKEN' => $token,
                    'Origin' => $this->originOf($endpoint),
                    'Referer' => $endpoint,
                ])
                ->asJson()
                ->post(rtrim($endpoint, '/').'/convert', [
                    'url' => $shopeeUrl,
                    'sub_id1' => (string) config('services.'.static::SOURCE.'.sub_id1'),
                    'sub_id2' => '',
                ]);
        } catch (\Exception $e) {
            Log::error('KieuSmartLinkService: lỗi kết nối khi chuyển link: '.$e->getMessage(), ['shopee_url' => $shopeeUrl]);

            return null;
        }

        $link = $response->json('converted_link');

        if (! $response->successful() || ! $response->json('success') || ! is_string($link) || $link === '') {
            // 419 = CSRF/phiên không khớp; 422 = bên họ không nhận link này.
            Log::error('KieuSmartLinkService: không chuyển được link', [
                'status' => $response->status(),
                'shopee_url' => $shopeeUrl,
                'body' => Str::limit($response->body(), 500),
            ]);

            return null;
        }

        $ids = $this->urls->extractShopeeIds($shopeeUrl);

        return [
            'voucher_link' => $link,
            'shop_id' => $ids['shop_id'] ?? null,
            'item_id' => $ids['item_id'] ?? null,
            'product' => null,
        ];
    }

    /** Ưu tiên endpoint admin đặt ở /admin/api-config, rồi tới config — như KieuShopeeService::params(). */
    private function endpoint(): string
    {
        $fromDb = ApiConfig::where('platform', static::SOURCE)->value('endpoint');

        return filled($fromDb) ? trim($fromDb) : (string) config('services.'.static::SOURCE.'.endpoint');
    }

    private function client(CookieJar $jar): PendingRequest
    {
        return Http::withOptions(['cookies' => $jar])
            ->withUserAgent(self::MOBILE_USER_AGENT)
            ->timeout(self::TIMEOUT);
    }

    private function csrfToken(string $html): ?string
    {
        return preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $m) ? $m[1] : null;
    }

    private function originOf(string $endpoint): string
    {
        $parts = parse_url($endpoint);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
    }
}
