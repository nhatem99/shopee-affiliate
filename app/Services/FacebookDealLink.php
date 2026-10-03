<?php

namespace App\Services;

/**
 * Link mua (và link kích hoạt mã YTB nếu có) cho một bài deal trong nhóm Facebook — xem
 * FacebookDealLinkBuilder.
 */
final readonly class FacebookDealLink
{
    public function __construct(
        public string $buyUrl,
        /** Chế độ mã YTB: khách mở link này trước rồi mới bấm link mua — y như bot Zalo. */
        public ?string $ytbActivateUrl = null,
        public ?int $shortLinkId = null,
        public ?string $source = null,
        public ?string $canonicalUrl = null,
        public ?array $product = null,
    ) {}

    /**
     * Đoạn thay vào chỗ {link} trong mẫu bài. Câu chữ giữ đúng như bot Zalo
     * (ZaloGroupLinkReplyService::replyFor) để khách gặp ở kênh nào cũng một kiểu.
     */
    public function captionBlock(): string
    {
        if ($this->ytbActivateUrl) {
            return implode("\n", [
                '1️⃣ Mở link này trước để kích hoạt mã:',
                $this->ytbActivateUrl,
                '2️⃣ Rồi quay lại bấm link này để mua:',
                $this->buyUrl,
            ]);
        }

        return implode("\n", [
            '👉 Bấm link này để mua có mã giảm giá:',
            $this->buyUrl,
        ]);
    }
}
