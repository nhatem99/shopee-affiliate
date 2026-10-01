<?php

namespace App\Console\Commands;

use App\Services\ZaloBotService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Đăng ký (hoặc gỡ) webhook của Zalo Bot. Chạy trên prod sau khi deploy.
 *
 * Đặt webhook xong thì getUpdates (zalo:bot --updates) không chạy được nữa — muốn lấy tin ở
 * máy local thì dùng một bot dev khác, hoặc gỡ webhook bằng --delete.
 */
class ZaloSetWebhook extends Command
{
    protected $signature = 'zalo:set-webhook
        {--url= : Địa chỉ webhook, mặc định APP_URL + /webhooks/zalo}
        {--delete : Gỡ webhook thay vì đặt}';

    protected $description = 'Đăng ký webhook Zalo Bot với Zalo';

    public function handle(ZaloBotService $bot): int
    {
        if (! $bot->isConfigured()) {
            $this->error('Chưa cấu hình ZALO_BOT_TOKEN trong .env.');

            return self::FAILURE;
        }

        try {
            if ($this->option('delete')) {
                $bot->deleteWebhook();
                $this->info('Đã gỡ webhook.');

                return self::SUCCESS;
            }

            $secret = (string) config('services.zalo_bot.webhook_secret');
            $length = strlen($secret);
            if ($length < 8 || $length > 256) {
                $this->error('ZALO_BOT_WEBHOOK_SECRET phải dài 8–256 ký tự.');

                return self::FAILURE;
            }

            $url = $this->option('url') ?: route('zalo.webhook');
            if (! str_starts_with($url, 'https://')) {
                $this->error("Zalo chỉ nhận webhook HTTPS, đang là: {$url}");

                return self::FAILURE;
            }

            $result = $bot->setWebhook($url, $secret);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Đã đặt webhook: {$url}");
        $verification = $result['verification'] ?? null;
        if (is_array($verification)) {
            $line = 'Zalo gọi thử: '.($verification['outcome'] ?? '?').' — '.($verification['hint'] ?? '');
            ($verification['ok'] ?? false) ? $this->info($line) : $this->warn($line);
        }

        return self::SUCCESS;
    }
}
