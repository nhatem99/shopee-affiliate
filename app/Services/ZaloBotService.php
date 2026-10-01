<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Gọi HTTP API của Zalo Bot (https://bot.zaloplatforms.com/docs). Mọi method đều là
 * POST https://bot-api.zaloplatforms.com/bot{TOKEN}/{method}, trả về {ok, result} hoặc
 * {ok: false, error_code, description}.
 *
 * Token nằm ngay trong URL nên mọi thông báo lỗi đều đi qua scrub() trước khi ném ra —
 * lỗi cURL có kèm nguyên URL, để lọt vào log là lộ quyền điều khiển bot.
 */
class ZaloBotService
{
    // Giới hạn của sendMessage theo tài liệu: 1–2000 ký tự.
    public const MAX_TEXT_LENGTH = 2000;

    public function __construct(private ZaloBotSettings $settings) {}

    public function isConfigured(): bool
    {
        return $this->settings->token() !== null;
    }

    public function getMe(): array
    {
        return $this->call('getMe');
    }

    /**
     * Gọi getMe bằng một token CHƯA lưu, để trang admin báo sai token ngay lúc nhập thay vì
     * lưu xong mới phát hiện.
     */
    public function getMeWithToken(string $token): array
    {
        return $this->call('getMe', [], 15, trim($token));
    }

    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo');
    }

    public function setWebhook(string $url, string $secret): array
    {
        return $this->call('setWebhook', ['url' => $url, 'secret_token' => $secret]);
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook');
    }

    /**
     * Lấy tin chờ khi CHƯA đặt webhook. Zalo không trả danh sách: mỗi lần gọi là một tin
     * (hoặc lỗi 408 khi hết thời gian chờ mà không có tin nào).
     */
    public function getUpdates(int $timeoutSeconds = 10): ?array
    {
        try {
            return $this->call('getUpdates', ['timeout' => (string) $timeoutSeconds], $timeoutSeconds + 5);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), '[408]')) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Gửi tin văn bản. Tin dài hơn giới hạn được cắt thành nhiều tin theo dòng.
     */
    public function sendMessage(string $chatId, string $text): void
    {
        foreach ($this->chunk($text) as $part) {
            $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $part]);
        }
    }

    /**
     * @return list<string>
     */
    private function chunk(string $text): array
    {
        if (mb_strlen($text) <= self::MAX_TEXT_LENGTH) {
            return [$text];
        }

        $parts = [];
        $current = '';
        foreach (explode("\n", $text) as $line) {
            // Một dòng tự nó đã quá dài thì cắt cứng.
            while (mb_strlen($line) > self::MAX_TEXT_LENGTH) {
                if ($current !== '') {
                    $parts[] = $current;
                    $current = '';
                }
                $parts[] = mb_substr($line, 0, self::MAX_TEXT_LENGTH);
                $line = mb_substr($line, self::MAX_TEXT_LENGTH);
            }

            $candidate = $current === '' ? $line : $current."\n".$line;
            if (mb_strlen($candidate) > self::MAX_TEXT_LENGTH) {
                $parts[] = $current;
                $current = $line;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    private function call(string $method, array $payload = [], int $timeout = 15, ?string $token = null): array
    {
        $token ??= (string) $this->settings->token();
        if ($token === '') {
            throw new RuntimeException('Chưa cấu hình token Zalo Bot (/admin/zalo-bot).');
        }

        $url = rtrim((string) config('services.zalo_bot.api_base'), '/')."/bot{$token}/{$method}";

        try {
            $response = Http::timeout($timeout)->acceptJson()->asJson()->post($url, (object) $payload);
        } catch (Throwable $e) {
            throw new RuntimeException("Zalo Bot {$method}: ".$this->scrub($e->getMessage(), $token), 0);
        }

        $body = $response->json();
        if (! is_array($body) || ($body['ok'] ?? false) !== true) {
            $code = is_array($body) ? ($body['error_code'] ?? $response->status()) : $response->status();
            $description = is_array($body) ? ($body['description'] ?? 'không rõ') : 'phản hồi không phải JSON';

            throw new RuntimeException("Zalo Bot {$method} lỗi [{$code}]: ".$this->scrub((string) $description, $token));
        }

        return is_array($body['result'] ?? null) ? $body['result'] : [];
    }

    private function scrub(string $message, string $token): string
    {
        return $token === '' ? $message : str_replace($token, '***', $message);
    }
}
