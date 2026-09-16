<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Khung giờ nguồn kieushopee nạp lại lượt mã FB-IG ("back mã"), theo giờ Việt Nam.
 *
 * Chỉ phục vụ việc tự đổi nguồn sang FB-IG (VoucherSourceResolver::activeSource). Giao diện khách
 * KHÔNG đọc từ đây — banner "Săn sale mỗi ngày" vẫn giữ bản của nó trong
 * resources/js/Components/RestockSchedule.vue, và kho mẫu bài đăng giữ bản trong
 * PromoContentService.
 *
 * Nghĩa là mấy giờ dưới đây đang có ba bản chép tay. Sửa một bản thì phải sửa cả ba: bản này
 * quyết định LÚC NÀO hệ thống đổi nguồn, hai bản kia là giờ hẹn với khách — lệch nhau thì banner
 * hẹn một giờ còn hệ thống đổi nguồn ở giờ khác, và không có gì báo lỗi cả.
 */
class RestockScheduleService
{
    /**
     * Múi giờ của lịch back mã. Ghi thẳng ra thay vì dựa vào config('app.timezone'): lịch này là
     * của nguồn cấp mã (họ back theo giờ VN), không phải của server — đổi timezone ứng dụng vì
     * lý do khác mà kéo theo lịch này trôi đi là hỏng âm thầm.
     */
    public const TIMEZONE = 'Asia/Ho_Chi_Minh';

    /** Mã Facebook/Instagram — nguồn kieushopee. */
    public const FB_IG_HOURS = [0, 9, 15, 20];

    /**
     * Một đợt back mã coi như còn "nóng" trong bao lâu kể từ mốc giờ. Mã back ra có giới hạn lượt
     * và hết nhanh, nên đây là ước lượng chứ không phải cam kết của nguồn.
     */
    public const WINDOW_MINUTES = 60;

    public function inFbIgWindow(?CarbonInterface $at = null): bool
    {
        return $this->fbIgWindowEndsAt($at) !== null;
    }

    /**
     * Thời điểm khung giờ FB-IG đang mở sẽ đóng lại, hoặc null nếu hiện không nằm trong khung nào.
     * Trả về mốc đóng (chứ không chỉ true/false) để trang admin nói được "tới mấy giờ".
     */
    public function fbIgWindowEndsAt(?CarbonInterface $at = null): ?CarbonImmutable
    {
        // Qua now() của Laravel chứ không phải CarbonImmutable::now() trực tiếp: test-now mà
        // travelTo() đặt nằm trên Carbon (mutable), CarbonImmutable::now() không thấy nó — đóng
        // băng thời gian trong test sẽ im lặng không có tác dụng.
        $now = CarbonImmutable::parse($at ?? now())->setTimezone(self::TIMEZONE);

        // Quét cả mốc của HÔM QUA: với WINDOW_MINUTES đủ dài, khung mở lúc 20h có thể còn kéo
        // sang sau nửa đêm — chỉ xét mốc trong ngày là lúc 00:30 tưởng đã ngoài khung.
        foreach ([-1, 0] as $dayOffset) {
            foreach (self::FB_IG_HOURS as $hour) {
                $start = $now->startOfDay()->addDays($dayOffset)->addHours($hour);
                $end = $start->addMinutes(self::WINDOW_MINUTES);

                if ($now >= $start && $now < $end) {
                    return $end;
                }
            }
        }

        return null;
    }
}
