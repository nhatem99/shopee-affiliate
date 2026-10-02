<?php

namespace App\Jobs;

use App\Services\ZaloGroupLinkReplyService;
use App\Services\ZaloPersonalBridge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lấy link có mã cho một link Shopee khách dán vào Zalo rồi trả lời ngay dưới tin đó. Chạy qua
 * queue vì lấy mã mất 20–45 giây (ganma) — chạy thẳng trong `zalo:group-listen` thì mọi tin
 * đến sau phải xếp hàng chờ.
 */
class ReplyZaloGroupLink implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>|null  $quote  trường `quote` của tin đến, để trả lời trích đúng tin
     */
    public function __construct(
        public readonly string $threadId,
        public readonly string $threadType,
        public readonly string $url,
        public readonly ?array $quote = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10];
    }

    public function handle(ZaloGroupLinkReplyService $replies, ZaloPersonalBridge $bridge): void
    {
        $reply = $replies->replyFor($this->url);

        $bridge->sendText($this->threadId, $this->threadType, $reply['text'], $this->quote, $reply['styles']);
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Zalo nick cá nhân: trả link thất bại', [
            'thread_id' => $this->threadId,
            'url' => $this->url,
            'error' => $e->getMessage(),
        ]);
    }
}
