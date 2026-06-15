<?php

namespace App\Services;

use App\Exceptions\AffiliateScanException;

class UrlValidationService
{
    private array $allowedDomains = [
        'shopee.vn',
        'shp.ee',
        's.shopee.vn',
        'lazada.vn',
        'lzd.co',
        'tiki.vn',
        'tiktok.com',
        'vt.tiktok.com',
    ];

    private array $platformMap = [
        'shopee.vn' => 'shopee',
        'shp.ee' => 'shopee',
        's.shopee.vn' => 'shopee',
        'lazada.vn' => 'lazada',
        'lzd.co' => 'lazada',
        'tiki.vn' => 'tiki',
        'tiktok.com' => 'tiktok',
        'vt.tiktok.com' => 'tiktok',
    ];

    public function validate(string $url): string
    {
        $parsed = parse_url($url);

        if (!$parsed || empty($parsed['host'])) {
            throw new AffiliateScanException('URL không hợp lệ.');
        }

        $host = strtolower($parsed['host']);
        $host = preg_replace('/^www\./', '', $host);

        foreach ($this->allowedDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $this->platformMap[$domain] ?? 'shopee';
            }
        }

        throw new AffiliateScanException(
            'Chỉ hỗ trợ link từ Shopee, Lazada, Tiki và TikTok Shop.'
        );
    }

    public function extractShopeeIds(string $url): array
    {
        // Pattern: /product-name-i.ITEM_ID.SHOP_ID
        if (preg_match('/-i\.(\d+)\.(\d+)/', $url, $m)) {
            return ['item_id' => $m[1], 'shop_id' => $m[2]];
        }

        return [];
    }
}
