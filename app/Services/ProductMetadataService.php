<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lấy thông tin sản phẩm (tên, ảnh, giá...) từ link người dùng dán — best-effort,
 * không cần API key. Thứ tự ưu tiên:
 *   1. Theo redirect để giải link rút gọn (shp.ee, s.shopee.vn, vt.tiktok.com...)
 *   2. Tiki: API công khai theo product id (ổn định nhất)
 *   3. JSON-LD (schema.org Product) nhúng trong HTML
 *   4. Thẻ meta Open Graph / Twitter
 *
 * Trả về null nếu không lấy được gì — caller tự fallback.
 *
 * Các request ra ngoài được đăng ký vào 1 pool dùng chung với AccessTrade/Shopee voucher
 * (xem AffiliateScanOrchestrator) để chạy song song thay vì cộng dồn thời gian chờ.
 */
class ProductMetadataService
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    public function __construct(
        private UrlValidationService $urlValidator,
        private ShopeeProductLookupService $shopeeLookup,
    ) {}

    /** Đăng ký các request có thể chạy song song; trả về false nếu đã có cache (khỏi gọi mạng thừa). */
    public function registerPoolRequests(Pool $pool, string $url, string $platform): bool
    {
        if (Cache::has($this->cacheKey($url))) {
            return false;
        }

        $pool->as('meta_page')->withHeaders([
            'User-Agent' => self::UA,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'vi,en;q=0.9',
        ])->connectTimeout(5)->timeout(8)->get($url);

        // Phần lớn link người dùng dán là link sản phẩm đầy đủ (đã chứa id), nên có thể
        // trích id ngay từ URL gốc và gọi song song luôn, không cần đợi giải redirect trước.
        if ($platform === 'tiki' && preg_match('/-p(\d+)\.html/i', $url, $m)) {
            $pool->as('meta_tiki')->withHeaders(['User-Agent' => self::UA])
                ->connectTimeout(5)->timeout(8)
                ->get("https://tiki.vn/api/v2/products/{$m[1]}");
        }

        if ($platform === 'shopee') {
            $ids = $this->urlValidator->extractShopeeIds($url);
            if ($ids) {
                $pool->as('meta_shopee')->timeout(10)
                    ->get(ShopeeProductLookupService::BASE_URL, ['item_id' => $ids['item_id']]);
            }
        }

        return true;
    }

    /** Ghép kết quả từ pool ở trên thành metadata sản phẩm, cache lại 6 giờ như trước. */
    public function resolveFromPool(array $responses, string $url, string $platform): ?array
    {
        $cacheKey = $this->cacheKey($url);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $data = $this->parsePoolResponses($responses, $url, $platform);
        Cache::put($cacheKey, $data, now()->addHours(6));

        return $data;
    }

    private function cacheKey(string $url): string
    {
        return 'product_meta:'.md5($url);
    }

    private function parsePoolResponses(array $responses, string $url, string $platform): ?array
    {
        try {
            if (isset($responses['meta_tiki'])
                && $responses['meta_tiki'] instanceof Response
                && $responses['meta_tiki']->successful()
                && ($data = $this->parseTikiResponse($responses['meta_tiki']))) {
                return $data;
            }

            if (isset($responses['meta_shopee'])
                && ($data = $this->shopeeLookup->parseResponse($responses['meta_shopee']))) {
                return $data;
            }

            $page = $responses['meta_page'] ?? null;

            // Link rút gọn: id chỉ lộ ra sau khi trang đã redirect xong nên chưa gọi song
            // song được ở trên — tra cứu thêm 1 lần ở đây (hiếm gặp, chấp nhận chậm hơn).
            if ($page instanceof Response && $platform === 'shopee' && ! isset($responses['meta_shopee'])) {
                $finalUrl = $page->effectiveUri() ? (string) $page->effectiveUri() : $url;
                $ids = $this->urlValidator->extractShopeeIds($finalUrl);
                if ($ids && $data = $this->shopeeLookup->getByItemId($ids['item_id'])) {
                    return $data;
                }
            }

            if ($page instanceof Response && $platform === 'tiki' && ! isset($responses['meta_tiki'])) {
                $finalUrl = $page->effectiveUri() ? (string) $page->effectiveUri() : $url;
                if ($data = $this->fromTikiApi($finalUrl)) {
                    return $data;
                }
            }

            if (! $page instanceof Response || ! $page->successful()) {
                return null;
            }

            $html = $page->body();

            return $this->fromJsonLd($html) ?? $this->fromOpenGraph($html);
        } catch (\Throwable $e) {
            Log::warning('ProductMetadata fetch failed: '.$e->getMessage());

            return null;
        }
    }

    private function fromTikiApi(string $url): ?array
    {
        // .../ten-san-pham-p123456.html  -> product id = 123456
        if (! preg_match('/-p(\d+)\.html/i', $url, $m)) {
            return null;
        }

        $resp = Http::withHeaders(['User-Agent' => self::UA])
            ->connectTimeout(5)->timeout(8)
            ->get("https://tiki.vn/api/v2/products/{$m[1]}");

        if (! $resp->successful()) {
            return null;
        }

        return $this->parseTikiResponse($resp);
    }

    private function parseTikiResponse(Response $resp): array
    {
        $j = $resp->json();

        return $this->normalize(
            name: $j['name'] ?? null,
            image: $j['thumbnail_url'] ?? ($j['images'][0]['base_url'] ?? null),
            price: (float) ($j['price'] ?? 0),
            listPrice: (float) ($j['list_price'] ?? $j['original_price'] ?? 0),
            rating: (float) ($j['rating_average'] ?? 0),
            sold: (int) ($j['quantity_sold']['value'] ?? 0),
        );
    }

    private function fromJsonLd(string $html): ?array
    {
        if (! preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $block) {
            $json = json_decode(trim($block), true);
            if (! is_array($json)) {
                continue;
            }

            foreach ($this->flattenLd($json) as $node) {
                if (! $this->isProductNode($node)) {
                    continue;
                }

                $offers = $node['offers'] ?? [];
                if (array_key_exists(0, $offers)) {
                    $offers = $offers[0];
                }

                $image = $node['image'] ?? null;
                if (is_array($image)) {
                    $image = $image[0] ?? null;
                }

                $price = (float) ($offers['price'] ?? $offers['lowPrice'] ?? 0);

                return $this->normalize(
                    name: $node['name'] ?? null,
                    image: is_string($image) ? $image : null,
                    price: $price,
                    listPrice: $price,
                    rating: (float) ($node['aggregateRating']['ratingValue'] ?? 0),
                    sold: (int) ($node['aggregateRating']['reviewCount'] ?? 0),
                );
            }
        }

        return null;
    }

    private function fromOpenGraph(string $html): ?array
    {
        $name = $this->meta($html, 'og:title') ?? $this->meta($html, 'twitter:title');
        $image = $this->meta($html, 'og:image') ?? $this->meta($html, 'twitter:image');
        $price = $this->meta($html, 'product:price:amount') ?? $this->meta($html, 'og:price:amount');

        if (! $name && ! $image) {
            return null;
        }

        $priceVal = (float) preg_replace('/[^\d.]/', '', (string) $price);

        return $this->normalize(
            name: $name,
            image: $image,
            price: $priceVal,
            listPrice: $priceVal,
            rating: 0,
            sold: 0,
        );
    }

    /** JSON-LD có thể là 1 node, mảng node, hoặc bọc trong @graph */
    private function flattenLd(array $json): array
    {
        if (isset($json['@graph']) && is_array($json['@graph'])) {
            return $json['@graph'];
        }

        return array_is_list($json) ? $json : [$json];
    }

    private function isProductNode(array $node): bool
    {
        $type = $node['@type'] ?? null;

        return $type === 'Product' || (is_array($type) && in_array('Product', $type, true));
    }

    /** Đọc 1 thẻ meta theo property/name (hỗ trợ cả thứ tự content/property đảo nhau) */
    private function meta(string $html, string $property): ?string
    {
        $p = preg_quote($property, '/');

        if (preg_match('/<meta[^>]+(?:property|name)=["\']'.$p.'["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)
            || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]*(?:property|name)=["\']'.$p.'["\']/i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES);
        }

        return null;
    }

    private function normalize(?string $name, ?string $image, float $price, float $listPrice, float $rating, int $sold): array
    {
        $discounted = $price > 0 ? $price : $listPrice;
        $original = max($listPrice, $discounted);
        $pct = ($original > 0 && $discounted < $original)
            ? (int) round((1 - $discounted / $original) * 100)
            : 0;

        return [
            'product_name' => $name ? trim($name) : null,
            'product_image' => $image,
            'original_price' => $original,
            'discounted_price' => $discounted,
            'discount_percent' => $pct,
            'sold_count' => $sold,
            'rating' => $rating,
        ];
    }
}
