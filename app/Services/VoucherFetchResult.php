<?php

namespace App\Services;

/**
 * Kết quả một lượt lấy mã cho link khách dán — xem VoucherFetchService::fetch().
 */
final readonly class VoucherFetchResult
{
    public function __construct(
        /**
         * Nguồn đã sinh ra $data['voucher_link'] (KieuShopeeService::SOURCE | GanmaService::SOURCE).
         * Ghi vào voucher_ref, short-link và tracking — không suy ngược từ URL vì cả hai nguồn
         * đều có thể trả link trên domain Shopee.
         */
        public string $source,
        /** Link Shopee đã bung từ link ngắn (nếu có) — dùng cho tracking và moi shop_id/item_id. */
        public string $canonicalUrl,
        /**
         * Link phải đưa lại cho ĐÚNG nguồn trên khi cần lấy mã mới lúc khách bấm mua (xem
         * ShortLinkController::store): kieushopee nhận link đã bung, ganma chỉ nhận link ngắn gốc.
         */
        public string $sourceUrl,
        /**
         * Shape của fetchProductAndVoucherLink(); null nghĩa là không lấy được mã.
         *
         * @var array{voucher_link: string, shop_id: ?string, item_id: ?string, product: ?array}|null
         */
        public ?array $data,
        /**
         * Link YouTube của ganma (chế độ mã YTB) đi kèm link kieushopee ở $data — trình duyệt
         * của khách phải đi qua nó trước khi tới link đích, xem ShortLinkController::store().
         * null khi không ở chế độ đó, hoặc khi chính $data đã là link ganma.
         */
        public ?string $ytbUrl = null,
    ) {}
}
