<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Khối "Ưu đãi đang có" ở trang chủ: mã YTB nào đang có và đã dùng bao nhiêu %, đọc từ ganma
 * (GanmaService::fetchVoucherStatus) — cùng số liệu khối tương tự trên trang ganma.vn/yt.
 *
 * CHỈ HIỆN KHI NGUỒN ĐANG PHỤC VỤ KHÁCH LÀ GANMA (activeSource, đã tính lớp ghi đè khung giờ).
 * Lúc đang để kieushopee, khách nhận mã FB-IG — khoe số liệu mã YTB lúc đó là nói sai về chính
 * cái mã khách sắp nhận. Không phải ganma thì cũng không gọi sang ganma.
 *
 * Cache kiểu stale-while-revalidate (Cache::flexible) thay vì một lệnh theo lịch:
 *  • Trang chủ không bao giờ đứng chờ ganma, trừ đúng lượt đầu khi cache còn trống (≤ 4 giây,
 *    xem GanmaService::VOUCHER_STATUS_TIMEOUT). Quá FRESH_SECONDS thì trả bản đang có rồi làm
 *    mới ngầm sau khi trả trang.
 *  • Không ai vào trang thì không gọi gì. Lệnh chạy mỗi phút sẽ đập sang ganma 1.440 lượt/ngày
 *    và đẻ thêm chừng đó dòng ở /admin/scheduler (bảng lượt chạy không tự dọn).
 *
 * Ganma hỏng thì giữ bản cũ thay vì ghi đè bằng rỗng — nhưng chỉ hiện nếu bản đó chưa quá
 * MAX_AGE_SECONDS. Mã YTB hết lượt rất nhanh sau mỗi đợt back mã; số liệu cũ hơn thế thì thà
 * ẩn hẳn còn hơn khoe "còn mã" cho một mã đã hết từ lâu.
 */
class YtbVoucherStatusService
{
    public const CACHE_KEY = 'ytb_voucher_status';

    /** Bản cache trẻ hơn chừng này thì dùng luôn; già hơn thì vẫn dùng nhưng làm mới ngầm. */
    private const FRESH_SECONDS = 60;

    /** Quá chừng này không ai vào trang thì cache tự bỏ, lượt sau đọc lại từ đầu. */
    private const KEEP_SECONDS = 900;

    /** Số liệu cũ hơn chừng này thì không hiện nữa — xem ghi chú đầu lớp. */
    public const MAX_AGE_SECONDS = 600;

    public function __construct(
        private VoucherSourceResolver $sources,
        private GanmaService $ganma,
    ) {}

    /**
     * Prop `ytbVouchers` của trang chủ. null = không hiện khối (không phải ganma, chưa có số liệu,
     * hoặc số liệu đã cũ).
     *
     * @return list<array{title: string, subtitle: string, used_percent: ?int, sold_out: bool}>|null
     */
    public function forDisplay(): ?array
    {
        if ($this->sources->activeSource() !== GanmaService::SOURCE) {
            return null;
        }

        $snapshot = Cache::flexible(self::CACHE_KEY, [self::FRESH_SECONDS, self::KEEP_SECONDS], fn () => $this->snapshot());

        $fetchedAt = $snapshot['fetched_at'] ?? null;

        if (! is_int($fetchedAt) || now()->getTimestamp() - $fetchedAt > self::MAX_AGE_SECONDS || ! $snapshot['vouchers']) {
            return null;
        }

        return array_map(fn (array $voucher) => [
            'title' => $voucher['title'],
            'subtitle' => $voucher['subtitle'],
            // Ganma trả "còn bao nhiêu %", khách quen đọc "đã dùng bao nhiêu %" như trên trang họ.
            'used_percent' => $voucher['percent_left'] === null ? null : 100 - $voucher['percent_left'],
            'sold_out' => $voucher['sold_out'],
        ], $snapshot['vouchers']);
    }

    /**
     * Một lượt đọc từ ganma. Hỏng thì trả lại bản đang có (giữ nguyên fetched_at cũ để
     * MAX_AGE_SECONDS vẫn đếm đúng), chưa có gì thì trả khung rỗng — KHÔNG trả null: Cache::flexible
     * coi null là "chưa có", lượt nào cũng đọc lại và khách lượt nào cũng phải chờ.
     *
     * @return array{vouchers: list<array>, fetched_at: ?int}
     */
    private function snapshot(): array
    {
        $vouchers = $this->ganma->fetchVoucherStatus();

        if ($vouchers === null) {
            return Cache::get(self::CACHE_KEY) ?? ['vouchers' => [], 'fetched_at' => null];
        }

        return ['vouchers' => $vouchers, 'fetched_at' => now()->getTimestamp()];
    }
}
