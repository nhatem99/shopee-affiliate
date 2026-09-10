<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\CashbackService;
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
            'communityUrl' => Setting::get('community_url') ?: '',
            'cashbackRate' => (float) Setting::get(CashbackService::RATE_KEY, 0),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        // Mỗi toggle ở trang Cài đặt tự POST riêng field của nó, nên field nào cũng
        // là "sometimes" — không bắt buộc phải gửi hết cùng lúc.
        $validated = $request->validate([
            'customer_auth_enabled' => ['sometimes', 'boolean'],
            'maintenance_mode' => ['sometimes', 'boolean'],
            'community_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'cashback_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]);

        if (array_key_exists('customer_auth_enabled', $validated)) {
            Setting::set('customer_auth_enabled', $validated['customer_auth_enabled'] ? '1' : '0');
        }

        if (array_key_exists('maintenance_mode', $validated)) {
            Setting::set('maintenance_mode', $validated['maintenance_mode'] ? '1' : '0');
        }

        if (array_key_exists('community_url', $validated)) {
            Setting::set('community_url', $validated['community_url'] ?? '');
        }

        if (array_key_exists('cashback_rate', $validated)) {
            Setting::set(CashbackService::RATE_KEY, (string) $validated['cashback_rate']);

            // Đổi tỉ lệ phải áp ngay cho những đơn ĐÃ nhập, không chỉ đơn nhập về sau: nếu không,
            // admin đặt tỉ lệ lần đầu sau khi đã nhập báo cáo sẽ thấy không có gì xảy ra cả.
            app(CashbackService::class)->sync();
        }

        return back()->with('success', 'Đã lưu cài đặt.');
    }
}
