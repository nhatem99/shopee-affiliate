<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Lấy link đã áp mã giảm giá Shopee từ sansale.kieushopee.com (thay cho salesoc.vn).
 *
 * Khác salesoc.vn — trả về nhiều link theo từng kênh (Mã FB/IG/Zalo/YTB) để người dùng
 * tự chọn — bên này mỗi lần quét chỉ trả về ĐÚNG MỘT link đã gắn sẵn mã. Vì vậy phía
 * người dùng không còn phải chọn mã nào, chỉ có một nút "Mua ngay".
 *
 * Link trả về thuộc tài khoản affiliate của kieushopee (mã giảm giá gắn theo tài khoản đó,
 * không tách ra được). AffiliateLinkRewriterService đổi lại mmp_pid sang affiliate ID của
 * mình sau khi người dùng bấm chọn — xem ShortLinkController::store().
 *
 * Endpoint là một Next.js Server Action chứ không phải REST API: phải gửi multipart kèm
 * header `next-action` (ID của action) và body trả về là React Flight stream, không phải
 * JSON thuần — xem extractFlightPayload().
 */
class KieuShopeeService
{
    /**
     * Giá trị `source` ghi vào short_links + tracking cho mọi link lấy từ đây. Trước đây
     * là tên nền tảng (facebook/zalo/...) vì salesoc trả nhiều kênh; giờ chỉ có một nguồn.
     */
    public const SOURCE = 'kieushopee';

    // Tạo link mất vài giây (bên họ phải gọi ngược sang Shopee) nên rộng tay hơn
    // 10s vốn dùng cho salesoc.vn.
    private const TIMEOUT = 20;

    // Giả lập request từ trình duyệt mobile: UA mặc định của Guzzle là thứ dễ bị lọc nhất
    // ở tầng CDN/WAF, mà đổi UA thì không ảnh hưởng gì tới cách server action xử lý.
    private const MOBILE_USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    /**
     * @return array{voucher_link: string, shop_id: ?string, item_id: ?string, product: ?array}|null
     */
    public function fetchProductAndVoucherLink(string $shopeeUrl, bool $useCache = true): ?array
    {
        if (! $useCache) {
            return $this->fetch($shopeeUrl);
        }

        // Cache ngắn hạn — cùng 1 link được dán lại (test, hoặc nhiều người cùng xem 1 sản
        // phẩm hot) thì trả ngay khỏi phải đợi round-trip. 15 phút là đủ ngắn để không giữ
        // mã đã hết lượt quá lâu (bên nguồn cũng không báo trạng thái còn/hết lượt của mã).
        return Cache::remember(
            'kieushopee:'.md5($shopeeUrl),
            now()->addMinutes(15),
            fn () => $this->fetch($shopeeUrl),
        );
    }

    private function fetch(string $shopeeUrl): ?array
    {
        $endpoint = (string) config('services.kieushopee.endpoint');
        $origin = $this->originOf($endpoint);

        try {
            $response = Http::withHeaders([
                'Accept' => 'text/x-component',
                'Next-Action' => (string) config('services.kieushopee.next_action'),
                'Origin' => $origin,
                'Referer' => $endpoint,
                'User-Agent' => self::MOBILE_USER_AGENT,
            ])
                ->timeout(self::TIMEOUT)
                ->asMultipart()
                ->post($endpoint, [
                    ['name' => '1_url', 'contents' => $shopeeUrl],
                    ['name' => '1_toolId', 'contents' => (string) config('services.kieushopee.tool_id')],
                    // Cách Next.js đóng gói tham số cho Server Action: field "0" là danh sách
                    // tham số, "$K1" là tham chiếu tới cụm field có tiền tố "1_" ở trên.
                    ['name' => '0', 'contents' => '["$K1"]'],
                ]);
        } catch (\Exception $e) {
            Log::error('KieuShopeeService: lỗi kết nối tới '.$endpoint.': '.$e->getMessage(), [
                'shopee_url' => $shopeeUrl,
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('KieuShopeeService: bị từ chối', [
                'status' => $response->status(),
                'shopee_url' => $shopeeUrl,
                'body' => Str::limit($response->body(), 300),
            ]);

            return null;
        }

        return $this->parseStreamResponse($response->body(), $shopeeUrl);
    }

    private function parseStreamResponse(string $body, string $shopeeUrl): ?array
    {
        $payload = $this->extractFlightPayload($body);

        if ($payload === null || ! ($payload['success'] ?? false)) {
            Log::error('KieuShopeeService: response không đọc được hoặc success=false', [
                'shopee_url' => $shopeeUrl,
                'body' => Str::limit($body, 500),
            ]);

            return null;
        }

        $result = $payload['results'][0] ?? [];
        $link = $result['link'] ?? null;

        if (! is_string($link) || $link === '') {
            // HTTP 200 + success=true nhưng không có link — người dùng thấy "chưa lấy được mã"
            // y hệt lúc API lỗi, nên phải phân biệt được trong log.
            Log::warning('KieuShopeeService: trả về success nhưng không có link', [
                'shopee_url' => $shopeeUrl,
                'body' => Str::limit($body, 500),
            ]);

            return null;
        }

        return [
            'voucher_link' => $link,
            'shop_id' => isset($result['shopId']) ? (string) $result['shopId'] : null,
            'item_id' => isset($result['itemId']) ? (string) $result['itemId'] : null,
            'product' => $this->normalizeProductInfo($payload['productInfo'] ?? []),
        ];
    }

    /**
     * Body là React Flight stream: mỗi dòng có dạng "<id>:<json>", dữ liệu thật hiện đang
     * nằm ở dòng "1:" nhưng id đó do Next tự đánh và có thể đổi khi họ sửa component.
     * Nên quét mọi dòng và lấy dòng đầu tiên có đúng shape mình cần, thay vì hard-code "1:".
     * Dòng không phải JSON (vd "1:I[...]" khai báo module) tự bị loại vì json_decode trả null.
     */
    private function extractFlightPayload(string $body): ?array
    {
        foreach (preg_split('/\r?\n/', trim($body)) as $line) {
            if (! preg_match('/^[0-9a-f]+:(.*)$/i', $line, $m)) {
                continue;
            }

            $data = json_decode($m[1], true);

            if (is_array($data) && (array_key_exists('results', $data) || array_key_exists('success', $data))) {
                return $data;
            }
        }

        return null;
    }

    /**
     * `productInfo` là dữ liệu sản phẩm bên nguồn kèm theo để khỏi phải gọi Shopee lần nữa.
     *
     * Shape THẬT đo được từ server production ngày 2026-09-07 (giá là VND thật, ảnh là URL
     * đầy đủ, key camelCase):
     *   {"itemId":"...","shopId":"...","name":"Áo Hoodie ...","currency":"VND",
     *    "image":"https://down-vn.img.susercontent.com/file/vn-...",
     *    "price":78000,"priceBeforeDiscount":200000,"priceMin":78000,"priceMax":78000}
     *
     * Vẫn nhận diện rộng tay (kể cả shape thô của Shopee: snake_case + giá micro + hash ảnh)
     * vì shape do họ quyết và có thể đổi; không chắc thì trả null để ShopeeVoucherController
     * tự rơi về ShopeeLinkResolverService::fetchProductInfo().
     *
     * Kết quả giữ ĐÚNG shape của ShopeeLinkResolverService::fetchProductInfo() để hai bên
     * dùng thay thế được cho nhau ở phía controller/frontend.
     */
    private function normalizeProductInfo(array $info): ?array
    {
        $name = $this->firstString($info, ['name', 'productName', 'product_name', 'title']);

        if ($name === null) {
            return null;
        }

        // API item của Shopee trả giá ở đơn vị "micro" (×100.000). Nhận diện bằng KEY đặc
        // trưng của shape đó chứ không bằng độ lớn con số — 100.000.000 vừa có thể là 1.000₫
        // dạng micro, vừa có thể là một sản phẩm giá 100 triệu thật. Shape thật của kieushopee
        // dùng camelCase nên không rơi vào nhánh này (đúng: 78000 là 78.000₫).
        $isMicro = array_key_exists('itemid', $info) || array_key_exists('price_before_discount', $info);

        $current = $this->firstPrice($info, ['price', 'priceMin', 'price_min', 'discountedPrice', 'discounted_price'], $isMicro);
        $original = $this->firstPrice($info, ['price_before_discount', 'priceBeforeDiscount', 'originalPrice', 'original_price'], $isMicro);

        return [
            'product_name' => $name,
            'product_image' => $this->imageUrl($info),
            'original_price' => $original ?? $current,
            'discounted_price' => $current,
            'discount_percent' => $this->discountPercent($info, $original, $current),
            'sold_count' => (int) ($info['sold'] ?? $info['historical_sold'] ?? $info['sold_count'] ?? 0),
            'rating' => (float) ($info['item_rating']['rating_star'] ?? $info['rating'] ?? 0),
        ];
    }

    /**
     * kieushopee không trả sẵn % giảm (khác Shopee có `raw_discount`), nhưng trả cả giá gốc
     * lẫn giá sau giảm nên tự tính được — thiếu nó thì mọi sản phẩm đều hiện "giảm 0%".
     */
    private function discountPercent(array $info, ?float $original, ?float $current): int
    {
        if (isset($info['raw_discount']) || isset($info['discount'])) {
            return (int) ($info['raw_discount'] ?? $info['discount']);
        }

        if (! $original || ! $current || $original <= $current) {
            return 0;
        }

        return (int) round(($original - $current) / $original * 100);
    }

    private function firstString(array $info, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $info[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Giá có thể là số (thô hoặc micro) hoặc chuỗi đã format sẵn kiểu "₫199.000" —
     * chuỗi thì chỉ lấy chữ số, và không bao giờ chia micro vì đã là giá hiển thị.
     */
    private function firstPrice(array $info, array $keys, bool $isMicro): ?float
    {
        foreach ($keys as $key) {
            $value = $info[$key] ?? null;

            if (is_int($value) || is_float($value)) {
                return $isMicro ? $value / 100000 : (float) $value;
            }

            if (is_string($value) && ($digits = preg_replace('/[^\d]/', '', $value)) !== '') {
                return (float) $digits;
            }
        }

        return null;
    }

    private function imageUrl(array $info): ?string
    {
        $image = $info['image'] ?? $info['imageUrl'] ?? $info['image_url'] ?? ($info['images'][0] ?? null);

        if (! is_string($image) || $image === '') {
            return null;
        }

        // Shopee trả về HASH ảnh chứ không phải URL — ghép CDN giống ShopeeLinkResolverService.
        return Str::startsWith($image, ['http://', 'https://'])
            ? $image
            : 'https://cf.shopee.vn/file/'.$image;
    }

    private function originOf(string $endpoint): string
    {
        $parts = parse_url($endpoint);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
    }
}
