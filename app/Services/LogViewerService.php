<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Đọc file log Laravel trong storage/logs và bóc thành từng bản ghi để hiển thị ở
 * /admin/logs — xem lỗi production ngay trên web, không cần SSH lên server.
 *
 * Chỉ đọc phần ĐUÔI file (MAX_BYTES): laravel.log ở production có thể lên tới hàng chục MB,
 * nạp cả file vào bộ nhớ là cách nhanh nhất để giết tiến trình PHP. Đổi lại, trang này luôn
 * chỉ thấy phần mới nhất của log — đủ dùng cho mục đích chẩn đoán lỗi vừa xảy ra.
 */
class LogViewerService
{
    /** Lượng dữ liệu tối đa đọc từ cuối file (~2MB ≈ vài nghìn bản ghi gần nhất). */
    private const MAX_BYTES = 2 * 1024 * 1024;

    /** Số bản ghi tối đa trả về frontend sau khi lọc — tránh trả response nặng vài chục MB. */
    private const MAX_ENTRIES = 300;

    /** Thứ tự nghiêm trọng của Monolog — dùng để lọc "từ mức này trở lên". */
    private const SEVERITY = [
        'DEBUG' => 0,
        'INFO' => 1,
        'NOTICE' => 2,
        'WARNING' => 3,
        'ERROR' => 4,
        'CRITICAL' => 5,
        'ALERT' => 6,
        'EMERGENCY' => 7,
    ];

    // Header của 1 bản ghi Monolog: [2026-09-04 13:27:29] production.ERROR: nội dung
    private const HEADER_PATTERN = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:\d{2})?)\] ([^\s.]+)\.([A-Z]+): /m';

    /** Cho phép trỏ sang thư mục khác trong test; mặc định là storage/logs của app. */
    public function __construct(private ?string $directory = null) {}

    /**
     * Danh sách file log có thể xem, mới sửa gần nhất đứng đầu.
     *
     * @return list<array{name: string, size: int, readable: bool, modified: string}>
     */
    public function files(): array
    {
        $paths = glob($this->directory().'/*.log') ?: [];

        $files = array_map(fn (string $path) => [
            'name' => basename($path),
            'size' => filesize($path) ?: 0,
            'modified_at' => filemtime($path) ?: 0,
            // File log do user khác ghi (php-fpm vs deploy user) thì trang này đọc ra rỗng —
            // báo rõ ở giao diện thay vì để người xem tưởng "không có lỗi nào".
            'readable' => is_readable($path),
        ], $paths);

        usort($files, fn (array $a, array $b) => $b['modified_at'] <=> $a['modified_at']);

        return array_map(fn (array $file) => [
            'name' => $file['name'],
            'size' => $file['size'],
            'readable' => $file['readable'],
            'modified' => Carbon::createFromTimestamp($file['modified_at'])->toDateTimeString(),
        ], $files);
    }

    /**
     * Đọc + lọc bản ghi trong 1 file log.
     *
     * @param  string|null  $minLevel  Mức thấp nhất muốn xem (DEBUG/INFO/.../ERROR); null = tất cả.
     * @param  string|null  $search  Lọc theo chuỗi con, khớp cả nội dung lẫn context/stack trace.
     * @return array{entries: list<array<string, mixed>>, counts: array<string, int>, truncated: bool, total: int}
     */
    public function read(string $file, ?string $minLevel = null, ?string $search = null): array
    {
        $path = $this->resolve($file);

        if ($path === null) {
            return ['entries' => [], 'counts' => [], 'truncated' => false, 'total' => 0];
        }

        $truncated = (filesize($path) ?: 0) > self::MAX_BYTES;
        $entries = $this->parse($this->tail($path));

        // Mới nhất lên đầu — người xem gần như luôn quan tâm lỗi vừa xảy ra.
        $entries = array_reverse($entries);

        $counts = [];
        foreach ($entries as $entry) {
            $counts[$entry['level']] = ($counts[$entry['level']] ?? 0) + 1;
        }

        $threshold = self::SEVERITY[strtoupper((string) $minLevel)] ?? null;
        $needle = $search !== null && trim($search) !== '' ? mb_strtolower(trim($search)) : null;

        $filtered = array_values(array_filter($entries, function (array $entry) use ($threshold, $needle) {
            // Level lạ (không có trong bảng SEVERITY) luôn được giữ lại — thà hiện thừa còn hơn giấu mất lỗi.
            if ($threshold !== null && (self::SEVERITY[$entry['level']] ?? PHP_INT_MAX) < $threshold) {
                return false;
            }

            if ($needle !== null && ! str_contains(mb_strtolower($entry['message'].' '.$entry['detail']), $needle)) {
                return false;
            }

            return true;
        }));

        return [
            'entries' => array_slice($filtered, 0, self::MAX_ENTRIES),
            'counts' => $counts,
            'truncated' => $truncated,
            'total' => count($filtered),
        ];
    }

    /** Đường dẫn thật của file log, hoặc null nếu tên file không hợp lệ / không tồn tại. */
    public function resolve(string $file): ?string
    {
        // basename() + kiểm tra đuôi .log chặn mọi kiểu ../../.env — tên file đến từ query string.
        $name = basename($file);

        if (! str_ends_with($name, '.log')) {
            return null;
        }

        $path = $this->directory().'/'.$name;

        return is_file($path) && is_readable($path) ? $path : null;
    }

    private function directory(): string
    {
        return $this->directory ?? storage_path('logs');
    }

    /** Đọc tối đa MAX_BYTES cuối file, bỏ dòng đầu tiên nếu nó bị cắt dở. */
    private function tail(string $path): string
    {
        $size = filesize($path) ?: 0;
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        if ($size > self::MAX_BYTES) {
            fseek($handle, $size - self::MAX_BYTES);
            fgets($handle);
        }

        $content = stream_get_contents($handle);
        fclose($handle);

        return $content === false ? '' : $content;
    }

    /**
     * Tách nội dung log thành từng bản ghi. Mọi dòng nằm giữa 2 header thuộc về bản ghi phía
     * trước (stack trace nhiều dòng của exception), nên không cắt theo dòng mà cắt theo offset.
     *
     * @return list<array<string, mixed>>
     */
    private function parse(string $content): array
    {
        preg_match_all(self::HEADER_PATTERN, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $entries = [];

        foreach ($matches as $i => $match) {
            $bodyStart = $match[0][1] + strlen($match[0][0]);
            $bodyEnd = $matches[$i + 1][0][1] ?? strlen($content);
            $body = rtrim(substr($content, $bodyStart, $bodyEnd - $bodyStart));

            [$firstLine, $trace] = array_pad(explode("\n", $body, 2), 2, '');
            [$message, $context] = $this->splitContext($firstLine);

            $detail = trim($context."\n".$trace);

            $entries[] = [
                // Đủ để Vue phân biệt các dòng khi render; không cần bền vững giữa các lần tải.
                'id' => $i,
                'logged_at' => $match[1][0],
                'env' => $match[2][0],
                'level' => $match[3][0],
                'message' => trim($message),
                'detail' => Str::limit($detail, 5000),
            ];
        }

        return $entries;
    }

    /**
     * Laravel viết context JSON ngay sau nội dung, cùng một dòng:
     * `ChannelVoucherMinter: ... {"channel":"fb"}`. Tách ra để danh sách còn đọc được, JSON dài
     * đẩy xuống phần chi tiết (bấm mới mở).
     *
     * @return array{0: string, 1: string}
     */
    private function splitContext(string $line): array
    {
        $position = strpos($line, ' {"');

        if ($position === false) {
            return [$line, ''];
        }

        $json = substr($line, $position + 1);
        $decoded = json_decode($json, true);

        return [
            substr($line, 0, $position),
            is_array($decoded)
                ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $json,
        ];
    }
}
