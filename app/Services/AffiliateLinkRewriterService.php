<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Bước KOL -> KOC: đổi mmp_pid trong link đã có mã sang affiliate ID của mình, để hoa hồng đơn
 * hàng về tài khoản của mình — trong khi vẫn giữ nguyên encrypted_payload/credential_token nên
 * mã giảm giá vẫn được áp dụng bình thường (mmp_pid là tham số tracking độc lập, không nằm
 * trong payload đã ký của Shopee).
 *
 * Đầu vào là link do App\Services\ChannelVoucher đúc ra — đã ở dạng shopee.vn nên thường không
 * phải đi hop nào.
 */
class AffiliateLinkRewriterService
{
    // shp.ee/s.shopee.vn là short-link chính thức của Shopee. s.afp.ad/salesoc.vn là di sản của
    // nguồn cũ (đã gỡ 2026-09-05) — giữ lại tới khi mọi token 'ref' phát trước đó hết hạn, vì
    // TTL của chúng là 7 ngày và trong nhóm Zalo còn link cũ người ta bấm lại. Bỏ được sau
    // 2026-09-12.
    private const HOPS_TO_FOLLOW = ['s.afp.ad', 'salesoc.vn', 'shp.ee', 's.shopee.vn'];

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

            if (! in_array($host, self::HOPS_TO_FOLLOW, true)) {
                Log::warning('AffiliateLinkRewriterService: dừng theo dõi — domain lạ, không phải chuỗi redirect biết trước', [
                    'hop' => $i,
                    'host' => $host,
                    'url' => $current,
                ]);

                return $current;
            }

            $response = Http::withOptions(['allow_redirects' => false])->timeout(10)->get($current);
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
        if (isset($query['utm_source'])) {
            $query['utm_source'] = $mmpPid;
        }
        if (isset($query['utm_content'])) {
            $query['utm_content'] = 'tietkiemvi';
        }

        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').($parts['path'] ?? '');

        return $base.'?'.http_build_query($query);
    }

    private function hostOf(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        return preg_replace('/^www\./', '', $host);
    }
}
