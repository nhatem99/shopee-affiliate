<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Chờ hết "thời gian gom" (buffer_seconds, mặc định 20 giây) rồi kiểm tra xem mình có phải
 * job cuối cùng của đợt không — tức là seq của mình bằng seq hiện tại trong cache.
 *
 * Lý do cần debounce: zca-js gửi ảnh và text thành các tin riêng, cách nhau vài trăm ms.
 * Mỗi tin bấy nhiêu mảnh thì bấy nhiêu job được xếp, nhưng chỉ job CUỐI mới đủ mảnh để
 * dựng bài hoàn chỉnh. Job cũ hơn (seq nhỏ hơn) tự thoát khi thấy seq đã bị vượt qua.
 *
 * Nếu đây là job cuối: lấy toàn bộ mảnh trong cache buffer rồi xếp PublishZaloMirrorPost
 * (job riêng để mảnh được lưu trong payload, retry sau lỗi gửi không mất dữ liệu).
 */
class AssembleZaloMirrorPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $threadId,
        public readonly string $senderId,
        public readonly int $seq,
        public readonly string $targetGroupId,
        public readonly string $sourceGroupId,
        public readonly string $senderName,
    ) {}

    public function handle(): void
    {
        $seqKey = "zalo_mirror:seq:{$this->threadId}:{$this->senderId}";
        $currentSeq = (int) Cache::get($seqKey, 0);

        // Job này không còn là job cuối — một mảnh mới hơn đã tới và sẽ xử lý toàn bộ.
        if ($this->seq < $currentSeq) {
            Log::debug('AssembleZaloMirrorPost: bỏ qua (seq cũ)', [
                'seq' => $this->seq,
                'current' => $currentSeq,
                'thread' => $this->threadId,
                'sender' => $this->senderId,
            ]);

            return;
        }

        // KHÔNG xoá seqKey: đặt lại về 0 thì một job cũ còn kẹt trong hàng đợi (seq 2) sẽ khớp
        // với seq 2 của đợt sau và lấy buffer trước khi đợt đó gom xong. Để seq tăng tiếp, khoá
        // tự hết hạn sau 15 phút không có tin.
        $bufferKey = "zalo_mirror:buf:{$this->threadId}:{$this->senderId}";
        $raw = Cache::pull($bufferKey); // lấy và xoá buffer trong một bước

        $pieces = is_string($raw) ? (json_decode($raw, true) ?: []) : [];

        if (empty($pieces)) {
            return;
        }

        $batches = $this->splitByGap($pieces);

        Log::info('AssembleZaloMirrorPost: gom xong, xếp PublishZaloMirrorPost', [
            'pieces' => count($pieces),
            'posts' => count($batches),
            'thread' => $this->threadId,
            'sender' => $this->senderId,
        ]);

        foreach ($batches as $batch) {
            PublishZaloMirrorPost::dispatch(
                $batch,
                $this->targetGroupId,
                $this->sourceGroupId,
                $this->senderId,
                $this->senderName,
            );
        }
    }

    /**
     * Cắt buffer thành từng bài theo giờ gửi: hai mảnh cách nhau quá buffer_seconds là hai bài
     * khác nhau. Debounce theo seq chỉ đúng khi worker chạy kịp — worker bận (job lấy mã mất
     * 20–45 giây) hoặc cầu nối phát lại cả loạt tin lúc nối lại thì buffer chứa mấy bài liền
     * nhau, không tách ra là thành một bài khổng lồ.
     *
     * @param  list<array<string, mixed>>  $pieces
     * @return list<list<array<string, mixed>>>
     */
    private function splitByGap(array $pieces): array
    {
        usort($pieces, fn ($a, $b) => ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0));
        $gapMs = (int) config('services.zalo_personal.mirror.buffer_seconds', 20) * 1000;

        $batches = [];
        $lastTs = null;
        foreach ($pieces as $piece) {
            $ts = (int) ($piece['ts'] ?? 0);
            if ($lastTs === null || $ts - $lastTs > $gapMs) {
                $batches[] = [];
            }
            $batches[array_key_last($batches)][] = $piece;
            $lastTs = $ts;
        }

        return $batches;
    }
}
