<?php

namespace App\Services;

use App\Models\FacebookVideo;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Dựng video Reel từ video sản phẩm Shopee bằng ffmpeg:
 *  • cắt bỏ vài giây đầu/cuối (trim_start/trim_end);
 *  • đặt video vào khung 9:16 1080×1920 (Reels yêu cầu 9:16), nền là chính video phóng to làm mờ —
 *    video vuông/ngang của Shopee không bị viền đen;
 *  • chèn chữ của mình (tên sản phẩm phía trên, giá phía dưới).
 *
 * Cắt đầu/cuối chỉ làm file khác đi — Facebook nhận ra video trùng bằng hình/tiếng chứ không bằng
 * file. Phần khung + chữ mới là thứ làm video thành "của mình".
 *
 * Thông số theo yêu cầu Reels của Graph API: H.264, 30 fps cố định, yuv420p, AAC 48 kHz, 3–90 giây.
 *
 * ffmpeg phải có bộ lọc drawtext (libfreetype) — gói ffmpeg của Ubuntu có sẵn; bản Homebrew thì
 * không, máy Mac dùng ffmpeg-full và đặt FFMPEG_BINARY.
 */
class FacebookVideoEditor
{
    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    public const MIN_SECONDS = 3;

    public const MAX_SECONDS = 90;

    /** Số ký tự mỗi dòng chữ — cỡ chữ 56 trên khung rộng 1080. */
    private const LINE_CHARS = 26;

    private const FONT_CANDIDATES = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
    ];

    /** Lý do chưa dựng được video trên máy này (thiếu ffmpeg/font), null là ổn. */
    public function problem(): ?string
    {
        foreach (['ffmpeg', 'ffprobe'] as $tool) {
            $result = Process::run([$this->binary($tool), '-version']);
            if (! $result->successful()) {
                return "Máy chủ chưa cài {$tool} — chạy: sudo apt install ffmpeg";
            }
        }

        $filters = Process::run([$this->binary('ffmpeg'), '-hide_banner', '-filters']);
        if (! str_contains($filters->output(), 'drawtext')) {
            return 'ffmpeg trên máy chủ thiếu bộ lọc drawtext (chèn chữ) — cài bản ffmpeg của apt.';
        }

        if ($this->font() === null) {
            return 'Không tìm thấy font chữ — chạy: sudo apt install fonts-dejavu-core (hoặc đặt FB_VIDEO_FONT).';
        }

        return null;
    }

    /** Độ dài video (giây); ném lỗi nếu file không có hình. */
    public function duration(string $path): float
    {
        $result = Process::timeout(60)->run([
            $this->binary('ffprobe'), '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=codec_type:format=duration',
            '-of', 'json',
            $path,
        ]);

        $data = json_decode($result->output(), true);
        if (! $result->successful() || empty($data['streams'])) {
            throw new RuntimeException('File không phải video đọc được: '.mb_strimwidth(trim($result->errorOutput()), 0, 300, '…'));
        }

        return (float) ($data['format']['duration'] ?? 0);
    }

    /**
     * Dựng output.mp4 từ source.mp4 của video này. Trả độ dài video đã dựng (giây).
     */
    public function render(FacebookVideo $video): float
    {
        if ($problem = $this->problem()) {
            throw new RuntimeException($problem);
        }

        $source = $video->sourcePath();
        $total = $this->duration($source);
        $start = max(0.0, (float) $video->trim_start);
        $length = min(self::MAX_SECONDS, $total - $start - max(0.0, (float) $video->trim_end));

        if ($length < self::MIN_SECONDS) {
            throw new RuntimeException(sprintf(
                'Video gốc dài %.1f giây, cắt %.1f giây đầu và %.1f giây cuối thì còn chưa tới %d giây — giảm số giây cắt.',
                $total, $start, $video->trim_end, self::MIN_SECONDS,
            ));
        }

        $workDir = dirname($source).'/work';
        File::ensureDirectoryExists($workDir);
        $output = $video->outputPath();
        $temp = $workDir.'/output.mp4';

        try {
            $result = Process::timeout(600)->run([
                $this->binary('ffmpeg'), '-hide_banner', '-y',
                '-ss', $this->seconds($start),
                '-i', $source,
                '-t', $this->seconds($length),
                '-filter_complex', $this->filterGraph($video, $workDir),
                '-map', '[out]', '-map', '0:a?',
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '22', '-pix_fmt', 'yuv420p',
                '-g', '60', '-keyint_min', '60', '-sc_threshold', '0',
                '-c:a', 'aac', '-b:a', '128k', '-ar', '48000', '-ac', '2',
                '-movflags', '+faststart',
                // VPS còn chạy web — không để ffmpeg ăn hết CPU.
                '-threads', '2',
                $temp,
            ]);

            if (! $result->successful()) {
                throw new RuntimeException('ffmpeg lỗi: '.mb_strimwidth(trim($this->lastLines($result->errorOutput())), 0, 600, '…'));
            }

            // Ghi ra file tạm rồi mới đổi tên: dựng hỏng giữa chừng thì bản cũ vẫn còn xem được.
            File::move($temp, $output);
        } finally {
            File::deleteDirectory($workDir);
        }

        return $this->duration($output);
    }

    /**
     * Nền mờ (thu nhỏ trước khi làm mờ cho nhẹ CPU) + video đặt giữa + từng dòng chữ.
     * Chữ đi qua textfile để khỏi phải escape dấu ' : , của tên sản phẩm trong filtergraph.
     */
    public function filterGraph(FacebookVideo $video, string $workDir): string
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;

        $chains = [
            '[0:v]split=2[bgsrc][fgsrc]',
            "[bgsrc]scale=270:480:force_original_aspect_ratio=increase,crop=270:480,boxblur=12:2,scale={$w}:{$h},setsar=1[bg]",
            "[fgsrc]scale={$w}:{$h}:force_original_aspect_ratio=decrease,setsar=1[fg]",
            '[bg][fg]overlay=(W-w)/2:(H-h)/2,fps=30,format=yuv420p[base]',
        ];

        $lines = [];
        // Trên: tên sản phẩm, ngay dưới vùng tiêu đề "Reels" của Facebook (~14% chiều cao).
        foreach ($this->wrap((string) $video->overlay_top, 3) as $i => $text) {
            $lines[] = [$text, 56, 290 + $i * 80];
        }
        // Dưới: giá — trên vùng caption/nút của Facebook (khoảng 35% dưới cùng bị che).
        foreach ($this->wrap((string) $video->overlay_bottom, 2) as $i => $text) {
            $lines[] = [$text, 64, 1180 + $i * 90];
        }

        if (! $lines) {
            $chains[] = '[base]null[out]';

            return implode(';', $chains);
        }

        $font = $this->quote($this->font());
        $label = 'base';
        foreach ($lines as $i => [$text, $size, $y]) {
            $file = "{$workDir}/line-{$i}.txt";
            File::put($file, $text);
            $next = $i === count($lines) - 1 ? 'out' : "t{$i}";
            $chains[] = "[{$label}]drawtext=fontfile={$font}:textfile={$this->quote($file)}:expansion=none"
                .":fontsize={$size}:fontcolor=white:box=1:boxcolor=black@0.55:boxborderw=16"
                .":x=(w-text_w)/2:y={$y}[{$next}]";
            $label = $next;
        }

        return implode(';', $chains);
    }

    /**
     * Ngắt chữ thành dòng theo số ký tự, tối đa $maxLines dòng (dòng cuối thêm … nếu còn thừa).
     *
     * @return list<string>
     */
    public function wrap(string $text, int $maxLines): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : "{$current} {$word}";
            if ($current !== '' && mb_strlen($candidate) > self::LINE_CHARS) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = mb_strimwidth($lines[$maxLines - 1], 0, self::LINE_CHARS - 1, '').'…';
        }

        return array_map(fn (string $line) => mb_strimwidth($line, 0, self::LINE_CHARS + 6, '…'), $lines);
    }

    public function font(): ?string
    {
        $configured = config('services.fb_video.font');
        foreach (array_filter([$configured, ...self::FONT_CANDIDATES]) as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function binary(string $tool): string
    {
        return (string) config("services.fb_video.{$tool}", $tool);
    }

    /** Giá trị trong filtergraph: bọc nháy đơn, nháy đơn bên trong thì đóng-escape-mở. */
    private function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    private function seconds(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /** ffmpeg in cả banner lẫn tiến độ ra stderr — lỗi thật nằm ở mấy dòng cuối. */
    private function lastLines(string $text, int $count = 6): string
    {
        return implode("\n", array_slice(preg_split('/\R/', trim($text)), -$count));
    }
}
