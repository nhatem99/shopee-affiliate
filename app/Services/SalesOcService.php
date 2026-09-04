<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Đọc thông tin sản phẩm + link voucher (Facebook/Instagram/Zalo) từ salesoc.vn.
 *
 * facebookAffiliateUrl/instagramAffiliateUrl/zaloAffiliateUrl trong response là
 * link rút gọn mờ (s.afp.ad/... hoặc shp.ee/...) — nơi mã giảm giá thực sự được
 * áp dụng. Link này thuộc tài khoản affiliate của salesoc.vn, không có cách nào
 * gắn mmp_pid của mình vào (server salesoc/mạng ad kiểm soát redirect cuối), nên
 * hoa hồng đơn hàng đi qua link này sẽ về salesoc.vn — đây là đánh đổi được chấp
 * nhận để đổi lấy mã giảm giá thật cho người dùng, thay vì link tự tạo không áp
 * được mã nào.
 */
class SalesOcService
{
    private const ENDPOINT = 'https://salesoc.vn/api/convert-with-shelf';

    // Giả lập request từ mobile — salesoc.vn trả về dữ liệu ổn định hơn khi gọi từ UA mobile.
    private const MOBILE_USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    // salesoc.vn kiểm tra Origin/Referer phía server và trả 403 ORIGIN_NOT_ALLOWED nếu request
    // không tự nhận là đến từ chính salesoc.vn — cần set 2 header này để API cho qua.
    private const SPOOFED_ORIGIN = 'https://salesoc.vn';

    private const TIMEOUT = 10;

    // Con trỏ xoay vòng relay, dùng chung cho mọi request/worker (cache store production là
    // database nên đếm được xuyên process, khác với biến static chỉ sống trong 1 request).
    private const RELAY_CURSOR_KEY = 'salesoc:relay-cursor';

    public function fetchProductAndVoucherLabels(string $shopeeUrl): ?array
    {
        // Cache ngắn hạn — cùng 1 link được dán lại (test, hoặc nhiều người cùng xem 1 sản
        // phẩm hot) thì trả ngay khỏi phải đợi round-trip tới salesoc.vn (thường 2-8s).
        // 15 phút là đủ ngắn để không giữ mã đã hết lượt quá lâu (salesoc.vn cũng không
        // báo trạng thái hết lượt của từng mã, nên độ mới ở đây vốn chỉ là best-effort).
        return Cache::remember('salesoc:'.md5($shopeeUrl), now()->addMinutes(15), function () use ($shopeeUrl) {
            return $this->fetch($shopeeUrl);
        });
    }

    private function fetch(string $shopeeUrl): ?array
    {
        $response = $this->fetchViaAnyRoute($shopeeUrl);

        if ($response === null) {
            return null;
        }

        $data = $response->json();
        $price = $this->parsePrice($data['price'] ?? null);
        $voucherLinks = [
            'facebook' => $this->extractOptions($data, 'facebookAffiliateUrls'),
            'instagram' => $this->extractInstagramOption($data),
            'zalo' => $this->extractOptions($data, 'zaloAffiliateUrls'),
            'youtube' => $this->extractYoutubeOption($data),
        ];

        // salesoc trả về OK nhưng không nền tảng nào có link dùng được — người dùng thấy
        // "Chưa lấy được link voucher" y hệt lúc API lỗi, nên phải phân biệt được trong log.
        if (array_filter($voucherLinks) === []) {
            Log::warning('SalesOcService: salesoc.vn OK nhưng không có link voucher nào', [
                'shopee_url' => $shopeeUrl,
                'body' => Str::limit($response->body(), 500),
            ]);
        }

        return [
            // Cùng shape với ShopeeLinkResolverService::fetchProductInfo() để
            // dùng thay thế được cho nhau ở phía controller/frontend.
            'product_name' => $data['productName'] ?? null,
            'product_image' => $data['imageUrl'] ?? null,
            'original_price' => $price,
            'discounted_price' => $price,
            'discount_percent' => 0,
            'sold_count' => 0,
            'rating' => 0,
            'voucher_labels' => $this->extractLabels($data),
            // Link áp mã giảm giá thật (CTA chính) — thuộc affiliate account của salesoc.vn.
            // API không trả trạng thái còn/hết lượt của từng mã, nên trả TẤT CẢ lựa chọn
            // (không chỉ mã % cao nhất) để người dùng thử link khác nếu link đầu đã hết lượt.
            'voucher_links' => $voucherLinks,
        ];
    }

    /**
     * Thử lần lượt từng đường ra tới salesoc.vn, lấy đường đầu tiên trả về dữ liệu dùng được.
     *
     * salesoc.vn chặn theo nguồn gọi ở tầng nginx (403 trước khi vào app của họ) và đã chặn
     * lần lượt: IP thật của VPS, rồi tới Cloudflare Worker relay. Đi một đường duy nhất nghĩa
     * là mỗi lần họ chặn thêm một nguồn là tính năng chết hẳn — nên thử hết các đường đang có.
     *
     * Relay nào đứng đầu thì XOAY VÒNG theo từng lượt gọi (xem nextRelayOffset): link 1 đi
     * relay 1, link 2 đi relay 2, link 3 đi relay 3, link 4 quay lại relay 1. Các relay còn
     * lại vẫn xếp ngay sau làm dự phòng, nên một relay chết không làm hỏng lượt gọi của nó.
     */
    private function fetchViaAnyRoute(string $shopeeUrl): ?Response
    {
        $routes = $this->routes($shopeeUrl, $this->nextRelayOffset(count($this->relayUrls())));
        $first = array_key_first($routes);

        foreach ($routes as $name => $call) {
            try {
                $response = $call();
            } catch (\Exception $e) {
                Log::error("SalesOcService: đường '{$name}' lỗi kết nối: ".$e->getMessage(), [
                    'shopee_url' => $shopeeUrl,
                ]);

                continue;
            }

            if ($response->successful() && $response->json('success')) {
                if ($name !== $first) {
                    // Đường được chia cho lượt này đã hỏng nhưng tính năng vẫn sống nhờ đường
                    // dự phòng — đáng biết để đi sửa nó trước khi kéo theo cả đường còn lại.
                    Log::warning("SalesOcService: phải dùng đường dự phòng '{$name}'", [
                        'shopee_url' => $shopeeUrl,
                    ]);
                }

                return $response;
            }

            Log::error("SalesOcService: đường '{$name}' bị từ chối", [
                'status' => $response->status(),
                'shopee_url' => $shopeeUrl,
                'body' => Str::limit($response->body(), 300),
            ]);
        }

        return null;
    }

    /**
     * Các đường ra tới salesoc.vn theo thứ tự ưu tiên. Relay đứng đầu vì IP thật của VPS là
     * thứ bị chặn đầu tiên; 'direct' luôn đứng cuối và luôn có mặt (ở local dev thì đây là
     * đường duy nhất, và cũng là đường tự hồi phục nếu salesoc bỏ chặn IP server).
     *
     * $relayOffset xoay danh sách relay để lượt gọi này bắt đầu từ relay thứ $relayOffset,
     * các relay còn lại nối vòng ngay sau làm dự phòng. Offset 0 = đúng thứ tự khai báo
     * trong config (diagnose() dùng mức này để in ra danh sách ổn định, dễ đối chiếu).
     *
     * @return array<string, callable(): Response>
     */
    private function routes(string $shopeeUrl, int $relayOffset = 0): array
    {
        $routes = [];

        // Relay: dịch vụ ngoài gọi hộ salesoc.vn từ IP khác rồi trả nguyên response về.
        // Xem deploy/deno-relay/main.ts (khuyến nghị) hoặc deploy/cloudflare-worker/salesoc-relay.js.
        //
        // Mỗi relay đi ra bằng ĐÚNG MỘT IP cố định (đo được: app Deno luôn ra từ 144.202.54.204),
        // nên một relay = một điểm chết. Khai báo nhiều relay ở nhiều nhà cung cấp khác nhau
        // (Deno/Vercel/Netlify/Cloud Run...) thì salesoc phải chặn lần lượt từng cái mới giết
        // được tính năng, và log sẽ báo ngay từ cái đầu tiên chết.
        foreach ($this->relayUrls($relayOffset) as $relayUrl) {
            $routes['relay:'.(parse_url($relayUrl, PHP_URL_HOST) ?: $relayUrl)] = fn () => Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Relay-Secret' => config('services.salesoc.relay_secret'),
            ])->timeout(self::TIMEOUT)->post($relayUrl, ['url' => $shopeeUrl]);
        }

        if ($proxy = config('services.salesoc.proxy')) {
            $routes['proxy'] = fn () => $this->directRequest()
                ->withOptions(['proxy' => $proxy])
                ->post(self::ENDPOINT, ['url' => $shopeeUrl]);
        }

        $routes['direct'] = fn () => $this->directRequest()->post(self::ENDPOINT, ['url' => $shopeeUrl]);

        return $routes;
    }

    /**
     * Danh sách relay, khai báo trong config dưới dạng chuỗi ngăn cách bằng dấu phẩy,
     * xoay đi $offset vị trí (relay thứ $offset lên đầu, phần đầu danh sách nối xuống cuối).
     *
     * @return list<string>
     */
    private function relayUrls(int $offset = 0): array
    {
        $raw = (string) config('services.salesoc.relay_url');
        $urls = array_values(array_filter(array_map('trim', explode(',', $raw))));

        $offset = $urls === [] ? 0 : $offset % count($urls);

        if ($offset === 0) {
            return $urls;
        }

        return array_merge(array_slice($urls, $offset), array_slice($urls, 0, $offset));
    }

    /**
     * Vị trí relay dành cho lượt gọi này, tăng dần và quay vòng: lượt 1 → relay 1,
     * lượt 2 → relay 2, ..., hết danh sách thì trở lại relay 1.
     *
     * Mục đích là RẢI TẢI đều trên các IP egress. Nếu luôn đi relay đầu tiên thì mọi request
     * của cả hệ thống dồn vào một IP, salesoc.vn nhìn thấy một nguồn gọi dày đặc bất thường
     * và chặn nó — đúng kịch bản đã xảy ra với IP VPS rồi tới Cloudflare Worker. Chia đều ra
     * n relay thì mỗi IP chỉ gánh 1/n lưu lượng.
     *
     * Bộ đếm nằm ở cache chứ không phải biến static: mỗi request PHP là một process riêng,
     * biến static luôn khởi động lại từ 0 nên request nào cũng sẽ đi relay đầu.
     */
    private function nextRelayOffset(int $relayCount): int
    {
        if ($relayCount < 2) {
            return 0;
        }

        try {
            // add() để key chắc chắn tồn tại (và có TTL) trước khi increment — increment vào
            // key trống ở một số cache store sẽ tạo bản ghi không TTL/hết hạn ngay lập tức.
            Cache::add(self::RELAY_CURSOR_KEY, 0, now()->addDay());

            // increment() trả về giá trị SAU khi tăng, nên lượt đầu tiên là 1 — trừ đi 1 để
            // lượt đầu rơi vào relay đầu danh sách thay vì relay thứ hai.
            $cursor = (int) Cache::increment(self::RELAY_CURSOR_KEY) - 1;
        } catch (\Exception $e) {
            // Cache hỏng thì vẫn phải lấy được voucher — chỉ mất phần rải tải.
            Log::warning('SalesOcService: không đọc được bộ đếm xoay relay: '.$e->getMessage());

            return 0;
        }

        return max($cursor, 0) % $relayCount;
    }

    private function directRequest(): PendingRequest
    {
        return Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => self::MOBILE_USER_AGENT,
            'Origin' => self::SPOOFED_ORIGIN,
            'Referer' => self::SPOOFED_ORIGIN.'/',
        ])->timeout(self::TIMEOUT);
    }

    /**
     * Bắn thử TẤT CẢ các đường ra và trả kết quả từng đường — dùng cho `php artisan salesoc:check`,
     * để khi salesoc chặn thêm nguồn mới thì biết ngay đường nào còn sống mà không phải đoán.
     *
     * @return list<array{route: string, ok: bool, status: int|null, duration_ms: int, detail: string}>
     */
    public function diagnose(string $shopeeUrl): array
    {
        $results = [];

        foreach ($this->routes($shopeeUrl) as $name => $call) {
            $startedAt = microtime(true);

            try {
                $response = $call();
                $ok = $response->successful() && (bool) $response->json('success');

                $results[] = [
                    'route' => $name,
                    'ok' => $ok,
                    'status' => $response->status(),
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                    'detail' => $ok
                        ? 'Có '.count($this->extractLabels($response->json() ?? [])).' mã giảm giá'
                        : Str::limit(preg_replace('/\s+/', ' ', $response->body()), 200),
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'route' => $name,
                    'ok' => false,
                    'status' => null,
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                    'detail' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Lấy toàn bộ {label, url} của 1 nền tảng. Entry có label rỗng nghĩa là
     * salesoc.vn không có mã giảm giá độc quyền cho nền tảng đó (chỉ có link
     * affiliate chung, không mã giảm giá thật) — bỏ qua, không hiện làm lựa
     * chọn để tránh gây hiểu lầm là "có mã" khi bấm vào không mà giảm nào.
     */
    private function extractOptions(array $data, string $key): array
    {
        $options = [];

        foreach ($data[$key] ?? [] as $entry) {
            $label = trim($entry['label'] ?? '');
            $url = $entry['url'] ?? null;
            if ($label !== '' && $url) {
                $options[] = ['label' => $label, 'url' => $url];
            }
        }

        return $options;
    }

    /**
     * Khác Facebook, entry Instagram của salesoc.vn không bao giờ có `label` (luôn
     * là chuỗi rỗng) dù link vẫn dùng được — trang salesoc.vn tự hiện nút "Mã IG"
     * bất kể label rỗng. Vì vậy không thể dùng extractOptions() (loại bỏ label rỗng)
     * cho Instagram, phải hard-code label như cách làm với YouTube bên dưới.
     */
    private function extractInstagramOption(array $data): array
    {
        foreach ($data['instagramAffiliateUrls'] ?? [] as $entry) {
            if ($url = $entry['url'] ?? null) {
                return [['label' => 'Mã IG', 'url' => $url]];
            }
        }

        return [];
    }

    /**
     * salesoc.vn không có field riêng cho YouTube — khi hasYoutubeVoucher=true,
     * chính website của họ hiện nút "Mã YTB" dùng lại field `affiliateUrl` chung
     * (xác nhận qua localStorage `convertHistory` của salesoc.vn: affiliateUrl và
     * fullAffiliateUrl trùng khớp với link trên nút "Mã YTB" thật).
     */
    private function extractYoutubeOption(array $data): array
    {
        $url = $data['affiliateUrl'] ?? null;

        if (! ($data['hasYoutubeVoucher'] ?? false) || ! $url) {
            return [];
        }

        return [['label' => 'Mã YTB', 'url' => $url]];
    }

    private function extractLabels(array $data): array
    {
        $labels = [];

        foreach (['facebookAffiliateUrls', 'instagramAffiliateUrls', 'zaloAffiliateUrls'] as $key) {
            foreach ($data[$key] ?? [] as $entry) {
                $label = trim($entry['label'] ?? '');
                if ($label !== '') {
                    $labels[] = $label;
                }
            }
        }

        return array_values(array_unique($labels));
    }

    private function parsePrice(?string $raw): ?float
    {
        if (! $raw) {
            return null;
        }

        $digits = preg_replace('/[^\d]/', '', $raw);

        return $digits === '' ? null : (float) $digits;
    }
}
