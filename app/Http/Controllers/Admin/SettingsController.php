<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\CashbackLeaderboardService;
use App\Services\CashbackService;
use App\Services\ChatService;
use App\Services\DailyCheckInService;
use App\Services\GuideVideoService;
use App\Services\MembershipTierService;
use App\Services\SourceHealthService;
use App\Services\VoucherSourceResolver;
use App\Services\WelcomeBonusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Settings', [
            'customerAuthEnabled' => Setting::getBool('customer_auth_enabled', true),
            // Video hướng dẫn lấy mã ở trang công khai /huong-dan — xem GuideVideoService.
            'guideVideo' => app(GuideVideoService::class)->adminState(),
            'maintenanceMode' => Setting::getBool('maintenance_mode', false),
            'autoMaintenanceEnabled' => SourceHealthService::enabled(),
            // Kết quả lượt kiểm tra nguồn gần nhất — không có nó thì công tắc chỉ là cái nút câm,
            // admin không biết hệ thống có đang thật sự kiểm tra hay cron đã chết từ đời nào.
            'sourceHealth' => app(SourceHealthService::class)->status(),
            'festiveDecor' => Setting::getBool('festive_decor', false),
            'historyRebuyEnabled' => Setting::getBool('history_rebuy_enabled', false),
            'fbigWindowAutoSwitch' => VoucherSourceResolver::autoSwitchEnabled(),
            'leaderboardDemo' => Setting::getBool(CashbackLeaderboardService::DEMO_KEY, CashbackLeaderboardService::DEMO_DEFAULT),
            'communityUrl' => Setting::get('community_url') ?: '',
            'messengerUrl' => Setting::get('messenger_url') ?: '',
            'supportChatEnabled' => ChatService::enabled(),
            'cashbackRate' => (float) Setting::get(CashbackService::RATE_KEY, 0),
            'welcomeBonusEnabled' => Setting::getBool(WelcomeBonusService::ENABLED_KEY, true),
            'membershipTierEnabled' => Setting::getBool(MembershipTierService::ENABLED_KEY, true),
            'checkinEnabled' => Setting::getBool(DailyCheckInService::ENABLED_KEY, true),
            // Số liệu kho quà của HÔM NAY — không có nó thì công tắc chỉ là cái nút câm, admin không
            // biết hôm nay đã phát bao nhiêu tiền và còn lại bao nhiêu phần.
            'checkinPrizes' => app(DailyCheckInService::class)->state(null),
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
            'auto_maintenance_enabled' => ['sometimes', 'boolean'],
            'festive_decor' => ['sometimes', 'boolean'],
            'history_rebuy_enabled' => ['sometimes', 'boolean'],
            'fbig_window_auto_switch' => ['sometimes', 'boolean'],
            'leaderboard_demo' => ['sometimes', 'boolean'],
            'community_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'messenger_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'support_chat_enabled' => ['sometimes', 'boolean'],
            'cashback_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'cashback_display_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'welcome_bonus_enabled' => ['sometimes', 'boolean'],
            'membership_tier_enabled' => ['sometimes', 'boolean'],
            'checkin_enabled' => ['sometimes', 'boolean'],
            'welcome_bonus_amount' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);

        // Tắt là ngừng cộng thưởng hạng cho các khoản GHI MỚI và ẩn toàn bộ khối hạng trên
        // trang khách. Tiền đã ghi giữ nguyên — cột tier_bonus_rate của từng khoản đóng băng
        // phần thưởng của lúc ghi, nên không có chuyện gạt công tắc rồi ví khách tụt xuống.
        if (array_key_exists('membership_tier_enabled', $validated)) {
            Setting::set(MembershipTierService::ENABLED_KEY, $validated['membership_tier_enabled'] ? '1' : '0');
        }

        // Tắt là thẻ điểm danh biến khỏi trang chủ và /diem-danh từ chối mọi lượt bấm. Tiền đã
        // phát giữ nguyên, chuỗi ngày cũng giữ nguyên trong bảng check_ins — bật lại trong vòng một
        // ngày thì khách nối tiếp đúng chuỗi cũ, không ai bị mất gì vì một cú gạt công tắc.
        if (array_key_exists('checkin_enabled', $validated)) {
            Setting::set(DailyCheckInService::ENABLED_KEY, $validated['checkin_enabled'] ? '1' : '0');
        }

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

            // Gạt tay là giành lại quyền: lần bảo trì này thành của admin, lượt kiểm tra nguồn
            // kế tiếp không được tắt hộ (và không đóng lại ngay sau khi admin vừa mở).
            app(SourceHealthService::class)->handOverToAdmin();
        }

        if (array_key_exists('auto_maintenance_enabled', $validated)) {
            Setting::set(SourceHealthService::ENABLED_KEY, $validated['auto_maintenance_enabled'] ? '1' : '0');

            if (! $validated['auto_maintenance_enabled']) {
                app(SourceHealthService::class)->stopAutoMaintenance();
            }
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

        if (array_key_exists('support_chat_enabled', $validated)) {
            Setting::set(ChatService::ENABLED_KEY, $validated['support_chat_enabled'] ? '1' : '0');
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

    /**
     * Video hướng dẫn lấy mã ở trang công khai /huong-dan.
     *
     * Tách khỏi update() vì đây là request multipart (có file đính kèm), còn mọi ô cài đặt khác
     * trong trang đang POST từng field JSON một.
     *
     * Gửi file thì file thắng, không gửi file mà ô link rỗng thì coi như gỡ video — khớp với
     * GuideVideoService: một nguồn sống tại một thời điểm.
     */
    public function updateGuideVideo(Request $request, GuideVideoService $guideVideo): RedirectResponse
    {
        $request->validate([
            'video_url' => ['nullable', 'string', 'max:2000'],
            // Cố ý KHÔNG nhận mọi thứ trình duyệt gọi là video: file này nằm trên chính tên miền
            // của mình và được phát cho khách, nên chỉ nhận đúng mấy định dạng <video> mở được.
            'video_file' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime,video/x-m4v', 'max:'.GuideVideoService::MAX_FILE_KB],
        ], [
            'video_file.mimetypes' => 'Chỉ nhận file MP4, WebM hoặc MOV.',
            'video_file.max' => 'File quá nặng — tối đa '.$guideVideo->maxUploadMb().' MB.',
        ]);

        if ($request->hasFile('video_file')) {
            $guideVideo->storeFile($request->file('video_file'));

            return back()->with('success', 'Đã tải video lên — xem thử ở trang /huong-dan.');
        }

        $url = trim((string) $request->input('video_url'));

        if ($url === '') {
            $guideVideo->clear();

            return back()->with('success', 'Đã gỡ video — trang /huong-dan chỉ còn phần hướng dẫn bằng chữ.');
        }

        // Chặn ở đây thay vì để khách phát hiện hộ: link không nhận dạng được mà vẫn lưu thì
        // trang /huong-dan chỉ hiện một khung trắng, còn admin thì tưởng đã xong.
        if ($guideVideo->parse($url) === null) {
            throw ValidationException::withMessages([
                'video_url' => 'Link này không nhúng được. Nhận link YouTube (kể cả Shorts), '
                    .'TikTok dạng đầy đủ (tiktok.com/@ten/video/...), Facebook, hoặc link .mp4 trực tiếp. '
                    .'Link TikTok rút gọn (vt.tiktok.com) thì mở ra rồi copy lại link đầy đủ trên thanh địa chỉ.',
            ]);
        }

        $guideVideo->saveUrl($url);

        return back()->with('success', 'Đã lưu link video — xem thử ở trang /huong-dan.');
    }

    public function destroyGuideVideo(GuideVideoService $guideVideo): RedirectResponse
    {
        $guideVideo->clear();

        return back()->with('success', 'Đã gỡ video — trang /huong-dan chỉ còn phần hướng dẫn bằng chữ.');
    }
}
