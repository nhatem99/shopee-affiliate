<?php

namespace App\Notifications;

use App\Models\Withdrawal;

/**
 * Admin duyệt/chuyển/từ chối lệnh rút thì khách phải được báo — trước đây họ chỉ biết bằng
 * cách vào lại trang Tài khoản xem bảng.
 */
class WithdrawalStatusNotification extends AppNotification
{
    public function __construct(private readonly Withdrawal $withdrawal) {}

    public function toArray(object $notifiable): array
    {
        $amount = number_format((float) $this->withdrawal->amount, 0, ',', '.').' đ';

        [$title, $body, $icon] = match ($this->withdrawal->status) {
            'approved' => ['Yêu cầu rút tiền đã được duyệt', "Lệnh rút {$amount} đã được duyệt, bên mình sẽ chuyển về ví của bạn sớm.", '✅'],
            'completed' => ['Đã chuyển tiền', "{$amount} đã được chuyển về ví của bạn.".($this->withdrawal->transaction_ref ? " Mã giao dịch: {$this->withdrawal->transaction_ref}." : ''), '💸'],
            'rejected' => ['Yêu cầu rút tiền bị từ chối', "Lệnh rút {$amount} không được duyệt.".($this->withdrawal->admin_note ? " Lý do: {$this->withdrawal->admin_note}." : '').' Tiền đã trả lại ví.', '⚠️'],
            default => ['Cập nhật yêu cầu rút tiền', "Lệnh rút {$amount} vừa được cập nhật.", 'ℹ️'],
        };

        return ['title' => $title, 'body' => $body, 'url' => '/profile', 'icon' => $icon];
    }
}
