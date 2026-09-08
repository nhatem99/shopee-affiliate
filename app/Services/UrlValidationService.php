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
        // App Shopee cũng phát ra link shope.ee. Thiếu nó thì khách dán đúng thứ mà chính giao
        // diện admin bảo họ dán (GanmaService::testConnection) lại bị chặn ngay từ cửa với
        // thông báo "Chỉ hỗ trợ link sản phẩm Shopee".
        'shope.ee',
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

    // Domain được phép làm đích cho short-link /go/{code}: link sản phẩm Shopee trực tiếp,
    // hoặc link voucher do kieushopee phát ra — thực tế là short-link của chính Shopee
    // (shp.ee/shope.ee/s.shopee.vn) hoặc của mạng affiliate (s.afp.ad) đứng trước một
    // chuỗi redirect kết thúc ở shopee.vn.
    private array $allowedRedirectDomains = [
        'shopee.vn',
        's.shopee.vn',
        'shp.ee',
        'shope.ee',
        's.afp.ad',
        'kieushopee.com',
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

        // Pattern: /product/SHOP_ID/ITEM_ID — dạng Shopee dùng cho link chia sẻ từ ứng dụng, và
        // cũng là dạng mà chuỗi redirect của link voucher hay kết thúc ở đó. Thiếu dạng này thì
        // mọi thứ nhận diện sản phẩm qua id đều mù trước đúng loại link khách hay dán nhất.
        //
        // /opaanlp/ là trang landing áp mã của chương trình affiliate — đích của link nguồn
        // ganma. Thiếu nó thì productKey() (ShortLinkController) không đọc được id, phải rơi
        // xuống băm URL đích; mà Shopee cấp credential_token mới sau mỗi lượt bấm nên URL đó
        // khác nhau mỗi lần → mỗi lượt bấm lại đăng một comment / thuê một reel mới.
        if (preg_match('#/(?:product|opaanlp)/(\d+)/(\d+)#', $url, $m)) {
            return ['shop_id' => $m[1], 'item_id' => $m[2]];
        }

        return [];
    }
}
