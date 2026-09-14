<?php

namespace App\Notifications;

class WelcomeBonusNotification extends AppNotification
{
    public function __construct(private readonly float $amount) {}

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Thưởng người mới',
            'body' => 'Bạn đã nhận '.number_format($this->amount, 0, ',', '.').' đ thưởng người mới vào ví. Có đơn hoàn tiền đầu tiên là rút được cùng nhau.',
            'url' => '/vi/lich-su',
            'icon' => '🎁',
        ];
    }
}
