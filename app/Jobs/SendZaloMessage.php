<?php

namespace App\Jobs;

use App\Services\ZaloBotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gửi một tin Zalo Bot qua queue, để request của khách (rút tiền, webhook...) không phải
 * chờ API Zalo và không hỏng theo khi Zalo chậm/lỗi.
 */
class SendZaloMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $chatId,
        public readonly string $text,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ZaloBotService $bot): void
    {
        $bot->sendMessage($this->chatId, $this->text);
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Zalo Bot: gửi tin thất bại', [
            'chat_id' => $this->chatId,
            'error' => $e->getMessage(),
        ]);
    }
}
