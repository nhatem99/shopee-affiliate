<?php

namespace App\Notifications;

/**
 * Đơn vừa xuất hiện lần đầu trong "Đơn hàng của tôi" — vẫn đang chờ Shopee xác nhận, chưa có
 * đồng nào vào ví. Gửi ngay lúc này chứ không đợi tiền về: khách đặt xong thường vào xem ngay
 * hôm đó, thấy trang trống là tưởng đơn không được ghi nhận rồi hỏi/khiếu nại.
 */
class NewOrderNotification extends AppNotification
{
    public function __construct(
        private readonly string $orderId,
        private readonly ?string $productName,
        private readonly int $otherItems,
        /** Số tiền dự kiến theo tỉ lệ hiện tại; null khi chương trình đang tắt (tỉ lệ 0). */
        private readonly ?float $estimate,
    ) {}

    public function toArray(object $notifiable): array
    {
        $product = $this->productName ? mb_strimwidth($this->productName, 0, 60, '…') : 'Đơn #'.$this->orderId;
        $suffix = $this->otherItems > 0 ? ' (+'.$this->otherItems.' sản phẩm)' : '';

        $body = $product.$suffix.'.';
        $body .= $this->estimate !== null && $this->estimate > 0
            ? ' Dự kiến hoàn '.number_format($this->estimate, 0, ',', '.').' đ — tiền vào ví sau khi Shopee xác nhận đơn hoàn thành.'
            : ' Tiền hoàn sẽ vào ví sau khi Shopee xác nhận đơn hoàn thành.';

        return [
            'title' => 'Đã ghi nhận đơn hàng mới',
            'body' => $body,
            'url' => '/don-hang',
            'icon' => '🛒',
            // Không hiển thị; để orders:notify-backfill biết đơn nào đã được báo rồi.
            'order_id' => $this->orderId,
        ];
    }
}
