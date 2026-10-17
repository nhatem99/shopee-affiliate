<?php

namespace App\Http\Controllers;

use App\Services\CashbackLeaderboardService;
use App\Services\CashbackLinkService;
use App\Services\CashbackService;
use App\Services\DailyCheckInService;
use App\Services\MembershipTierService;
use App\Services\TrackingService;
use App\Services\YtbVoucherStatusService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Trang chủ. Tách khỏi closure trong web.php để CashbackLinkController render lại đúng trang này
 * (props() + cashbackResult) sau mỗi lần dán link — bảng xếp hạng, điểm danh... không biến mất.
 * Hai bản theo công tắc "Lấy mã giảm giá trên web" — xem component().
 */
class HomeController extends Controller
{
    public function __construct(
        private TrackingService $tracking,
        private CashbackService $cashback,
        private CashbackLeaderboardService $leaderboard,
        private MembershipTierService $tiers,
        private DailyCheckInService $checkIn,
        private YtbVoucherStatusService $ytbVouchers,
    ) {}

    public function index(Request $request): Response
    {
        $this->tracking->log('page_view', $request, ['url' => $request->fullUrl()]);

        return Inertia::render(self::component(), $this->props($request));
    }

    /**
     * Web tắt lấy mã → trang chủ chỉ hoàn tiền (bố cục theo dealngon, Pages/HomeCashback.vue);
     * bật → trang lấy mã cũ (Pages/Home.vue).
     */
    public static function component(): string
    {
        return CashbackLinkService::voucherToolEnabled() ? 'Home' : 'HomeCashback';
    }

    /**
     * @return array<string, mixed>
     */
    public function props(Request $request): array
    {
        $voucherTool = CashbackLinkService::voucherToolEnabled();

        return [
            // Công cụ lấy mã chỉ dùng được trên điện thoại — admin luôn xem/test được từ máy tính.
            // Chỉ Pages/Home.vue (web bật lấy mã) đọc; trang hoàn tiền dùng được cả trên máy tính.
            'canUseVoucherTool' => TrackingService::isMobile($request->userAgent())
                || ($request->user()?->isAdmin() ?? false),
            // Bảng xếp hạng hoàn tiền tháng này. Chỉ tính khi chương trình đang bật: tắt rồi mà vẫn
            // truy vấn là tốn công cho một khối frontend đằng nào cũng ẩn (useCashback::cashbackOn).
            'leaderboard' => $this->cashback->rate() > 0 ? $this->leaderboard->forMonth($request->user()) : null,
            // Hạng thành viên & đặc quyền. null = chương trình tắt, khối ở frontend tự biến mất
            // (MembershipTierService::enabled đã gộp luôn điều kiện tỉ lệ hoàn tiền > 0).
            'membershipTiers' => $this->tiers->enabled() ? $this->tiers->publicTiers($request->user()) : null,
            // Điểm danh nhận quà. null = admin đã tắt, thẻ ở frontend tự biến mất. Đóng closure để
            // những lượt partial reload không xin tới nó (ví dụ reload riêng voucherResult sau mỗi lần
            // dán link) không phải chạy lại mấy truy vấn đếm kho quà.
            'dailyCheckIn' => fn () => $this->checkIn->enabled() ? $this->checkIn->state($request->user()) : null,
            // Khối "Ưu đãi đang có" (mã YTB còn bao nhiêu %) — null khi nguồn không phải ganma hoặc
            // chưa có số liệu, xem YtbVoucherStatusService. Web tắt lấy mã thì không có mã nào để khoe.
            'ytbVouchers' => fn () => $voucherTool ? $this->ytbVouchers->forDisplay() : null,
        ];
    }
}
