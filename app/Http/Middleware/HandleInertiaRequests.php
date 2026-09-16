<?php

namespace App\Http\Middleware;

use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Models\Setting;
use App\Services\CashbackService;
use App\Services\ChatService;
use App\Services\ImpersonationService;
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
                // Admin đang "xem như khách" (ImpersonationService): AppLayout dựa vào đây để hiện
                // thanh "Đang xem với tư cách ... — Thoát". Null với mọi phiên bình thường.
                'impersonator' => fn () => $this->impersonator($request),
            ],
            // Chuông thông báo ở header. Chỉ khách (không phải admin) mới có; đóng trong closure
            // để không tốn hai truy vấn cho khách vãng lai và các request partial reload.
            'notifications' => fn () => $this->notifications($request),
            // Badge chat hỗ trợ: số tin chưa đọc của khách, hoặc số khách đang chờ admin trả
            // lời. Đóng closure như notifications() — khách vãng lai không tốn truy vấn nào.
            'chat' => fn () => $this->chat($request),
            // Banner "Số dư khả dụng" trong AccountDrawer.vue (menu trượt từ icon hamburger).
            // Cùng lý do đóng closure như notifications(): availableBalance() chạy 2 query tổng
            // hợp, chỉ đáng tính trên full visit của khách đã đăng nhập, không phải mọi request.
            'wallet' => fn () => $this->wallet($request),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'settings' => [
                'customerAuthEnabled' => Setting::getBool('customer_auth_enabled', true),
                // Link nhóm/cộng đồng săn sale hiện ở banner "Khung giờ back mã"
                // (RestockSchedule.vue). Chưa đặt thì banner tự ẩn dòng link đi.
                'communityUrl' => Setting::get('community_url') ?: null,
                // Icon Messenger nổi (AppLayout.vue) — khách bấm là nhảy thẳng sang chat trên
                // Messenger. Chưa đặt thì icon tự ẩn, không hiện nút chết dẫn về đâu cả.
                'messengerUrl' => Setting::get('messenger_url') ?: null,
                // Chat hỗ trợ ngay trong web (/ho-tro). Bật thì nút nổi góc màn hình của khách
                // đã đăng nhập dẫn vào đó thay vì sang Messenger — xem AppLayout.vue.
                'supportChatEnabled' => ChatService::enabled(),
                // Chỉ admin cần cờ này: admin duyệt trang khách y như bình thường khi đang bảo
                // trì (xem MaintenanceMode) nên rất dễ quên là khách vẫn đang bị chặn — AppLayout
                // dựa vào đây để hiện thanh nhắc.
                'maintenanceMode' => ($request->user()?->isAdmin() ?? false)
                    && Setting::getBool('maintenance_mode', false),
                // Tỉ lệ hoàn tiền — chia sẻ toàn cục vì nội dung quảng bá hoàn tiền nằm rải khắp
                // các trang khách (trang chủ, tài khoản, thanh điều hướng), không riêng trang nào.
                //
                // Đây cũng là CÔNG TẮC của toàn bộ nội dung đó: mặc định setting này bằng 0, và
                // khi bằng 0 thì CashbackService::sync() không tạo đồng hoa hồng nào (xem
                // CashbackService::sync). Nói chuyện hoàn tiền trong lúc hệ thống trả 0đ cho tất
                // cả mọi người là hứa suông — nên frontend bọc mọi khối hoàn tiền bằng
                // `cashbackRate > 0` (xem composable useCashback).
                //
                // Là tỉ lệ HIỂN THỊ (displayRate), không phải tỉ lệ tính tiền: admin có thể đặt
                // hai số khác nhau ở trang Cài đặt. Công tắc bật/tắt vẫn theo tỉ lệ thực.
                'cashbackRate' => app(CashbackService::class)->displayRate(),
                // Mức rút tối thiểu đi kèm luôn: nội dung nào nhắc tới con số này (trang tài
                // khoản, khối giải thích ở trang chủ) cũng phải lấy động, tránh cảnh sửa hằng số
                // trong PHP rồi quên mất mấy chỗ đã gõ cứng "50.000đ" trong .vue.
                'minWithdrawal' => ProfileController::MIN_WITHDRAWAL,
                // Lớp trang trí theo mùa trên trang khách (Components/FestiveDecor.vue).
                'festiveDecor' => Setting::getBool('festive_decor', false),
                // Cho khách bấm mua lại thẳng từ lịch sử hay bắt dán lại link để quét mới. Chia sẻ
                // toàn cục vì có HAI trang lịch sử dùng tới: khối lịch sử trên trang chủ
                // (Pages/Home.vue, lưu ở localStorage) và trang /history (Pages/History.vue).
                // Mặc định TẮT: mã trong link cũ có thể đã hết lượt/hết hạn từ lúc quét.
                'historyRebuyEnabled' => Setting::getBool('history_rebuy_enabled', false),
            ],
        ]);
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function impersonator(Request $request): ?array
    {
        $admin = app(ImpersonationService::class)->impersonator($request);

        return $admin ? ['id' => $admin->id, 'name' => $admin->name] : null;
    }

    /**
     * @return array{unread: int, latest: array<int, array<string, mixed>>}|null
     */
    private function notifications(Request $request): ?array
    {
        $user = $request->user();

        if (! $user || $user->isAdmin()) {
            return null;
        }

        return [
            'unread' => $user->unreadNotifications()->count(),
            'latest' => $user->notifications()->limit(5)->get()
                ->map(fn ($n) => NotificationController::present($n))
                ->values()
                ->all(),
        ];
    }

    /**
     * Khách: số tin admin gửi mà chưa đọc. Admin: số hội thoại đang chờ trả lời.
     *
     * Admin vẫn có badge kể cả khi chat đang tắt ở Cài đặt — tắt chỉ chặn khách gửi THÊM, những
     * gì đang chờ trả lời thì không được phép biến mất khỏi tầm mắt.
     *
     * @return array{unread: int}|null
     */
    private function chat(Request $request): ?array
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        $chat = app(ChatService::class);

        if ($user->isAdmin()) {
            return ['unread' => $chat->unreadForAdmin()];
        }

        return ChatService::enabled() ? ['unread' => $chat->unreadForUser($user)] : null;
    }

    /**
     * @return array{available: float}|null
     */
    private function wallet(Request $request): ?array
    {
        $user = $request->user();

        if (! $user || $user->isAdmin()) {
            return null;
        }

        return ['available' => $user->availableBalance()];
    }
}
