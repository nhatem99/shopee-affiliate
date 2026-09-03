<?php

namespace App\Services;

use App\Jobs\ResolveActivityLocation;
use App\Models\UserActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Jenssegers\Agent\Agent;

/**
 * Ghi nhận hành vi người dùng (paste link, chọn mã, copy mã, click link...)
 * để phục vụ thống kê thiết bị/vị trí/nền tảng cho admin và tối ưu sản phẩm.
 * Vị trí (quốc gia/thành phố) được tra cứu bất đồng bộ qua ResolveActivityLocation
 * để không làm chậm request của người dùng.
 */
class TrackingService
{
    public function log(string $eventType, Request $request, array $data = []): ?UserActivity
    {
        $userAgent = (string) $request->userAgent();

        // Bỏ qua bot/crawler (Facebook link-preview, Google, script không gửi UA...) —
        // không ghi vào thống kê để admin theo dõi đúng hành vi người dùng thật.
        if (self::isBot($userAgent)) {
            return null;
        }

        $agent = new Agent;
        $agent->setUserAgent($userAgent);
        $traffic = $this->resolveTrafficSource($request);

        $activity = UserActivity::create([
            'user_id' => $request->user()?->id,
            'session_id' => $request->hasSession() ? $request->session()->getId() : null,
            'event_type' => $eventType,
            'url' => $data['url'] ?? null,
            'platform' => $data['platform'] ?? null,
            'product_name' => $data['product_name'] ?? null,
            'voucher_code' => $data['voucher_code'] ?? null,
            'source' => $data['source'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $userAgent ?: null,
            'device_type' => $this->resolveDeviceType($agent),
            'browser' => $agent->browser() ?: null,
            'os_name' => $agent->platform() ?: null,
            'metadata' => $data['metadata'] ?? null,
            ...$traffic,
        ]);

        if ($activity->ip_address) {
            ResolveActivityLocation::dispatch($activity->id, $activity->ip_address);
        }

        return $activity;
    }

    /**
     * Ghi lại sự kiện liên quan bảo mật (đăng nhập sai, OTP sai, bị chặn admin, bị rate-limit...)
     * để admin theo dõi dấu hiệu tấn công trong /admin/activities. KHÔNG áp bộ lọc bot của
     * log() — chính traffic không có User-Agent/giả UA mới là thứ cần thấy ở đây, lọc nó đi
     * sẽ che mất chứng cứ của một cuộc tấn công tự động. Ghi thêm ra log file (kênh daily)
     * để có thể grep nhanh khi điều tra mà không cần vào DB.
     */
    public function logSecurityEvent(string $eventType, Request $request, array $data = []): UserActivity
    {
        $userAgent = (string) $request->userAgent();
        $agent = new Agent;
        $agent->setUserAgent($userAgent);

        $activity = UserActivity::create([
            'user_id' => $request->user()?->id,
            'session_id' => $request->hasSession() ? $request->session()->getId() : null,
            'event_type' => $eventType,
            'source' => $data['source'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $userAgent ?: null,
            'device_type' => $userAgent ? $this->resolveDeviceType($agent) : null,
            'browser' => $userAgent ? ($agent->browser() ?: null) : null,
            'os_name' => $userAgent ? ($agent->platform() ?: null) : null,
            'metadata' => $data['metadata'] ?? null,
        ]);

        if ($activity->ip_address) {
            ResolveActivityLocation::dispatch($activity->id, $activity->ip_address);
        }

        Log::channel('daily')->warning("Security event: {$eventType}", [
            'ip' => $request->ip(),
            'path' => $request->path(),
            'metadata' => $data['metadata'] ?? null,
        ]);

        return $activity;
    }

    /**
     * Dùng chung cho việc ghi log (bỏ qua bot) và dọn dữ liệu bot cũ trong bảng
     * (Admin > Theo dõi > Xoá log bot) — cùng một tiêu chí nhận diện.
     */
    public static function isBot(?string $userAgent): bool
    {
        if (! $userAgent) {
            return true;
        }

        $agent = new Agent;
        $agent->setUserAgent($userAgent);

        return $agent->isRobot();
    }

    /**
     * Công cụ lấy mã chỉ dành cho khách dùng điện thoại (bấm link Facebook/Zalo trên di động) —
     * dùng chung tiêu chí này để vừa ẩn/hiện giao diện, vừa chặn ở backend (admin luôn được qua,
     * xem ShopeeVoucherController::resolve).
     */
    public static function isMobile(?string $userAgent): bool
    {
        if (! $userAgent) {
            return false;
        }

        $agent = new Agent;
        $agent->setUserAgent($userAgent);

        return $agent->isMobile() || $agent->isTablet();
    }

    /**
     * Android hay không — dùng để quyết định có bọc link đích bằng intent:// (mở thẳng app
     * Shopee nếu đã cài, xem ShortLinkController::redirect()) hay không. iOS không cần xử lý
     * riêng: Universal Links của Safari tự mở app khi điều hướng top-level tới link shopee.vn
     * thật (không qua JS), miễn app đã cài — cách của Android (Chrome) không tự động chắc chắn
     * bằng nên cần intent:// để ép mở app, kể cả khi bấm từ trong webview app Facebook/Zalo.
     */
    public static function isAndroid(?string $userAgent): bool
    {
        if (! $userAgent) {
            return false;
        }

        $agent = new Agent;
        $agent->setUserAgent($userAgent);

        return $agent->isAndroidOS();
    }

    /**
     * Suy ra khách vào web từ đâu (Facebook, Zalo, Google tìm kiếm, gõ thẳng link...) dựa
     * trên header Referer + tham số utm_* trên URL. Chỉ có ý nghĩa với event 'page_view' —
     * các hành động sau đó (AJAX trong trang) referer sẽ trỏ về chính domain mình nên bị
     * coi là "direct", đúng vì đó không phải lượt ghé thăm mới.
     */
    private function resolveTrafficSource(Request $request): array
    {
        $utmSource = $request->query('utm_source');
        $referer = $request->header('referer');
        $refererHost = null;

        if ($referer) {
            $host = parse_url($referer, PHP_URL_HOST);
            // Bỏ qua nếu referer là chính domain mình (điều hướng nội bộ / gọi AJAX trong trang).
            if ($host && strcasecmp($host, (string) $request->getHost()) !== 0) {
                $refererHost = strtolower($host);
            }
        }

        $label = match (true) {
            filled($utmSource) => strtolower((string) $utmSource),
            $refererHost === null => 'direct',
            str_contains($refererHost, 'facebook.') || str_contains($refererHost, 'fb.') => 'facebook',
            str_contains($refererHost, 'zalo.') => 'zalo',
            str_contains($refererHost, 'google.') => 'google',
            str_contains($refererHost, 'tiktok.') => 'tiktok',
            str_contains($refererHost, 'youtube.') || str_contains($refererHost, 'youtu.be') => 'youtube',
            str_contains($refererHost, 'instagram.') => 'instagram',
            default => $refererHost,
        };

        return [
            'traffic_source' => $label,
            'referrer_host' => $refererHost,
            'utm_source' => $utmSource ? (string) $utmSource : null,
            'utm_medium' => $request->query('utm_medium') ? (string) $request->query('utm_medium') : null,
            'utm_campaign' => $request->query('utm_campaign') ? (string) $request->query('utm_campaign') : null,
        ];
    }

    private function resolveDeviceType(Agent $agent): string
    {
        return match (true) {
            $agent->isTablet() => 'tablet',
            $agent->isMobile() => 'mobile',
            default => 'desktop',
        };
    }
}
