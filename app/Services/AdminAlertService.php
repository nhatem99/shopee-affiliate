<?php

namespace App\Services;

use App\Models\Withdrawal;

/**
 * Những việc đang chờ admin xử lý — số trên chuông ở header và badge ở sidebar trang quản trị.
 *
 * Chỉ đếm việc mà KHÁCH đang đứng chờ (họ nhắn mà chưa ai trả lời, họ rút mà chưa ai duyệt).
 * Muốn thêm loại việc mới: thêm một key ở đây rồi thêm một dòng trong ALERTS của AdminLayout.vue.
 */
class AdminAlertService
{
    public function __construct(private ChatService $chat) {}

    /**
     * @return array{chats: int, withdrawals: int}
     */
    public function counts(): array
    {
        return [
            'chats' => $this->chat->unreadForAdmin(),
            'withdrawals' => Withdrawal::where('status', 'pending')->count(),
        ];
    }
}
