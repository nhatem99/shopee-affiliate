<?php

namespace App\Notifications;

class WelcomeNotification extends AppNotification
{
    public function __construct(private readonly string $source) {}

    public function toArray(object $notifiable): array
    {
        $via = $this->source === 'google' ? ' bằng Google' : '';

        return [
            'title' => 'Chào mừng thành viên mới',
            'body' => 'Tài khoản của bạn đã được tạo'.$via.'. Dán link Shopee ở trang chủ và mua trong lúc đang đăng nhập để được hoàn tiền.',
            'url' => '/',
            'icon' => '👋',
        ];
    }
}
