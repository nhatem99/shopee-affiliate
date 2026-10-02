<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Gọi thử nguồn lấy mã kieushopee theo lịch, và TỰ BẬT CHẾ ĐỘ BẢO TRÌ khi nó chết.
 *
 * Lý do có lớp này: điểm gãy hay gặp nhất của nguồn là `next_action` — ID Server Action do bản
 * build Next.js của họ sinh ra, cứ deploy lại là đổi và mọi request thành 404 "Server action not
 * found". Đo thật: 18-09-2026 nguồn chết từ 02:21 tới tận hôm sau mà trang vẫn mở bình thường,
 * khách vẫn dán link — chỉ có điều mọi lượt đều rơi sang nhánh dự phòng. Không ai biết cho tới
 * khi ngồi đọc báo cáo affiliate.
 *
 * Kiểm tra kieushopee kể cả khi admin để ganma: quyết định của admin là "hễ sansale.kieushopee.com
 * lỗi là đóng trang", kể cả khi ganma vẫn ra mã được (chế độ mã YTB vẫn đưa khách link kieushopee).
 * Ngoại lệ duy nhất là laymavoucher: admin bật nó chính là để né lúc kieushopee chết, lúc đó
 * không lượt quét nào đi qua kieushopee nữa — vẫn kiểm tra kieushopee thì trang bị đóng đúng
 * lúc nguồn dự phòng đang chạy ngon. Nên khi đang để laymavoucher thì kiểm tra laymavoucher.
 * Cùng lý do đó, để ganma mà đã tắt "mã YTB gọi kèm kieushopee" thì bỏ qua hẳn — xem
 * skipWhileYtbOnly().
 * Bật/tắt bảo trì dùng đúng Setting `maintenance_mode` mà MaintenanceMode middleware
 * và trang Cài đặt vẫn đọc — không thêm đường thứ hai để sớm muộn hai bên lệch nhau.
 *
 * Ranh giới với admin (quan trọng): lớp này chỉ được tắt CHÍNH lần bảo trì do nó bật. Admin bật
 * tay thì nguồn sống lại cũng không ai tắt hộ — xem AUTO_FLAG_KEY.
 */
class SourceHealthService
{
    /** Công tắc ở Admin > Cài đặt. Mặc định TẮT theo lệ chung của repo cho mọi hành vi tự động. */
    public const ENABLED_KEY = 'auto_maintenance_on_source_failure';

    /** Kết quả lần kiểm tra gần nhất (JSON) — trang Cài đặt đọc để hiện trạng thái. */
    public const STATUS_KEY = 'kieushopee_health_status';

    /**
     * '1' = lần bảo trì ĐANG chạy do chính lớp này bật. Đây là thứ phân biệt "máy bật" với
     * "admin bật tay", và là điều kiện duy nhất cho phép tự tắt lại khi nguồn sống.
     */
    public const AUTO_FLAG_KEY = 'maintenance_turned_on_by_health';

    /**
     * Số lần lỗi LIÊN TIẾP trước khi đóng trang. Không đóng ngay từ lần đầu: nguồn thỉnh thoảng
     * timeout một nhịp rồi tự khỏi, mà mỗi lần đóng/mở trang là một lần khách đang dán link giữa
     * chừng bị đá ra. 2 lần × 5 phút/lượt ≈ chậm nhất 10 phút mới đóng.
     */
    public const FAILURES_BEFORE_MAINTENANCE = 2;

    public function __construct(
        private KieuShopeeService $kieuShopee,
        private LaymaVoucherService $layma,
        private VoucherSourceResolver $sources,
    ) {}

    /**
     * Nguồn được đem ra kiểm tra — xem ghi chú đầu lớp. Theo lựa chọn của admin (configured),
     * không theo activeSource(): lớp ghi đè khung giờ chỉ chuyển ganma sang kieushopee, không
     * bao giờ đụng tới laymavoucher.
     */
    private function sourceUnderCheck(): KieuShopeeService
    {
        return $this->sources->configuredSource() === LaymaVoucherService::SOURCE
            ? $this->layma
            : $this->kieuShopee;
    }

    public static function enabled(): bool
    {
        return Setting::getBool(self::ENABLED_KEY, false);
    }

    /**
     * Gọi thử nguồn một lượt rồi bật/tắt bảo trì theo kết quả. Người gọi (command theo lịch, nút
     * ở trang Cài đặt) chỉ việc hiện lại mảng trả về.
     *
     * @return array{checked_at: string, ok: bool, message: string, consecutive_failures: int, action: ?string}
     */
    public function check(): array
    {
        if ($this->sources->ytbOnly()) {
            return $this->skipWhileYtbOnly();
        }

        // Dùng đúng testConnection() của nút "Kiểm tra kết nối" ở /admin/api-config: cùng tham
        // số, cùng đường đi với lượt lấy mã thật của khách. Tự dựng một request riêng ở đây là
        // tự tạo ra khả năng "check nói OK mà khách vẫn không lấy được mã".
        $service = $this->sourceUnderCheck();
        $source = $service::SOURCE;
        $result = $service->testConnection();

        // Vừa đổi nguồn thì đếm lỗi lại từ đầu: lỗi của nguồn cũ không phải bằng chứng gì về
        // nguồn mới. Không reset thì admin chuyển sang laymavoucher lúc kieushopee đã lỗi 1 lượt,
        // laymavoucher chỉ cần hụt một nhịp là trang đóng luôn.
        $previous = $this->status();
        $carried = ($previous['source'] ?? KieuShopeeService::SOURCE) === $source ? $previous['consecutive_failures'] : 0;
        $failures = $result['ok'] ? 0 : $carried + 1;

        $status = [
            'checked_at' => now()->toIso8601String(),
            'source' => $source,
            'ok' => $result['ok'],
            'message' => $result['message'],
            'consecutive_failures' => $failures,
            'action' => $result['ok'] ? $this->onSourceUp($source) : $this->onSourceDown($failures, $source),
        ];

        Setting::set(self::STATUS_KEY, json_encode($status, JSON_UNESCAPED_UNICODE));

        return $status;
    }

    /**
     * Đang để ganma và đã tắt "mã YTB gọi kèm kieushopee": không lượt quét nào đi qua kieushopee
     * nữa — cùng lý do với ngoại lệ laymavoucher, kiểm tra nó thì trang bị đóng đúng lúc ganma đang
     * phục vụ ngon (admin tắt công tắc đó thường chính vì kieushopee đang chết).
     *
     * Cũng không đem ganma ra kiểm tra thay: mỗi lượt mất ~20 giây, và hụt mã thường là do sản
     * phẩm thử hết mã chứ không phải nguồn chết — đóng trang theo nó là đóng nhầm.
     *
     * Lần bảo trì do chính lớp này bật (lúc kieushopee chết) thì mở lại luôn: lý do đóng trang
     * không còn chạm tới khách nữa, để nguyên thì admin tắt công tắc xong trang vẫn đóng.
     *
     * @return array{checked_at: string, ok: bool, message: string, consecutive_failures: int, action: ?string}
     */
    private function skipWhileYtbOnly(): array
    {
        $status = [
            'checked_at' => now()->toIso8601String(),
            'source' => KieuShopeeService::SOURCE,
            'ok' => true,
            'skipped' => true,
            'message' => 'Bỏ qua — chế độ mã YTB đang không gọi kieushopee nên kieushopee lỗi cũng không ảnh hưởng khách.',
            'consecutive_failures' => 0,
            'action' => $this->reopen('SourceHealthService: chế độ mã YTB không gọi kieushopee nữa — đã tự tắt chế độ bảo trì'),
        ];

        Setting::set(self::STATUS_KEY, json_encode($status, JSON_UNESCAPED_UNICODE));

        return $status;
    }

    /**
     * Kết quả lần kiểm tra gần nhất. Chưa chạy lần nào thì trả về khung rỗng thay vì null — nơi
     * gọi khỏi phải tự phòng thủ, và trang Cài đặt hiện được "chưa kiểm tra lần nào".
     *
     * `source` null = lần kiểm tra lưu trước khi có laymavoucher, lúc đó chỉ có kieushopee.
     *
     * `skipped` true = lần đó bỏ qua vì chế độ mã YTB không gọi kieushopee — xem skipWhileYtbOnly().
     *
     * @return array{checked_at: ?string, source: ?string, ok: ?bool, skipped: bool, message: ?string, consecutive_failures: int, action: ?string}
     */
    public function status(): array
    {
        $saved = json_decode((string) Setting::get(self::STATUS_KEY, ''), true);

        if (! is_array($saved)) {
            $saved = [];
        }

        return [
            'checked_at' => $saved['checked_at'] ?? null,
            'source' => $saved['source'] ?? null,
            'ok' => $saved['ok'] ?? null,
            'skipped' => (bool) ($saved['skipped'] ?? false),
            'message' => $saved['message'] ?? null,
            'consecutive_failures' => (int) ($saved['consecutive_failures'] ?? 0),
            'action' => $saved['action'] ?? null,
        ];
    }

    /**
     * Admin vừa tự tay gạt công tắc bảo trì ở trang Cài đặt — từ giờ lần bảo trì này là của
     * admin, lớp này không được tắt hộ nữa.
     *
     * Đếm lỗi cũng về 0: admin tắt bảo trì trong lúc nguồn vẫn chết mà vẫn giữ nguyên bộ đếm thì
     * đúng lượt kiểm tra kế tiếp (≤5 phút) trang đóng lại ngay, nhìn như nút không ăn. Về 0 thì
     * admin có trọn một chu kỳ để xử lý; muốn trang mở hẳn thì tắt công tắc tự động.
     */
    public function handOverToAdmin(): void
    {
        Setting::set(self::AUTO_FLAG_KEY, '0');

        $status = $this->status();
        $status['consecutive_failures'] = 0;
        Setting::set(self::STATUS_KEY, json_encode($status, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Admin vừa TẮT công tắc tự động. Nếu trang đang bảo trì vì lớp này bật thì mở lại luôn —
     * tắt công tắc mà trang vẫn đóng thì admin không còn manh mối nào để hiểu vì sao, trong khi
     * ý định "thôi đừng tự động nữa" đã rõ ràng.
     */
    public function stopAutoMaintenance(): void
    {
        if (Setting::getBool(self::AUTO_FLAG_KEY, false)) {
            Setting::set('maintenance_mode', '0');

            Log::info('SourceHealthService: admin tắt công tắc tự động — mở lại trang đang bảo trì tự động');
        }

        $this->handOverToAdmin();
    }

    /** @return string|null Việc vừa làm, để command/trang Cài đặt nói lại cho người đọc. */
    private function onSourceDown(int $failures, string $source): ?string
    {
        if ($failures < self::FAILURES_BEFORE_MAINTENANCE) {
            return 'cho_them_luot';
        }

        if (Setting::getBool('maintenance_mode', false)) {
            return null;
        }

        Setting::set('maintenance_mode', '1');
        Setting::set(self::AUTO_FLAG_KEY, '1');

        Log::warning("SourceHealthService: nguồn {$source} chết — đã tự bật chế độ bảo trì", [
            'so_lan_loi_lien_tiep' => $failures,
        ]);

        return 'da_bat_bao_tri';
    }

    private function onSourceUp(string $source): ?string
    {
        return $this->reopen("SourceHealthService: nguồn {$source} sống lại — đã tự tắt chế độ bảo trì");
    }

    /** Mở lại trang nếu lần bảo trì đang chạy là do chính lớp này bật. */
    private function reopen(string $logMessage): ?string
    {
        // Bảo trì do admin bật tay (hoặc không hề bảo trì) thì không đụng vào.
        if (! Setting::getBool(self::AUTO_FLAG_KEY, false)) {
            return null;
        }

        Setting::set('maintenance_mode', '0');
        Setting::set(self::AUTO_FLAG_KEY, '0');

        Log::info($logMessage);

        return 'da_tat_bao_tri';
    }
}
