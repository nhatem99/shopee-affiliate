<?php

namespace App\Services;

use App\Exceptions\AffiliateScanException;
use Illuminate\Support\Str;

/**
 * Nick Zalo cá nhân thấy khách dán link Shopee (trong nhóm hoặc nhắn riêng) thì trả lại link
 * mua có mã — cùng các bước mà trang chủ làm khi khách dán link rồi bấm "Mua ngay":
 * lấy mã từ nguồn đang bật (VoucherFetchService), đổi mã affiliate về của mình, bọc /go/{code}.
 *
 * Khác trang chủ ở hai chỗ, vì trong nhóm không có ai đăng nhập và không có nút để bấm:
 *  • không gắn sub_id khách — chưa biết người dán link là tài khoản nào trên web;
 *  • không đi vòng comment Facebook — link /go/ trả thẳng vào nhóm.
 * Link được tạo NGAY lúc trả lời; khách bấm muộn thì mã có thể đã hết lượt (trang chủ thì lấy
 * mã mới lúc bấm "Mua ngay").
 */
class ZaloGroupLinkReplyService
{
    private const URL_PATTERN = '#https?://[^\s<>"\']+#iu';

    public function __construct(
        private UrlValidationService $urlValidator,
        private VoucherFetchService $fetcher,
        private ShopeeLinkResolverService $resolver,
        private AffiliateLinkRewriterService $rewriter,
        private ShortLinkService $shortLinks,
        private VoucherRefService $refs,
        private DirectAffiliateLinkService $direct,
    ) {}

    /**
     * Link Shopee đầu tiên trong một tin đến của cầu nối. Zalo biến link dán vào thành thẻ xem
     * trước, lúc đó URL nằm ở attachment.href chứ không ở text — phải xem cả hai.
     *
     * @param  array<string, mixed>  $event
     */
    public function shopeeUrlIn(array $event): ?string
    {
        $chunks = [
            $event['text'] ?? null,
            data_get($event, 'attachment.href'),
            data_get($event, 'attachment.title'),
            data_get($event, 'attachment.description'),
        ];

        foreach ($chunks as $chunk) {
            if (! is_string($chunk) || $chunk === '') {
                continue;
            }

            preg_match_all(self::URL_PATTERN, $chunk, $matches);
            foreach ($matches[0] as $url) {
                $url = rtrim($url, '.,;:!?)]}');
                try {
                    $this->urlValidator->validateShopeeOnly($url);

                    return $url;
                } catch (AffiliateScanException) {
                    // Link không phải Shopee (YouTube, TikTok...) — xem link kế tiếp.
                }
            }
        }

        return null;
    }

    /**
     * Nội dung trả lời cho một link Shopee. Dòng đầu (tên sản phẩm) in đậm.
     *
     * @return array{text: string, styles: list<array{start: int, len: int, st: string}>}
     */
    public function replyFor(string $url): array
    {
        // Công tắc "chỉ đổi sang link affiliate" (trang admin Zalo nick nhóm): không lấy mã, trả thẳng link
        // affiliate của Shopee — không bọc /go/ của site.
        if (DirectAffiliateLinkService::enabled()) {
            return $this->directReplyFor($url);
        }

        try {
            $result = $this->fetcher->fetch($url);
            $voucherLink = $result->data['voucher_link'] ?? null;
            if (! $voucherLink) {
                return $this->message('😥 Sản phẩm này hiện chưa lấy được mã giảm giá, bạn thử lại sau nhé.');
            }
            $this->urlValidator->validateAffiliateRedirectUrl($voucherLink);
        } catch (AffiliateScanException $e) {
            return $this->message('😥 '.$e->getMessage());
        }

        $product = $result->data['product'] ?? $this->productFor($result->canonicalUrl, $result->data);
        $name = $product['product_name'] ?? null;

        $link = $this->shortLinks->create(
            $this->rewriter->rewriteToOwnAffiliate($voucherLink),
            $result->source,
            $name,
            $product['product_image'] ?? null,
        );
        $buyUrl = url('/go/'.$link->code);

        $title = '🛍️ '.($name ? Str::limit($name, 120) : 'Link mua có mã giảm giá');

        // Chế độ mã YTB: khách phải tự mở link YouTube trước rồi mới mua — y như hai bước
        // trên trang chủ (ShortLinkController::activateYoutube).
        if ($result->ytbUrl) {
            $ref = $this->refs->issue($voucherLink, $result->source, $result->sourceUrl, $result->ytbUrl);

            return $this->message(implode("\n", [
                $title,
                '1️⃣ Mở link này trước để kích hoạt mã:',
                route('voucher.ytb', $ref),
                '2️⃣ Rồi quay lại bấm link này để mua:',
                $buyUrl,
            ]));
        }

        return $this->message(implode("\n", [
            $title,
            '👉 Bấm link này để mua có mã giảm giá:',
            $buyUrl,
        ]));
    }

    /**
     * @return array{text: string, styles: list<array{start: int, len: int, st: string}>}
     */
    private function directReplyFor(string $url): array
    {
        try {
            $direct = $this->direct->build($url, (string) config('services.shopee_affiliate.utm_content_zalo'));
        } catch (AffiliateScanException $e) {
            return $this->message('😥 '.$e->getMessage());
        }

        $name = $direct['product']['product_name'] ?? null;

        return $this->message(implode("\n", [
            '🛍️ '.($name ? Str::limit($name, 120) : 'Link mua sản phẩm'),
            '👉 Link mua:',
            $direct['url'],
        ]));
    }

    /**
     * Nguồn mã không trả thông tin sản phẩm thì hỏi Shopee — y như ShopeeVoucherController.
     */
    private function productFor(string $canonicalUrl, array $data): ?array
    {
        $ids = $this->resolver->extractIds($canonicalUrl);
        if (! $ids && ! empty($data['shop_id']) && ! empty($data['item_id'])) {
            $ids = ['shop_id' => $data['shop_id'], 'item_id' => $data['item_id']];
        }

        return $ids ? $this->resolver->fetchProductInfo($ids['item_id'], $ids['shop_id']) : null;
    }

    /**
     * In đậm dòng đầu. Vị trí tính theo đơn vị UTF-16 vì Zalo đếm như chuỗi JavaScript —
     * emoji chiếm 2 đơn vị, đếm bằng mb_strlen là lệch.
     *
     * @return array{text: string, styles: list<array{start: int, len: int, st: string}>}
     */
    private function message(string $text): array
    {
        $firstLine = strtok($text, "\n");

        return [
            'text' => $text,
            'styles' => [[
                'start' => 0,
                'len' => intdiv(strlen(mb_convert_encoding($firstLine, 'UTF-16LE', 'UTF-8')), 2),
                'st' => 'b',
            ]],
        ];
    }
}
