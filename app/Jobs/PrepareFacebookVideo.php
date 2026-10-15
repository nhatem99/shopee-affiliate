<?php

namespace App\Jobs;

use App\Models\FacebookVideo;
use App\Services\FacebookVideoEditor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Tải video gốc từ Shopee (nếu $download) rồi dựng bản Reel — xem FacebookVideoEditor.
 * Xong thì video sang READY chờ admin xem; hỏng thì FAILED kèm lý do.
 *
 * Dựng video có thể quá retry_after của queue (90 giây): job bị nhận lại lần nữa trong lúc lần
 * đầu còn chạy. WithoutOverlapping + dontRelease bỏ luôn lần trùng đó; $tries > 1 để lần trùng
 * không bị tính là quá số lần thử rồi đánh hỏng video đang dựng ngon lành.
 */
class PrepareFacebookVideo implements ShouldQueue
{
    use Queueable;

    /** Video Shopee thường dưới 60 MB; quá mức này là link lạ. */
    private const MAX_BYTES = 200 * 1024 * 1024;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public int $videoId, public bool $download = false) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("fb-video:{$this->videoId}"))->dontRelease()->expireAfter($this->timeout)];
    }

    public function handle(FacebookVideoEditor $editor): void
    {
        $video = FacebookVideo::find($this->videoId);
        if (! $video || $video->status !== FacebookVideo::PROCESSING) {
            return;
        }

        try {
            if ($this->download) {
                $this->download($video);
            }
            if (! $video->has_source) {
                throw new RuntimeException('Chưa có video gốc.');
            }

            $sourceDuration = $editor->duration($video->sourcePath());
            $video->update(['source_duration' => $sourceDuration]);

            $outputDuration = $editor->render($video);

            $video->update([
                'status' => FacebookVideo::READY,
                'has_output' => true,
                'output_duration' => $outputDuration,
                'error' => null,
            ]);
        } catch (Throwable $e) {
            Log::warning('PrepareFacebookVideo: không dựng được video', ['video_id' => $video->id, 'error' => $e->getMessage()]);

            $video->update([
                // Đã có bản dựng trước (admin bấm dựng lại) thì vẫn xem/đăng được bản đó.
                'status' => $video->has_output ? FacebookVideo::READY : FacebookVideo::FAILED,
                'error' => mb_strimwidth($e->getMessage(), 0, 1000, '…'),
            ]);
        }
    }

    private function download(FacebookVideo $video): void
    {
        $path = $video->sourcePath();
        File::ensureDirectoryExists(dirname($path));
        $temp = $path.'.part';

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
                'Referer' => 'https://shopee.vn/',
            ])->connectTimeout(15)->timeout(300)->sink($temp)->get($video->source_url);

            if (! $response->successful()) {
                throw new RuntimeException("Tải video từ Shopee lỗi HTTP {$response->status()} — tải video lên tay.");
            }
            if (filesize($temp) > self::MAX_BYTES) {
                throw new RuntimeException('Video gốc lớn hơn 200 MB — tải bản nhỏ hơn lên tay.');
            }

            File::move($temp, $path);
            $video->update(['has_source' => true]);
        } finally {
            File::delete($temp);
        }
    }
}
