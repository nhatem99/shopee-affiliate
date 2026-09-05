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

        if (! $parsed || empty($parsed['host'])) {
            throw new AffiliateScanException('URL không hợp lệ.');
        }

        $host = strtolower($parsed['host']);
        $host = preg_replace('/^www\./', '', $host);

        foreach ($this->allowedDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $this->platformMap[$domain] ?? 'shopee';
            }
        }

        throw new AffiliateScanException(
            'Chỉ hỗ trợ link từ Shopee, Lazada, Tiki và TikTok Shop.'
        );
    }

    // Domain sản phẩm/short-link chính thức của Shopee — dùng để lọc input người dùng dán vào.
    private array $shopeeInputDomains = [
        'shopee.vn',
        's.shopee.vn',
        'shp.ee',
    ];

    public function validateShopeeOnly(string $url): void
    {
        $parsed = parse_url($url);

        if (! $parsed || empty($parsed['host'])) {
            throw new AffiliateScanException('URL không hợp lệ.');
        }

        $host = strtolower($parsed['host']);
        $host = preg_replace('/^www\./', '', $host);

        foreach ($this->shopeeInputDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return;
            }
        }

        throw new AffiliateScanException('Chỉ hỗ trợ link sản phẩm Shopee.');
    }

    // Domain được phép làm đích cho short-link /go/{code}. shopee.vn/shp.ee là đích thật của link
    // đã đúc mã; salesoc.vn/s.afp.ad là di sản của nguồn cũ (đã gỡ 2026-09-05), giữ lại tới khi
    // mọi token 'ref' phát trước đó hết hạn (TTL 7 ngày) — bỏ được sau 2026-09-12.
    private array $allowedRedirectDomains = [
        'shopee.vn',
        's.shopee.vn',
        'shp.ee',
        'salesoc.vn',
        's.afp.ad',
    ];

    public function validateAffiliateRedirectUrl(string $url): void
    {
        $parsed = parse_url($url);

        if (! $parsed || empty($parsed['host'])) {
            throw new AffiliateScanException('URL không hợp lệ.');
        }

        $host = strtolower($parsed['host']);
        $host = preg_replace('/^www\./', '', $host);

        foreach ($this->allowedRedirectDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return;
            }
        }

        throw new AffiliateScanException('Link không hợp lệ.');
    }

    public function extractShopeeIds(string $url): array
    {
        // Pattern: /product-name-i.SHOP_ID.ITEM_ID
        if (preg_match('/-i\.(\d+)\.(\d+)/', $url, $m)) {
            return ['shop_id' => $m[1], 'item_id' => $m[2]];
        }

        // Pattern: /product/SHOP_ID/ITEM_ID — dạng Shopee dùng cho link affiliate (link KOL đúc ra
        // luôn ở dạng này). Cần nhận ra để đọc lại được ID từ chính link mình vừa đúc.
        if (preg_match('#/product/(\d+)/(\d+)#', $url, $m)) {
            return ['shop_id' => $m[1], 'item_id' => $m[2]];
        }

        return [];
    }
}
