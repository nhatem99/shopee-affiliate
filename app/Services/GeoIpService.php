<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tra mã quốc gia (ISO alpha-2, VD: VN, JP) từ IP qua ip-api.com — dùng cho middleware
 * chặn theo quốc gia (GeoBlock). Khác với ResolveActivityLocation (chạy nền qua queue, chỉ
 * để hiển thị thống kê): kết quả ở đây quyết định chặn/không chặn.
 */
class GeoIpService
{
    /**
     * Mã quốc gia nếu IP này ĐÃ được tra (còn trong cache); chưa thì trả null ngay và tra sau
     * khi đã trả trang cho khách (defer) — GeoBlock dùng hàm này để không ai phải chờ ip-api.com.
     *
     * Trước đây middleware gọi thẳng countryCode() và đứng chờ: request đầu tiên của mỗi IP mới
     * trong ngày (khách vừa bấm link từ Facebook/Zalo, mạng điện thoại đổi IP liên tục) chậm thêm
     * cả một vòng sang ip-api.com, tới 2 giây khi bên đó chậm hoặc quá giới hạn 45 lượt/phút lúc
     * đông khách. Đánh đổi đã chấp nhận: IP nước ngoài lọt đúng request đầu, từ request sau mới
     * bị chặn.
     */
    public function cachedCountryCode(string $ip): ?string
    {
        if (! $this->isPublic($ip)) {
            return null;
        }

        $code = Cache::get($this->cacheKey($ip));

        if ($code === null) {
            // always: request này kết thúc bằng lỗi (404, 422...) thì vẫn phải tra — Laravel mặc
            // định bỏ việc defer của response lỗi.
            defer(fn () => $this->countryCode($ip), "geoip:{$ip}", always: true);

            return null;
        }

        return $code === 'UNKNOWN' ? null : $code;
    }

    public function countryCode(string $ip): ?string
    {
        if (! $this->isPublic($ip)) {
            return null;
        }

        // Cache::remember không lưu lại giá trị null (coi là cache-miss, gọi lại API mỗi
        // lần) — dùng 'UNKNOWN' làm giá trị đại diện để những IP tra không ra vẫn được
        // cache, tránh dội API liên tục khi ip-api.com lỗi hoặc IP không xác định được.
        $code = Cache::remember($this->cacheKey($ip), now()->addDay(), function () use ($ip) {
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

    /** IP nội bộ/private (localhost, LAN) không tra được quốc gia. */
    private function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function cacheKey(string $ip): string
    {
        return "geoip_code:{$ip}";
    }
}
