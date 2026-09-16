<?php

namespace App\Notifications;

/**
 * Admin vừa trả lời trong chat hỗ trợ, và khách đang không mở trang đó (xem ChatService::notifyUser).
 */
class AdminReplyNotification extends AppNotification
{
    public function __construct(private readonly string $body) {}

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Hỗ trợ đã trả lời bạn',
            'body' => mb_strimwidth(trim($this->body), 0, 120, '…'),
            'url' => '/ho-tro',
            'icon' => '💬',
        ];
    }
}
