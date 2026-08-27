<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Settings', [
            'customerAuthEnabled' => Setting::getBool('customer_auth_enabled', true),
            'maintenanceMode' => Setting::getBool('maintenance_mode', false),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        // Mỗi toggle ở trang Cài đặt tự POST riêng field của nó, nên field nào cũng
        // là "sometimes" — không bắt buộc phải gửi cả 2 cùng lúc.
        $validated = $request->validate([
            'customer_auth_enabled' => ['sometimes', 'boolean'],
            'maintenance_mode' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('customer_auth_enabled', $validated)) {
            Setting::set('customer_auth_enabled', $validated['customer_auth_enabled'] ? '1' : '0');
        }

        if (array_key_exists('maintenance_mode', $validated)) {
            Setting::set('maintenance_mode', $validated['maintenance_mode'] ? '1' : '0');
        }

        return back()->with('success', 'Đã lưu cài đặt.');
    }
}
