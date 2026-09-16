<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\CashbackLeaderboardService;
use App\Services\CashbackService;
use App\Services\VoucherSourceResolver;
use App\Services\WelcomeBonusService;
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
            'festiveDecor' => Setting::getBool('festive_decor', false),
            'historyRebuyEnabled' => Setting::getBool('history_rebuy_enabled', false),
            'fbigWindowAutoSwitch' => VoucherSourceResolver::autoSwitchEnabled(),
            'leaderboardDemo' => Setting::getBool(CashbackLeaderboardService::DEMO_KEY, CashbackLeaderboardService::DEMO_DEFAULT),
            'communityUrl' => Setting::get('community_url') ?: '',
            'messengerUrl' => Setting::get('messenger_url') ?: '',
            'cashbackRate' => (float) Setting::get(CashbackService::RATE_KEY, 0),
            'welcomeBonusEnabled' => Setting::getBool(WelcomeBonusService::ENABLED_KEY, true),
            'welcomeBonusAmount' => (float) Setting::get(WelcomeBonusService::AMOUNT_KEY, WelcomeBonusService::DEFAULT_AMOUNT),
            // Trả về null (không phải 0) khi chưa đặt, để ô nhập hiện trống = "theo tỉ lệ thực".
            'cashbackDisplayRate' => Setting::get(CashbackService::DISPLAY_RATE_KEY) !== null
                && Setting::get(CashbackService::DISPLAY_RATE_KEY) !== ''
                ? (float) Setting::get(CashbackService::DISPLAY_RATE_KEY)
                : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        // Mỗi toggle ở trang Cài đặt tự POST riêng field của nó, nên field nào cũng
        // là "sometimes" — không bắt buộc phải gửi hết cùng lúc.
        $validated = $request->validate([
            'customer_auth_enabled' => ['sometimes', 'boolean'],
            'maintenance_mode' => ['sometimes', 'boolean'],
            'festive_decor' => ['sometimes', 'boolean'],
            'history_rebuy_enabled' => ['sometimes', 'boolean'],
            'fbig_window_auto_switch' => ['sometimes', 'boolean'],
            'leaderboard_demo' => ['sometimes', 'boolean'],
            'community_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'messenger_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'cashback_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'cashback_display_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'welcome_bonus_enabled' => ['sometimes', 'boolean'],
            'welcome_bonus_amount' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);

        if (array_key_exists('welcome_bonus_enabled', $validated)) {
            Setting::set(WelcomeBonusService::ENABLED_KEY, $validated['welcome_bonus_enabled'] ? '1' : '0');
        }

        // Chỉ áp cho tài khoản đăng ký TỪ ĐÂY về sau — không cộng/trừ lại ai đã nhận rồi.
        if (array_key_exists('welcome_bonus_amount', $validated)) {
            Setting::set(WelcomeBonusService::AMOUNT_KEY, (string) $validated['welcome_bonus_amount']);
        }

        if (array_key_exists('customer_auth_enabled', $validated)) {
            Setting::set('customer_auth_enabled', $validated['customer_auth_enabled'] ? '1' : '0');
        }

        if (array_key_exists('maintenance_mode', $validated)) {
            Setting::set('maintenance_mode', $validated['maintenance_mode'] ? '1' : '0');
        }

        if (array_key_exists('festive_decor', $validated)) {
            Setting::set('festive_decor', $validated['festive_decor'] ? '1' : '0');
        }

        if (array_key_exists('history_rebuy_enabled', $validated)) {
            Setting::set('history_rebuy_enabled', $validated['history_rebuy_enabled'] ? '1' : '0');
        }

        if (array_key_exists('fbig_window_auto_switch', $validated)) {
            Setting::set(VoucherSourceResolver::AUTO_SWITCH_KEY, $validated['fbig_window_auto_switch'] ? '1' : '0');
        }

        if (array_key_exists('leaderboard_demo', $validated)) {
            Setting::set(CashbackLeaderboardService::DEMO_KEY, $validated['leaderboard_demo'] ? '1' : '0');
        }

        if (array_key_exists('community_url', $validated)) {
            Setting::set('community_url', $validated['community_url'] ?? '');
        }

        if (array_key_exists('messenger_url', $validated)) {
            Setting::set('messenger_url', $validated['messenger_url'] ?? '');
        }

        if (array_key_exists('cashback_rate', $validated)) {
            Setting::set(CashbackService::RATE_KEY, (string) $validated['cashback_rate']);

            // Đổi tỉ lệ phải áp ngay cho những đơn ĐÃ nhập, không chỉ đơn nhập về sau: nếu không,
            // admin đặt tỉ lệ lần đầu sau khi đã nhập báo cáo sẽ thấy không có gì xảy ra cả.
            app(CashbackService::class)->sync();
        }

        // Chỉ là con số hiển thị, không đụng tới tiền — nên KHÔNG sync. Rỗng = xoá để quay về
        // bám theo tỉ lệ thực (xem CashbackService::displayRate).
        if (array_key_exists('cashback_display_rate', $validated)) {
            $display = $validated['cashback_display_rate'];
            Setting::set(CashbackService::DISPLAY_RATE_KEY, $display === null ? '' : (string) $display);
        }

        return back()->with('success', 'Đã lưu cài đặt.');
    }
}
