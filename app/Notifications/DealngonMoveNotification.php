<?php

namespace App\Notifications;

/**
 * Báo khách tietkiemvi sang dealngon — web mới của chính mình, tài khoản/ví/lịch sử của khách đã
 * được chuyển sang đó (tools/export-customers.php → dealngon /admin/nhap-khach, giữ nguyên email,
 * mật khẩu, Google). Gửi bằng lệnh users:notify-dealngon.
 */
class DealngonMoveNotification extends AppNotification
{
    public function toArray(object $notifiable): array
    {
        $url = (string) config('services.dealngon.url');
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: $url);

        return [
            'title' => "Tietkiemvi đã chuyển sang {$host}",
            'body' => "Mời bạn sang {$host} để mua hoàn tiền tiếp. Tài khoản, số dư ví và lịch sử của bạn được chuyển sang đó — đăng nhập bằng đúng email, mật khẩu hoặc Google như ở đây.",
            'url' => $url,
            'icon' => '📢',
        ];
    }
}
