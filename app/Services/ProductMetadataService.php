<?php

namespace App\Services;

use GuzzleHttp\TransferStats;
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
 */
class ProductMetadataService
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    public function fetch(string $url, string $platform): ?array
    {
        return Cache::remember('product_meta:'.md5($url), now()->addHours(6), function () use ($url, $platform) {
            try {
                return $this->resolve($url, $platform);
            } catch (\Throwable $e) {
                Log::warning('ProductMetadata fetch failed: '.$e->getMessage());

                return null;
            }
        });
    }

    private function resolve(string $url, string $platform): ?array
    {
        $finalUrl = $url;

        $response = Http::withHeaders([
            'User-Agent' => self::UA,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'vi,en;q=0.9',
        ])->withOptions([
            'on_stats' => function (TransferStats $stats) use (&$finalUrl) {
                if ($uri = $stats->getEffectiveUri()) {
                    $finalUrl = (string) $uri;
                }
            },
        ])->connectTimeout(5)->timeout(8)->get($url);

        // Tiki có API công khai rất ổn định — ưu tiên dùng id từ URL đã giải redirect
        if ($platform === 'tiki') {
            if ($data = $this->fromTikiApi($finalUrl)) {
                return $data;
            }
        }

        if (! $response->successful()) {
            return null;
        }

        $html = $response->body();

        return $this->fromJsonLd($html) ?? $this->fromOpenGraph($html);
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
