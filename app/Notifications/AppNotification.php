<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Lớp nền cho mọi thông báo trong app: chỉ ghi DB (chuông ở header đọc từ bảng notifications),
 * không gửi mail. Dữ liệu là {title, body, url, icon} — chuông và trang /thong-bao chỉ biết
 * đúng 4 khoá này, con nào cũng phải trả đủ.
 */
abstract class AppNotification extends Notification
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, body: string, url: string|null, icon: string}
     */
    abstract public function toArray(object $notifiable): array;
}
