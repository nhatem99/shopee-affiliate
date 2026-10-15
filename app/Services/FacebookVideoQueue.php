<?php

namespace App\Services;

use App\Jobs\PrepareFacebookVideo;
use App\Models\FacebookVideo;

/**
 * Việc "shopee_video" của bot đăng nhóm: mở trang sản phẩm Shopee bằng Chromium trên điện thoại
 * rồi gửi về link mp4 của video sản phẩm.
 *
 * Vì sao phải nhờ bot (đo 2026-10-15): mọi API chi tiết sản phẩm của Shopee (pdp/get_pc,
 * pdp/get_rw, item/get) trả 403 lỗi 90309999 khi gọi từ server, kể cả giả UA facebookexternalhit;
 * Chrome headless chưa đăng nhập cũng bị đẩy sang shopee.vn/verify/traffic. addlivetag không có
 * trường video nào. Chỉ trình duyệt thật đã đăng nhập Shopee mới đọc được.
 *
 * Bot chỉ báo link — server tự tải file (CDN video của Shopee không chặn), khỏi đẩy vài chục MB
 * qua mạng điện thoại.
 */
class FacebookVideoQueue
{
    /** Bot từ bản này mới làm việc "shopee_video". */
    public const MIN_VERSION = '1.8.0';

    /** Kết quả bot được phép báo về. */
    public const RESULTS = ['found', 'no_video', 'login_required', 'blocked', 'failed'];

    /** Bot nhận việc mà quá chừng này không báo — coi như hỏng để admin tải tay hoặc thử lại. */
    private const CLAIM_TIMEOUT_MINUTES = 10;

    /** Host video bot được phép gửi về — server sẽ tải link này, không nhận link tới chỗ khác. */
    private const VIDEO_HOSTS = '/(^|\.)(susercontent\.com|shopee\.vn|shopee\.com|shopeemobile\.com)$/i';

    public static function runnerSupports(?string $version): bool
    {
        return FacebookGroupPostScheduler::runnerAtLeast($version, self::MIN_VERSION);
    }

    /** Video đang chờ bot cũ nhất — giao cả lúc bot tạm dừng đăng deal vì việc này không đụng Facebook. */
    public function nextJob(?string $runnerVersion): ?array
    {
        if (! self::runnerSupports($runnerVersion)) {
            return null;
        }

        $this->failSilentClaims();

        $video = FacebookVideo::where('status', FacebookVideo::WAITING_BOT)->orderBy('id')->first();
        if (! $video) {
            return null;
        }

        // Hai lượt hỏi cùng lúc thì chỉ một lượt nhận được.
        $claimed = FacebookVideo::whereKey($video->id)
            ->where('status', FacebookVideo::WAITING_BOT)
            ->update(['status' => FacebookVideo::FETCHING, 'claimed_at' => now(), 'error' => null]);
        if (! $claimed) {
            return null;
        }

        return [
            'type' => 'shopee_video',
            'video_id' => $video->id,
            'url' => $video->shopee_url,
        ];
    }

    /**
     * @param  list<array{url: string, width?: ?int, height?: ?int, duration?: ?float}>  $videos
     * @return bool false nếu video này không còn chờ kết quả bot
     */
    public function report(FacebookVideo $video, string $status, array $videos, ?string $error): bool
    {
        if ($video->status !== FacebookVideo::FETCHING) {
            return false;
        }

        if ($status === 'found') {
            $best = $this->pick($videos);
            if (! $best) {
                $video->update([
                    'status' => FacebookVideo::FAILED,
                    'error' => 'Bot gửi về link video không phải của Shopee: '.mb_strimwidth(implode(', ', array_column($videos, 'url')), 0, 300, '…'),
                ]);

                return true;
            }

            $video->update([
                'status' => FacebookVideo::PROCESSING,
                'source_url' => $best['url'],
                'source_duration' => $best['duration'] ?? null,
                'error' => null,
            ]);
            PrepareFacebookVideo::dispatch($video->id, download: true);

            return true;
        }

        $video->update(match ($status) {
            'no_video' => ['status' => FacebookVideo::NO_VIDEO, 'error' => $error],
            'login_required' => ['status' => FacebookVideo::FAILED, 'error' => 'Chromium trên điện thoại chưa đăng nhập Shopee — mở shopee.vn đăng nhập một lần rồi bấm "Lấy lại".'],
            'blocked' => ['status' => FacebookVideo::FAILED, 'error' => 'Shopee chặn trình duyệt trên điện thoại (bắt xác minh)'.($error ? ": {$error}" : '').' — mở shopee.vn trên Chromium làm xác minh rồi bấm "Lấy lại", hoặc tải video lên tay.'],
            default => ['status' => FacebookVideo::FAILED, 'error' => 'Bot không lấy được video: '.($error ?: 'không rõ lý do')],
        });

        return true;
    }

    /**
     * Bản rõ nhất trong các bản bot gửi (Shopee để sẵn nhiều độ phân giải cho cùng một video),
     * chỉ trong số link của Shopee.
     *
     * @param  list<array{url: string, width?: ?int, height?: ?int, duration?: ?float}>  $videos
     */
    private function pick(array $videos): ?array
    {
        $allowed = array_filter($videos, function (array $video) {
            $parts = parse_url($video['url']);

            return ($parts['scheme'] ?? null) === 'https' && preg_match(self::VIDEO_HOSTS, $parts['host'] ?? '') === 1;
        });

        usort($allowed, fn (array $a, array $b) => ($b['width'] ?? 0) * ($b['height'] ?? 0) <=> ($a['width'] ?? 0) * ($a['height'] ?? 0));

        return $allowed[0] ?? null;
    }

    public function failSilentClaims(): void
    {
        FacebookVideo::where('status', FacebookVideo::FETCHING)
            ->where('claimed_at', '<', now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES))
            ->update([
                'status' => FacebookVideo::FAILED,
                'error' => 'Bot nhận việc nhưng không báo kết quả — bấm "Lấy lại" hoặc tải video lên tay.',
            ]);
    }
}
