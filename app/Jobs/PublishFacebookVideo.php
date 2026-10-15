<?php

namespace App\Jobs;

use App\Models\ApiConfig;
use App\Models\FacebookVideo;
use App\Services\FacebookPageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Đăng output.mp4 của một video thành Reel trên fanpage (cấu hình Facebook ở /admin/api-config).
 *
 * Như PrepareFacebookVideo: tải lên có thể quá retry_after của queue, lần nhận trùng bị
 * WithoutOverlapping bỏ đi — đăng trùng hai Reel còn tệ hơn đăng hỏng.
 */
class PublishFacebookVideo implements ShouldQueue
{
    use Queueable;

    /** Chờ Facebook xử lý xong tối đa ngần này lần × STATUS_DELAY giây; quá thì coi như đã đăng. */
    private const STATUS_CHECKS = 12;

    private const STATUS_DELAY = 10;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public int $videoId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("fb-video:{$this->videoId}"))->dontRelease()->expireAfter($this->timeout)];
    }

    public function handle(): void
    {
        $video = FacebookVideo::find($this->videoId);
        if (! $video || $video->status !== FacebookVideo::PUBLISHING) {
            return;
        }

        try {
            $config = ApiConfig::where('platform', 'facebook')->where('is_active', true)->first();
            if (! $config || ! $config->app_id || ! $config->app_secret) {
                $this->backToReady($video, 'Chưa có cấu hình Facebook đang bật (Page ID + Page Access Token) ở /admin/api-config.');

                return;
            }
            if (! $video->has_output || ! is_file($video->outputPath())) {
                $this->backToReady($video, 'Chưa có video đã dựng.');

                return;
            }

            $page = $this->page($config);
            $reelId = $page->publishReel($video->outputPath(), (string) $video->caption);
            if (! $reelId) {
                $this->backToReady($video, 'Facebook từ chối đăng Reel: '.$this->explain((string) $page->lastError));

                return;
            }

            // Đã lên Facebook — ghi lại ngay, lỡ bước chờ xử lý phía dưới hỏng thì vẫn biết reel nào.
            $video->update(['fb_video_id' => $reelId, 'permalink' => "https://www.facebook.com/reel/{$reelId}"]);

            $status = null;
            for ($i = 0; $i < self::STATUS_CHECKS; $i++) {
                $status = $page->reelStatus($reelId);
                if (in_array($status['state'] ?? null, ['published', 'error'], true)) {
                    break;
                }
                $this->pause();
            }

            if (($status['state'] ?? null) === 'error') {
                $this->backToReady($video, 'Facebook nhận file nhưng xử lý video hỏng: '.$status['error']);

                return;
            }

            $video->update([
                'status' => FacebookVideo::POSTED,
                'posted_at' => now(),
                // Chưa thấy "published" sau ~2 phút: thường Facebook vẫn đang xử lý, reel tự lên sau.
                'error' => ($status['state'] ?? null) === 'published' ? null : 'Facebook còn đang xử lý video — vài phút nữa mở link reel kiểm tra.',
                'has_source' => false,
                'has_output' => false,
            ]);
            // Facebook đã giữ bản đăng — bỏ file trên VPS, mỗi video vài chục MB.
            Storage::disk('local')->deleteDirectory($video->relativePath());
        } catch (Throwable $e) {
            Log::warning('PublishFacebookVideo: lỗi', ['video_id' => $video->id, 'error' => $e->getMessage()]);
            $this->backToReady($video, $e->getMessage());
        }
    }

    protected function page(ApiConfig $config): FacebookPageService
    {
        return new FacebookPageService($config->app_id, $config->app_secret);
    }

    protected function pause(): void
    {
        sleep(self::STATUS_DELAY);
    }

    /** Về READY để admin bấm đăng lại sau khi sửa token. */
    private function backToReady(FacebookVideo $video, string $error): void
    {
        $video->update(['status' => FacebookVideo::READY, 'error' => mb_strimwidth($error, 0, 1000, '…')]);
    }

    /** Lỗi thiếu quyền gặp nhiều nhất — nói thẳng phải làm gì thay vì để nguyên JSON của Graph. */
    private function explain(string $body): string
    {
        $message = json_decode($body, true)['error']['message'] ?? $body;
        $code = json_decode($body, true)['error']['code'] ?? null;

        if ($code === 200 || $code === 10 || str_contains((string) $message, 'permission')) {
            return "token thiếu quyền pages_manage_posts — cấp lại Page Access Token có quyền này ở /admin/api-config. ({$message})";
        }

        return Str::limit((string) $message, 600);
    }
}
