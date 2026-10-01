<?php

namespace App\Services;

use App\Jobs\SendZaloMessage;
use App\Models\Withdrawal;

/**
 * Báo việc cho admin qua Zalo Bot. Chưa cấu hình token hoặc chat_id admin thì im lặng bỏ
 * qua — báo Zalo là phần phụ, không được làm hỏng luồng chính đang gọi nó.
 *
 * Muốn báo thêm loại việc mới: thêm một method dựng nội dung rồi gọi notify().
 */
class ZaloAdminNotifier
{
    public function __construct(private ZaloBotSettings $settings) {}

    public function notify(string $text): void
    {
        if ($this->settings->token() === null) {
            return;
        }

        foreach ($this->settings->adminChatIds() as $chatId) {
            SendZaloMessage::dispatch($chatId, $text);
        }
    }

    public function withdrawalRequested(Withdrawal $withdrawal): void
    {
        $user = $withdrawal->user;
        $provider = $withdrawal->provider === 'momo' ? 'MoMo' : 'ZaloPay';

        $this->notify(implode("\n", [
            '💸 Yêu cầu rút tiền mới #'.$withdrawal->id,
            'Khách: '.($user?->name ?? '?').($user?->email ? " ({$user->email})" : ''),
            'Số tiền: '.number_format((float) $withdrawal->amount, 0, ',', '.').'đ',
            "Ví: {$provider} {$withdrawal->account_number} - {$withdrawal->account_name}",
            'Duyệt: '.route('admin.withdrawals'),
        ]));
    }
}
