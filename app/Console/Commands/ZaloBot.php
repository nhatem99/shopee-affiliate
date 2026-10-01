<?php

namespace App\Console\Commands;

use App\Services\ZaloBotService;
use App\Services\ZaloWebhookService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Kiểm tra nhanh Zalo Bot: token còn sống không, webhook đang trỏ đâu, và (khi CHƯA đặt
 * webhook) lấy tin chờ để biết chat_id của người vừa nhắn.
 */
class ZaloBot extends Command
{
    protected $signature = 'zalo:bot
        {--updates : Lấy tin đang chờ bằng getUpdates (chỉ chạy được khi chưa đặt webhook)}
        {--send= : Gửi thử một tin tới các chat_id admin}';

    protected $description = 'Xem thông tin Zalo Bot, lấy chat_id, gửi thử tin cho admin';

    public function handle(ZaloBotService $bot, ZaloWebhookService $webhook): int
    {
        if (! $bot->isConfigured()) {
            $this->error('Chưa cấu hình ZALO_BOT_TOKEN trong .env.');

            return self::FAILURE;
        }

        try {
            $me = $bot->getMe();
            $this->info("Bot: {$me['display_name']} (id {$me['id']}, {$me['account_name']})");

            $hook = $bot->getWebhookInfo();
            $this->line('Webhook: '.($hook['url'] ?? '(chưa đặt)'));
        } catch (RuntimeException $e) {
            // getWebhookInfo trả 404 khi chưa từng đặt webhook.
            if (! str_contains($e->getMessage(), 'getWebhookInfo')) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->line('Webhook: (chưa đặt)');
        }

        $admins = config('services.zalo_bot.admin_chat_ids', []);
        $this->line('Admin chat_id: '.($admins ? implode(', ', $admins) : '(chưa có — đặt ZALO_BOT_ADMIN_CHAT_IDS)'));

        if ($this->option('updates')) {
            return $this->pullUpdates($bot, $webhook);
        }

        if (($text = $this->option('send')) !== null) {
            if (! $admins) {
                $this->error('Chưa có chat_id admin để gửi.');

                return self::FAILURE;
            }
            foreach ($admins as $chatId) {
                $bot->sendMessage($chatId, $text);
                $this->info("Đã gửi tới {$chatId}.");
            }
        }

        return self::SUCCESS;
    }

    private function pullUpdates(ZaloBotService $bot, ZaloWebhookService $webhook): int
    {
        $this->line('Đang chờ tin (nhắn gì đó cho bot)...');

        $count = 0;
        while (($update = $bot->getUpdates(10)) !== null) {
            $chatId = data_get($update, 'message.chat.id');
            $name = data_get($update, 'message.from.display_name');
            $text = data_get($update, 'message.text');
            $this->line("• chat_id={$chatId}  tên={$name}  tin=".json_encode($text, JSON_UNESCAPED_UNICODE));

            // Ghi luôn vào zalo_chats như webhook, để không mất người đã nhắn lúc chưa có webhook.
            $webhook->handle(['result' => $update]);
            $count++;
        }

        $this->info($count ? "Xong, {$count} tin." : 'Không có tin nào đang chờ.');

        return self::SUCCESS;
    }
}
