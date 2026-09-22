<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Video hướng dẫn lấy mã hiện ở trang công khai /huong-dan.
 *
 * Admin chọn MỘT trong hai nguồn, không phải cả hai cùng lúc — đặt cái này là cái kia bị xoá:
 *  • Dán link YouTube / TikTok / Facebook → nhúng iframe, không tốn băng thông VPS.
 *  • Tải file MP4 lên → nằm trong public/uploads, phát bằng thẻ <video>, không phụ thuộc bên thứ ba.
 *
 * Vì sao không giữ cả hai rồi cho admin chọn "nguồn đang dùng": thành ba thứ phải đồng bộ (url,
 * path, cờ chọn) cho một trang chỉ phát đúng một video, và kiểu gì cũng có lúc cờ trỏ vào nguồn
 * đã bị xoá. Một nguồn sống tại một thời điểm thì không có trạng thái nào để lệch.
 *
 * Link phải qua được parse() mới lưu — trang khách không bao giờ nhận một URL chưa nhận dạng
 * được: nhúng bừa vào iframe thì khách chỉ thấy khung trắng, mà admin thì tưởng đã xong.
 */
class GuideVideoService
{
    /** Link nhúng (YouTube/TikTok/Facebook) hoặc URL file video đặt ở nơi khác. */
    public const URL_KEY = 'guide_video_url';

    /** Đường dẫn tương đối trên disk 'uploads' khi admin tự tải file lên. */
    public const PATH_KEY = 'guide_video_path';

    /** Trần của ứng dụng. Trần THẬT còn phụ thuộc php.ini — xem maxUploadMb(). */
    public const MAX_FILE_KB = 102400; // 100 MB

    /**
     * Video đang bật cho trang khách, hoặc null khi admin chưa đặt gì.
     *
     * @return array{kind: string, src: string, provider: string, orientation: string}|null
     */
    public function current(): ?array
    {
        $path = (string) Setting::get(self::PATH_KEY, '');

        // Kiểm tra file còn thật hay không: deploy bằng cách copy mã nguồn (không mang theo
        // public/uploads) là mất file mà setting vẫn còn, trang khách sẽ hiện một trình phát
        // câm không báo lỗi gì. Không thấy file thì coi như chưa đặt, rơi xuống link bên dưới.
        if ($path !== '' && Storage::disk('uploads')->exists($path)) {
            return [
                'kind' => 'file',
                'src' => Storage::disk('uploads')->url($path),
                'provider' => 'upload',
                // File tự tải lên thì không đoán được dọc hay ngang — để thẻ <video> tự co theo
                // kích thước thật của video.
                'orientation' => 'auto',
            ];
        }

        $url = (string) Setting::get(self::URL_KEY, '');

        return $url === '' ? null : $this->parse($url);
    }

    /** Trạng thái cho trang /admin/settings — gồm cả giá trị thô để đổ vào ô nhập. */
    public function adminState(): array
    {
        $path = (string) Setting::get(self::PATH_KEY, '');
        $hasFile = $path !== '' && Storage::disk('uploads')->exists($path);

        return [
            'url' => (string) Setting::get(self::URL_KEY, ''),
            'fileName' => $hasFile ? basename($path) : null,
            'video' => $this->current(),
            'maxUploadMb' => $this->maxUploadMb(),
        ];
    }

    /**
     * Nhận dạng link thành thứ nhúng được. Trả null khi không nhận ra — gọi ở cả lúc validate
     * (chặn link hỏng ngay từ đầu) lẫn lúc dựng trang khách.
     *
     * @return array{kind: string, src: string, provider: string, orientation: string}|null
     */
    public function parse(string $url): ?array
    {
        $url = trim($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return null;
        }

        $host = preg_replace('/^(www|m)\./', '', $host);

        if (in_array($host, ['youtube.com', 'youtu.be', 'youtube-nocookie.com'], true)) {
            return $this->youtube($url, $host);
        }

        if ($host === 'tiktok.com') {
            return $this->tiktok($url);
        }

        if ($host === 'facebook.com' || $host === 'fb.watch') {
            return [
                'kind' => 'embed',
                'src' => 'https://www.facebook.com/plugins/video.php?'.http_build_query([
                    'href' => $url,
                    'show_text' => 'false',
                ]),
                'provider' => 'facebook',
                // Reel Facebook luôn là khung dọc; video thường thì ngang.
                'orientation' => str_contains(strtolower($url), '/reel/') ? 'portrait' : 'landscape',
            ];
        }

        // Link trỏ thẳng tới một file video đặt ở CDN/hosting khác — phát bằng <video> như file
        // tự tải lên, chỉ khác chỗ chứa.
        if (preg_match('/\.(mp4|webm|ogv|m4v)(\?|#|$)/i', $url) && str_starts_with($url, 'https://')) {
            return [
                'kind' => 'file',
                'src' => $url,
                'provider' => 'external',
                'orientation' => 'auto',
            ];
        }

        return null;
    }

    /**
     * @return array{kind: string, src: string, provider: string, orientation: string}|null
     */
    private function youtube(string $url, string $host): ?array
    {
        $id = null;
        $portrait = false;

        if ($host === 'youtu.be') {
            $id = trim((string) parse_url($url, PHP_URL_PATH), '/');
        } elseif (preg_match('#/(shorts|embed|live|v)/([A-Za-z0-9_-]{11})#', (string) parse_url($url, PHP_URL_PATH), $m)) {
            $id = $m[2];
            $portrait = $m[1] === 'shorts';
        } else {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $id = $query['v'] ?? null;
        }

        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            return null;
        }

        return [
            'kind' => 'embed',
            // nocookie: khách chỉ xem một video hướng dẫn, không có lý do gì để YouTube gắn
            // cookie theo dõi họ trên tên miền của mình.
            // rel=0 để hết video không hiện loạt video gợi ý của kênh khác.
            'src' => "https://www.youtube-nocookie.com/embed/{$id}?rel=0",
            'provider' => 'youtube',
            'orientation' => $portrait ? 'portrait' : 'landscape',
        ];
    }

    /**
     * @return array{kind: string, src: string, provider: string, orientation: string}|null
     */
    private function tiktok(string $url): ?array
    {
        // Chỉ link đầy đủ mới có id trong đường dẫn. Link rút gọn (vt./vm.tiktok.com) phải gọi
        // mạng mới biết id — không làm ở đây, thông báo lỗi bảo admin mở ra lấy link đầy đủ.
        if (! preg_match('#/video/(\d+)#', (string) parse_url($url, PHP_URL_PATH), $m)) {
            return null;
        }

        return [
            'kind' => 'embed',
            'src' => "https://www.tiktok.com/embed/v2/{$m[1]}",
            'provider' => 'tiktok',
            'orientation' => 'portrait',
        ];
    }

    /**
     * Lưu file admin tải lên, xoá file cũ và link cũ (một nguồn sống tại một thời điểm).
     *
     * store() tự đặt tên ngẫu nhiên — cùng lý do với ảnh chat: tên file do người dùng đặt không
     * bao giờ được thành tên file trên server.
     */
    public function storeFile(UploadedFile $file): void
    {
        $this->deleteFile();

        Setting::set(self::PATH_KEY, $file->store('guide', 'uploads'));
        Setting::set(self::URL_KEY, '');
    }

    /** Lưu link nhúng, xoá file cũ để không bỏ lại file mồ côi chiếm ổ đĩa. */
    public function saveUrl(string $url): void
    {
        $this->deleteFile();

        Setting::set(self::URL_KEY, trim($url));
    }

    /** Gỡ hẳn video — trang /huong-dan quay về chỉ còn phần hướng dẫn bằng chữ. */
    public function clear(): void
    {
        $this->deleteFile();

        Setting::set(self::URL_KEY, '');
    }

    private function deleteFile(): void
    {
        $path = (string) Setting::get(self::PATH_KEY, '');

        if ($path !== '') {
            Storage::disk('uploads')->delete($path);
        }

        Setting::set(self::PATH_KEY, '');
    }

    /**
     * Trần tải lên THẬT: nhỏ nhất trong upload_max_filesize, post_max_size và trần của ứng dụng.
     *
     * Hiện thẳng con số này ở trang cài đặt vì file vượt post_max_size không rơi vào validate mà
     * chết từ tầng PHP (request rỗng) — admin chỉ thấy "lỗi" chung chung rồi thử lại mãi.
     */
    public function maxUploadMb(): int
    {
        $limits = array_filter([
            $this->iniBytes((string) ini_get('upload_max_filesize')),
            $this->iniBytes((string) ini_get('post_max_size')),
            self::MAX_FILE_KB * 1024,
        ]);

        return max(1, (int) floor(min($limits) / 1024 / 1024));
    }

    /** "64M" / "8192K" / "1G" → bytes. 0 (không giới hạn) trả null để bị loại khỏi phép min. */
    private function iniBytes(string $value): ?int
    {
        if ($value === '' || ! preg_match('/^(\d+)\s*([KMG])?$/i', trim($value), $m)) {
            return null;
        }

        $bytes = (int) $m[1] * match (strtoupper($m[2] ?? '')) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        return $bytes > 0 ? $bytes : null;
    }
}
