<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tra mã quốc gia (ISO alpha-2, VD: VN, JP) từ IP qua ip-api.com — dùng cho middleware
 * chặn theo quốc gia (GeoBlock). Chạy ĐỒNG BỘ (không qua queue) vì middleware cần biết
 * ngay để quyết định chặn/không chặn — khác với ResolveActivityLocation (chạy nền, chỉ
 * để hiển thị thống kê, không cần biết ngay).
 */
class GeoIpService
{
    public function countryCode(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        // Cache::remember không lưu lại giá trị null (coi là cache-miss, gọi lại API mỗi
        // lần) — dùng 'UNKNOWN' làm giá trị đại diện để những IP tra không ra vẫn được
        // cache, tránh dội API liên tục khi ip-api.com lỗi hoặc IP không xác định được.
        $code = Cache::remember("geoip_code:{$ip}", now()->addDay(), function () use ($ip) {
            try {
                $response = Http::timeout(2)->get("http://ip-api.com/json/{$ip}", [
                    'fields' => 'status,countryCode',
                ]);

                if (! $response->ok() || $response->json('status') !== 'success') {
                    return 'UNKNOWN';
                }

                return $response->json('countryCode') ?? 'UNKNOWN';
            } catch (\Exception $e) {
                Log::warning('GeoIpService: lookup thất bại', ['ip' => $ip, 'error' => $e->getMessage()]);

                return 'UNKNOWN';
            }
        });

        return $code === 'UNKNOWN' ? null : $code;
    }
}
