<?php

namespace App\Services;

use App\Jobs\SendZaloMessage;
use App\Models\ZaloChat;
use Illuminate\Support\Facades\DB;

/**
 * Xử lý một update Zalo Bot đẩy về webhook (cùng dạng với một kết quả của getUpdates):
 *
 *   {"ok": true, "result": {"event_name": "message.text.received",
 *     "message": {"from": {...}, "chat": {"id", "chat_type"}, "text", "message_id", "date"}}}
 *
 * Việc chính ở giai đoạn này là GHI LẠI chat_id — Zalo không có API liệt kê người đã nhắn,
 * mà bot chỉ gửi được tới ai đã nhắn trước.
 */
class ZaloWebhookService
{
    /**
     * @return bool false nếu update trùng (đã xử lý) hoặc không dùng được
     */
    public function handle(array $payload): bool
    {
        // Tài liệu mẫu bọc trong {ok, result}; chấp nhận cả dạng không bọc cho chắc.
        $update = is_array($payload['result'] ?? null) ? $payload['result'] : $payload;

        $message = $update['message'] ?? null;
        $chatId = (string) data_get($message, 'chat.id', '');
        $messageId = (string) data_get($message, 'message_id', '');
        $event = (string) ($update['event_name'] ?? '');

        if ($chatId === '' || $messageId === '') {
            return false;
        }

        // Zalo gửi lại khi lần trước mình trả lời chậm. Unique(message_id) chặn ở DB nên hai
        // request trùng đến cùng lúc cũng chỉ một cái lọt qua.
        $inserted = DB::table('zalo_webhook_messages')->insertOrIgnore([
            'message_id' => $messageId,
            'chat_id' => $chatId,
            'event_name' => $event,
            'created_at' => now(),
        ]);
        if ($inserted === 0) {
            return false;
        }

        ZaloChat::updateOrCreate(['chat_id' => $chatId], array_filter([
            'chat_type' => data_get($message, 'chat.chat_type'),
            'display_name' => data_get($message, 'from.display_name'),
            'last_message_at' => now(),
        ], fn ($v) => $v !== null));

        $text = trim((string) ($message['text'] ?? ''));
        if ($event === 'message.text.received' && in_array(mb_strtolower($text), ['/id', 'id'], true)) {
            SendZaloMessage::dispatch($chatId, "chat_id của bạn: {$chatId}");
        }

        return true;
    }
}
