<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Đưa mọi dạng link TikTok Shop khách dán về một dạng duy nhất: id sản phẩm + url chuẩn.
 *
 * Khách copy từ app TikTok gần như luôn ra link rút gọn (vt.tiktok.com/ZSxxxx, tiktok.com/t/xxxx)
 * — trong đó không có id sản phẩm, phải đi theo redirect mới thấy. Giống hệt bài toán mà
 * ShopeeLinkResolverService đang giải cho shp.ee bên Shopee.
 *
 * Vì sao cần id chứ không chỉ cần url: ACCESSTRADE nhận url nào cũng tạo được link (đo thật
 * 25-09-2026 — họ bọc nguyên văn chuỗi mình gửi vào deep_link, không kiểm tra), nên nếu đưa
 * thẳng link rút gọn cho họ thì link affiliate sẽ trỏ tới một chặng redirect của TikTok. Chặng
 * đó có thể nuốt mất tham số tracking, và khi đó đơn hàng không quy về ai cả — hỏng âm thầm,
 * đúng kiểu chỉ lộ ra ở kỳ đối soát.
 */
class TikTokLinkResolverService
{
    /** Host được phép đi theo redirect. Ngoài danh sách này thì dừng — không đuổi theo domain lạ. */
    private const TIKTOK_HOSTS = ['tiktok.com', 'vt.tiktok.com', 'vm.tiktok.com', 'shop.tiktok.com', 'm.tiktok.com'];

    /**
     * Link rút gọn của TikTok đi qua 1-2 chặng. Để 4 cho dư, nhưng không hơn: mỗi chặng là một
     * request thật nằm trong đường đi của khách đang chờ màn hình.
     */
    private const MAX_HOPS = 4;

    private const TIMEOUT = 8;

    /**
     * Dạng url chuẩn dùng cho mọi thứ phía sau (gửi ACCESSTRADE, tra thông tin sản phẩm).
     *
     * Chọn `/vn/pdp/{id}` vì đó là dạng ỨNG DỤNG TIKTOK ĐANG PHÁT RA ngày nay — đo thật
     * 25-09-2026 trên một link chia sẻ lấy từ app: vt.tiktok.com/ZS9A4R76bVmje-pJ8pO redirect
     * 301 thẳng tới https://shop.tiktok.com/vn/pdp/1732117639001048907?_d=...&chain_key=...
     *
     * Feed của ACCESSTRADE thì lại trả dạng cũ /view/product/{id}, và cả hai dạng đều còn sống
     * (đều HTTP 200) lẫn đều được họ nhận khi tạo link. Vẫn chọn dạng của app vì trang sản phẩm
     * TikTok chỉ là vỏ rỗng dựng bằng JS — mình KHÔNG có cách nào kiểm chứng từ server rằng dạng
     * cũ còn hiện đúng sản phẩm hay đã âm thầm thành trang trống trên điện thoại khách.
     *
     * Cắt sạch tham số của link chia sẻ (_d, chain_key, _svg...) chứ không mang theo: đó là
     * tracking của NGƯỜI ĐÃ CHIA SẺ link đó, mang theo là tự nguyện đẩy công của mình cho họ.
     */
    private const CANONICAL_FORMAT = 'https://shop.tiktok.com/vn/pdp/%s';

    /**
     * @return array{product_id: string, canonical_url: string}|null null = không phải link sản
     *                                                               phẩm TikTok Shop (link video,
     *                                                               trang shop, hoặc link hỏng).
     */
    public function resolve(string $url): ?array
    {
        $current = trim($url);

        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            // Kiểm tra host TRƯỚC khi bóc id, không phải sau. Bóc trước thì một chặng redirect
            // ra domain lạ chứa "/view/product/999..." cũng cho ra id, và mình sẽ dựng link
            // affiliate cho một sản phẩm do bên ngoài chỉ định. Không có ai báo lỗi cả — url
            // cuối vẫn là shop.tiktok.com hợp lệ, chỉ là sai sản phẩm khách muốn mua.
            if (! $this->isTikTokHost($current)) {
                Log::info('TikTokLinkResolverService: dừng vì không còn ở domain TikTok', [
                    'hop' => $hop,
                    'url' => $current,
                ]);

                return null;
            }

            if ($id = $this->extractProductId($current)) {
                return [
                    'product_id' => $id,
                    'canonical_url' => sprintf(self::CANONICAL_FORMAT, $id),
                ];
            }

            $next = $this->followOneHop($current);

            if ($next === null) {
                return null;
            }

            $current = $next;
        }

        Log::warning('TikTokLinkResolverService: vượt quá số chặng cho phép mà chưa thấy id sản phẩm', [
            'url_dau' => $url,
            'url_cuoi' => $current,
        ]);

        return null;
    }

    /** Link đã là link sản phẩm TikTok Shop chưa (không cần gọi mạng)? */
    public function isTikTokUrl(string $url): bool
    {
        return $this->isTikTokHost($url);
    }

    /**
     * Bốn dạng đã gặp thật:
     *   shop.tiktok.com/vn/pdp/1732117639001048907        ← app TikTok phát ra (đo 25-09-2026)
     *   shop.tiktok.com/view/product/1733724538346374522  ← feed của ACCESSTRADE trả về
     *   www.tiktok.com/view/product/1733724538346374522
     *   ...?product_id=1733724538346374522
     *
     * Tiền tố quốc gia trong /vn/pdp/ để hờ (`[a-z]{2}`) vì cùng một sản phẩm ở thị trường khác
     * sẽ ra /th/pdp/, /id/pdp/... — chặn cứng "vn" thì link khách dán từ vùng khác rơi hết.
     */
    private function extractProductId(string $url): ?string
    {
        if (preg_match('#/(?:[a-z]{2}/)?pdp/(\d{6,})#', $url, $m)) {
            return $m[1];
        }

        if (preg_match('#/view/product/(\d{6,})#', $url, $m)) {
            return $m[1];
        }

        if (preg_match('#[?&]product_id=(\d{6,})#', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    /** @return string|null URL của chặng kế tiếp; null nếu hết chặng hoặc request hỏng. */
    private function followOneHop(string $url): ?string
    {
        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->withHeaders([
                    // TikTok trả nội dung khác hẳn cho UA lạ. Dùng UA điện thoại vì link rút gọn
                    // vốn sinh ra từ app điện thoại.
                    'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
                ])
                ->timeout(self::TIMEOUT)
                ->get($url);
        } catch (\Exception $e) {
            Log::warning('TikTokLinkResolverService: lỗi khi đi theo redirect: '.$e->getMessage(), ['url' => $url]);

            return null;
        }

        $location = $response->header('Location');

        if ($location === '') {
            Log::info('TikTokLinkResolverService: chặng cuối không còn redirect, cũng không có id sản phẩm', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return null;
        }

        // TikTok có trả Location tương đối. Ghép lại theo host hiện tại, nếu không vòng sau sẽ
        // coi "/view/product/..." là một url không có host rồi bỏ cuộc.
        if (str_starts_with($location, '/')) {
            $parts = parse_url($url);

            return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').$location;
        }

        return $location;
    }

    private function isTikTokHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);

        if ($host === '') {
            return false;
        }

        foreach (self::TIKTOK_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
