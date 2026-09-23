<?php

namespace App\Notifications;

class CheckInRewardNotification extends AppNotification
{
    public function __construct(
        private readonly float $amount,
        private readonly int $streak,
        private readonly ?int $milestone = null,
    ) {}

    public function toArray(object $notifiable): array
    {
        $money = number_format($this->amount, 0, ',', '.').' đ';

        return [
            'title' => $this->milestone !== null
                ? 'Thưởng mốc '.$this->milestone.' ngày điểm danh'
                : 'Điểm danh nhận quà',
            'body' => $this->milestone !== null
                ? 'Chuỗi '.$this->streak.' ngày liên tiếp — bạn nhận '.$money.' vào ví, đã gồm thưởng mốc '.$this->milestone.' ngày.'
                : 'Bạn nhận '.$money.' vào ví. Chuỗi hiện tại: '.$this->streak.' ngày — điểm danh tiếp để giữ chuỗi.',
            'url' => '/vi/lich-su',
            'icon' => $this->milestone !== null ? '🏆' : '🎁',
        ];
    }
}
