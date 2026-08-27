<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => self::MOBILE_USER_AGENT,
            ])->timeout(10)->post(self::ENDPOINT, [
                'url' => $shopeeUrl,
            ]);

            if (! $response->successful() || ! $response->json('success')) {
                return null;
            }

            $data = $response->json();
            $price = $this->parsePrice($data['price'] ?? null);

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
                'voucher_links' => [
                    'facebook' => $this->extractOptions($data, 'facebookAffiliateUrls'),
                    'instagram' => $this->extractOptions($data, 'instagramAffiliateUrls'),
                    'zalo' => $this->extractOptions($data, 'zaloAffiliateUrls'),
                    'youtube' => $this->extractYoutubeOption($data),
                ],
            ];
        } catch (\Exception $e) {
            Log::warning('SalesOcService fetch failed: '.$e->getMessage());

            return null;
        }
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
