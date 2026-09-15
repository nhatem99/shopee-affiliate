<?php

namespace App\Http\Middleware;

use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TouchLastSeen
{
    /** Ghi thưa: cùng một phiên lướt 30 trang trong 5 phút chỉ tốn một lần UPDATE. */
    public const INTERVAL_MINUTES = 5;

    /**
     * Cập nhật users.last_seen_at cho cột "Truy cập cuối" ở Admin → Tài khoản.
     *
     * Dùng cột riêng thay vì tra user_activities: nhật ký đó chỉ ghi page_view ở trang chủ và
     * bỏ qua admin/bot, lại có thể bị dọn — không đủ tin để nói "khách này lần cuối vào lúc nào".
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Admin đang "vào tài khoản khách" thì đừng ghi lên khách — đó là admin xem, không phải khách vào.
        if ($user && ! app(ImpersonationService::class)->isActive($request)) {
            $stale = $user->last_seen_at === null
                || $user->last_seen_at->lt(now()->subMinutes(self::INTERVAL_MINUTES));

            if ($stale) {
                // Query builder thẳng, không qua save(): không chạm updated_at, không bắn event.
                $user->newQuery()->whereKey($user->id)->update(['last_seen_at' => now()]);
                $user->setAttribute('last_seen_at', now());
            }
        }

        return $next($request);
    }
}
