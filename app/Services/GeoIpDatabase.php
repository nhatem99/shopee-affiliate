<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader;

/**
 * File dữ liệu IP → quốc gia nằm ngay trên đĩa (DB-IP "IP to Country Lite", định dạng mmdb), để
 * GeoBlock chặn được IP nước ngoài NGAY LƯỢT ĐẦU mà không bắt ai chờ mạng: tra một IP chỉ là vài
 * lần đọc file, cỡ chục micro giây.
 *
 * Trước đó chỉ có ip-api.com: chờ nó thì khách VN chậm thêm tới 2 giây, không chờ thì IP lạ lọt
 * lượt đầu — mà lượt đầu là đủ cho máy quét link: chúng đổi IP liên tục, mở /go/{code} một lần là
 * đã sang tới Shopee và hiện thành click Ireland/Hà Lan/Mỹ trong báo cáo hoa hồng.
 *
 * Bản Lite miễn phí, không cần tài khoản, ra bản mới đầu mỗi tháng — `geoip:update` tải về. Giấy
 * phép CC BY 4.0, DB-IP yêu cầu ghi nguồn ở trang dùng kết quả: xem geo-blocked.blade.php.
 */
class GeoIpDatabase
{
    private const URL = 'https://download.db-ip.com/free/dbip-country-lite-%s.mmdb.gz';

    /** IP của VNPT — file tải về phải trả đúng VN cho IP này thì mới được thay file đang dùng. */
    private const PROBE_VN_IP = '113.160.0.1';

    private Reader|false|null $reader = null;

    public function __construct(private ?string $path = null)
    {
        $this->path ??= config('services.geoip.database');
    }

    /** Mã quốc gia (VN, JP...) theo file trên đĩa; null khi chưa có file hoặc file không biết IP này. */
    public function countryCode(string $ip): ?string
    {
        $this->reader ??= $this->open($this->path) ?? false;

        if ($this->reader === false) {
            return null;
        }

        try {
            $code = $this->reader->get($ip)['country']['iso_code'] ?? null;
        } catch (\Throwable) {
            return null;
        }

        return is_string($code) ? $code : null;
    }

    /** Tháng (Y-m) của bản đang dùng; null khi chưa có file. */
    public function installedMonth(): ?string
    {
        $month = is_file($this->path) && is_file($this->monthFile())
            ? trim((string) file_get_contents($this->monthFile()))
            : '';

        return $month !== '' ? $month : null;
    }

    /**
     * Tải bản mới nhất nếu máy chưa có. Thử bản tháng này trước — mấy ngày đầu tháng DB-IP có thể
     * chưa ra — rồi tới tháng trước. Tải hỏng thì file đang dùng giữ nguyên.
     *
     * @return array{status: 'installed'|'current'|'failed', month: ?string, error: ?string}
     */
    public function update(bool $force = false): array
    {
        $installed = $this->installedMonth();
        $errors = [];

        foreach ([now()->format('Y-m'), now()->subMonthNoOverflow()->format('Y-m')] as $month) {
            if (! $force && $installed !== null && $installed >= $month) {
                return ['status' => 'current', 'month' => $installed, 'error' => null];
            }

            $error = $this->install($month);

            if ($error === null) {
                return ['status' => 'installed', 'month' => $month, 'error' => null];
            }

            $errors[] = "{$month}: {$error}";
        }

        return ['status' => 'failed', 'month' => $installed, 'error' => implode('; ', $errors)];
    }

    /** @return string|null lỗi, null khi đã cài xong */
    private function install(string $month): ?string
    {
        $tmp = $this->path.'.download';

        try {
            $response = Http::timeout(120)->get(sprintf(self::URL, $month));

            if (! $response->successful()) {
                return 'HTTP '.$response->status();
            }

            // File .gz; phòng khi đường truyền đã tự giải nén (Content-Encoding) thì dùng luôn.
            $body = $response->body();
            $mmdb = str_starts_with($body, "\x1f\x8b") ? gzdecode($body) : $body;

            if ($mmdb === false) {
                return 'giải nén thất bại';
            }

            File::ensureDirectoryExists(dirname($this->path));
            file_put_contents($tmp, $mmdb);

            $error = $this->probe($tmp);

            if ($error !== null) {
                return $error;
            }

            // Cùng thư mục nên đổi tên là thay file trong một bước: request đang chạy không bao
            // giờ đọc phải file mới ghi dở.
            rename($tmp, $this->path);
            file_put_contents($this->monthFile(), $month);
            $this->reader = null;

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        } finally {
            File::delete($tmp);
        }
    }

    /** Thử đúng việc file phải làm trước khi dùng: IP Việt Nam phải ra VN. */
    private function probe(string $file): ?string
    {
        $reader = $this->open($file);

        if ($reader === null) {
            return 'file tải về không đọc được';
        }

        try {
            $code = $reader->get(self::PROBE_VN_IP)['country']['iso_code'] ?? null;
        } finally {
            $reader->close();
        }

        return $code === 'VN' ? null : 'IP mẫu '.self::PROBE_VN_IP." ra '".($code ?? 'null')."' thay vì VN";
    }

    private function open(string $file): ?Reader
    {
        if (! is_file($file)) {
            return null;
        }

        try {
            return new Reader($file);
        } catch (\Throwable $e) {
            Log::warning('GeoIpDatabase: không mở được file dữ liệu IP', ['file' => $file, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function monthFile(): string
    {
        return $this->path.'.month';
    }
}
