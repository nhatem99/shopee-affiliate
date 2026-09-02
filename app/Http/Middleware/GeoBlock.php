<?php

namespace App\Http\Middleware;

use App\Services\GeoIpService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GeoBlock
{
    // Chỉ cho phép Việt Nam và Nhật Bản — theo yêu cầu. Đổi ở đây nếu cần thêm quốc gia.
    private const ALLOWED_COUNTRIES = ['VN', 'JP'];

    public function __construct(private GeoIpService $geoIp) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // /admin/login luôn mở — không tự khóa admin nếu admin đang ở nước ngoài hoặc
        // IP-api.com tra sai. IP nội bộ/private (localhost, LAN) không tra được quốc gia
        // nên GeoIpService trả null — coi như không xác định được thì KHÔNG chặn (fail-open),
        // tránh chặn nhầm hàng loạt nếu ip-api.com bị lỗi/hết quota.
        if ($request->routeIs('admin.login', 'admin.login.store')) {
            return $next($request);
        }

        $countryCode = $this->geoIp->countryCode($request->ip());

        if ($countryCode !== null && ! in_array($countryCode, self::ALLOWED_COUNTRIES, true)) {
            abort(403);
        }

        return $next($request);
    }
}
