<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Đổi mmp_pid trong link voucher lấy từ kieushopee (shp.ee/shope.ee/s.afp.ad/...) sang
 * affiliate ID của mình, để hoa hồng đơn hàng về tài khoản của mình thay vì tài khoản
 * của nguồn cấp mã — trong khi vẫn giữ nguyên encrypted_payload/credential_token nên
 * mã giảm giá vẫn được áp dụng bình thường (mmp_pid là tham số tracking độc lập,
 * không nằm trong payload đã ký).
 */
class AffiliateLinkRewriterService
{
    private const HOPS_TO_FOLLOW = ['s.afp.ad', 'shp.ee', 'shope.ee', 's.shopee.vn', 'kieushopee.com'];

    private const MAX_HOPS = 4;

    public function rewriteToOwnAffiliate(string $url): string
    {
        try {
            $resolved = $this->followToShopee($url);
            $rewritten = $this->swapMmpPid($resolved) ?? $resolved;

            Log::info('AffiliateLinkRewriterService: rewrite hoàn tất', [
                'input_url' => $url,
                'resolved_url' => $resolved,
                'final_url' => $rewritten,
                'mmp_pid_swapped' => $rewritten !== $resolved,
            ]);

            return $rewritten;
        } catch (\Exception $e) {
            Log::warning('AffiliateLinkRewriterService failed: '.$e->getMessage(), ['input_url' => $url]);

            return $url;
        }
    }

    private function followToShopee(string $url): string
    {
        $current = $url;

        for ($i = 0; $i < self::MAX_HOPS; $i++) {
            $host = $this->hostOf($current);

            if ($host === 'shopee.vn') {
                Log::info('AffiliateLinkRewriterService: chạm tới shopee.vn', ['hop' => $i, 'url' => $current]);

                return $current;
            }

            if (! $this->isKnownHop($host)) {
                Log::warning('AffiliateLinkRewriterService: dừng theo dõi — domain lạ, không phải chuỗi redirect biết trước', [
                    'hop' => $i,
                    'host' => $host,
                    'url' => $current,
                ]);

                return $current;
            }

            $response = Http::withOptions(['allow_redirects' => false])->timeout(6)->get($current);
            $location = $response->header('Location');

            Log::info('AffiliateLinkRewriterService: theo dõi 1 hop redirect', [
                'hop' => $i,
                'from' => $current,
                'status' => $response->status(),
                'location' => $location,
            ]);

            if (! $location) {
                return $current;
            }

            $current = $location;
        }

        Log::warning('AffiliateLinkRewriterService: vượt quá MAX_HOPS mà chưa tới shopee.vn', ['last_url' => $current]);

        return $current;
    }

    private function swapMmpPid(string $url): ?string
    {
        if ($this->hostOf($url) !== 'shopee.vn') {
            return null;
        }

        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);

        if (! isset($query['mmp_pid'])) {
            Log::warning('AffiliateLinkRewriterService: URL shopee.vn nhưng không có mmp_pid để đổi', ['url' => $url]);

            return null;
        }

        $mmpPid = config('services.shopee_affiliate.mmp_pid');
        $query['mmp_pid'] = $mmpPid;

        // utm_source đi kèm mmp_pid cho khớp nhau — đây là affiliate ID, thứ Shopee vốn phải
        // biết để trả hoa hồng, không tiết lộ thêm gì về website nguồn.
        if (isset($query['utm_source'])) {
            $query['utm_source'] = $mmpPid;
        }

        // KHÔNG gắn tên website vào URL. Trước đây chỗ này đặt utm_content = 'tietkiemvi',
        // nghĩa là mọi đơn hàng đều tự khai với Shopee rằng traffic đến từ tietkiemvi.com —
        // trong khi cả thiết kế (bọc qua comment Facebook) là để lượt click được tính là
        // traffic từ Facebook. Xoá hẳn tham số thay vì để nguyên giá trị của nguồn cấp mã,
        // vì giá trị đó cũng là một định danh không phải của mình.
        //
        // An toàn: utm_* là tham số tracking độc lập, không nằm trong encrypted_payload /
        // credential_token đã ký — bỏ đi không ảnh hưởng việc áp mã giảm giá.
        unset($query['utm_content']);

        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').($parts['path'] ?? '');

        return $base.'?'.http_build_query($query);
    }

    /**
     * Khớp cả subdomain (vd sansale.kieushopee.com) — nguồn cấp mã hay đổi subdomain theo
     * từng tool, mà chặn nhầm ở đây thì link vẫn chạy nhưng mất luôn phần đổi mmp_pid.
     */
    private function isKnownHop(string $host): bool
    {
        foreach (self::HOPS_TO_FOLLOW as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private function hostOf(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        return preg_replace('/^www\./', '', $host);
    }
}
