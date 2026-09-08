<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'settings' => [
                'customerAuthEnabled' => Setting::getBool('customer_auth_enabled', true),
                // Link nhóm/cộng đồng săn sale hiện ở banner "Khung giờ back mã"
                // (RestockSchedule.vue). Chưa đặt thì banner tự ẩn dòng link đi.
                'communityUrl' => Setting::get('community_url') ?: null,
                // Chỉ admin cần cờ này: admin duyệt trang khách y như bình thường khi đang bảo
                // trì (xem MaintenanceMode) nên rất dễ quên là khách vẫn đang bị chặn — AppLayout
                // dựa vào đây để hiện thanh nhắc.
                'maintenanceMode' => ($request->user()?->isAdmin() ?? false)
                    && Setting::getBool('maintenance_mode', false),
            ],
        ]);
    }
}
