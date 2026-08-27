<?php

namespace App\Services;

use App\Jobs\ResolveActivityLocation;
use App\Models\UserActivity;
use Illuminate\Http\Request;
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
        ]);

        if ($activity->ip_address) {
            ResolveActivityLocation::dispatch($activity->id, $activity->ip_address);
        }

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

    private function resolveDeviceType(Agent $agent): string
    {
        return match (true) {
            $agent->isTablet() => 'tablet',
            $agent->isMobile() => 'mobile',
            default => 'desktop',
        };
    }
}
