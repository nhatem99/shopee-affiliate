<?php

namespace App\Notifications;

use App\Services\DealngonInvite;

/**
 * Báo khách tietkiemvi sang dealngon — web mới của chính mình, tài khoản/ví/lịch sử của khách đã
 * được chuyển sang đó (tools/export-customers.php → dealngon /admin/nhap-khach, giữ nguyên email,
 * mật khẩu, Google). Gửi bằng lệnh users:notify-dealngon.
 */
class DealngonMoveNotification extends AppNotification
{
    public function toArray(object $notifiable): array
    {
        $host = DealngonInvite::host();

        return [
            'title' => "Hoàn tiền đã chuyển sang {$host}",
            'body' => "Tietkiemvi đang xây dựng lại — từ nay mua hàng qua {$host} mới được hoàn tiền. Mời bạn sang {$host} mua hàng: đăng nhập bằng đúng email, mật khẩu hoặc Google như ở đây, số dư ví và lịch sử của bạn đã được chuyển theo.",
            'url' => DealngonInvite::url(),
            'icon' => '📢',
        ];
    }
}
