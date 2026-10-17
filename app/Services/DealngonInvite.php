<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Công tắc "Mời khách sang dealngon" (Admin → Cài đặt, mặc định BẬT từ 17-10-2026): tietkiemvi
 * đang xây dựng lại, hoàn tiền chuyển sang dealngon — web mới của chính mình, khách cũ đã được
 * chuyển sang đó (tài khoản, ví, lịch sử).
 *
 * Bật: trang khách thôi hứa hoàn tiền ở tietkiemvi (không tiền dự kiến, không mời đăng nhập/đăng
 * ký để được hoàn) mà mời sang dealngon; khách đã đăng nhập thấy hộp thông báo một lần mỗi phiên
 * (DealngonNotice.vue). Khách vãng lai vẫn dán link lấy link bình thường, không bắt đăng nhập.
 * Chỉ đổi GIAO DIỆN — tỉ lệ hoàn và việc ghi tiền cho đơn (CashbackService::sync) giữ nguyên.
 */
class DealngonInvite
{
    public const ENABLED_KEY = 'dealngon_invite_enabled';

    public static function enabled(): bool
    {
        return Setting::getBool(self::ENABLED_KEY, true);
    }

    public static function url(): string
    {
        return (string) config('services.dealngon.url');
    }

    public static function host(): string
    {
        $url = self::url();

        return (string) (parse_url($url, PHP_URL_HOST) ?: $url);
    }
}
