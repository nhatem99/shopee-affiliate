<?php

namespace App\Jobs;

use App\Services\ZaloMirrorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Đăng một bài đã gom xong lên nhóm đích. Mảnh bài được lưu trong payload của job — retry
 * sau lỗi gửi (bridge chập, mất mạng...) vẫn có đủ dữ liệu, không cần đọc lại từ cache.
 *
 * ZaloMirrorService::publish() xử lý toàn bộ logic: kiểm tra điều kiện, chuyển đổi link,
 * gửi, và ghi lịch sử vào cache cho trang admin.
 */
class PublishZaloMirrorPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    /**
     * @param  list<array>  $pieces  các mảnh (text, photo, link) đã gom
     */
    public function __construct(
        public readonly array $pieces,
        public readonly string $targetGroupId,
        public readonly string $sourceGroupId,
        public readonly string $senderId,
        public readonly string $senderName,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [15];
    }

    public function handle(ZaloMirrorService $mirror): void
    {
        $mirror->publish(
            $this->pieces,
            $this->targetGroupId,
            $this->sourceGroupId,
            $this->senderId,
            $this->senderName,
        );
    }

    public function failed(Throwable $e): void
    {
        Log::warning('PublishZaloMirrorPost: đăng bài thất bại hẳn', [
            'source' => $this->sourceGroupId,
            'target' => $this->targetGroupId,
            'sender' => $this->senderName,
            'error' => $e->getMessage(),
        ]);
    }
}
