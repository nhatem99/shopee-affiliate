<?php

namespace App\Notifications;

/**
 * Hoa hồng của một đơn vừa được cộng vào ví (CashbackService::award tạo bản ghi commissions).
 * Đây là lúc khách thật sự có tiền để rút — khác NewOrderNotification chỉ báo "đã thấy đơn".
 */
class CommissionCreditedNotification extends AppNotification
{
    public function __construct(
        private readonly float $amount,
        private readonly string $orderId,
    ) {}

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Tiền hoàn đã vào ví',
            'body' => 'Đơn '.$this->orderId.' đã hoàn thành, +'.number_format($this->amount, 0, ',', '.').' đ được cộng vào ví. Bấm để xem số dư và rút tiền.',
            'url' => '/vi/lich-su',
            'icon' => '💰',
        ];
    }
}
