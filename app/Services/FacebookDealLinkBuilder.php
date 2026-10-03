<?php

namespace App\Services;

use App\Exceptions\AffiliateScanException;

/**
 * Link mua có mã cho một link Shopee admin dán — cùng các bước bot Zalo nhóm làm
 * (ZaloGroupLinkReplyService::replyFor): lấy mã từ nguồn đang bật, đổi affiliate về của mình,
 * bọc /go/{code}; chế độ mã YTB thì thêm link kích hoạt.
 *
 * Gọi hai lần cho mỗi bài: lúc admin soạn (ra link dự phòng + thông tin sản phẩm) và lúc bot
 * nhận bài để đăng — mã có giới hạn lượt, lấy càng sát giờ đăng thì càng còn dùng được.
 *
 * TODO: gộp với ZaloGroupLinkReplyService sau khi phần Zalo mirror (đang sửa dở trên main)
 * vào code — tách riêng ở đây chỉ để hai việc không sửa đè cùng một file.
 */
class FacebookDealLinkBuilder
{
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
     * @throws AffiliateScanException Thông điệp đã viết sẵn để hiện thẳng cho admin.
     */
    public function build(string $shopeeUrl): FacebookDealLink
    {
        // Công tắc "chỉ đổi sang link affiliate" ở Admin > Cài đặt: không lấy mã, đăng thẳng
        // link affiliate của Shopee (không bọc /go/ — nên cũng không đếm được lượt bấm).
        if (DirectAffiliateLinkService::enabled()) {
            $direct = $this->direct->build($shopeeUrl, (string) config('services.shopee_affiliate.utm_content_fb_group'));

            return new FacebookDealLink(
                $direct['url'],
                null,
                null,
                DirectAffiliateLinkService::SOURCE,
                $direct['canonical_url'],
                $direct['product'],
            );
        }

        $result = $this->fetcher->fetch($shopeeUrl);
        $voucherLink = $result->data['voucher_link'] ?? null;
        if (! $voucherLink) {
            throw new AffiliateScanException('Sản phẩm này hiện chưa lấy được mã giảm giá, thử lại sau.');
        }
        $this->urlValidator->validateAffiliateRedirectUrl($voucherLink);

        $product = $result->data['product'] ?? $this->productFor($result->canonicalUrl, $result->data);
        $name = $product['product_name'] ?? null;

        $link = $this->shortLinks->create(
            $this->rewriter->rewriteToOwnAffiliate($voucherLink),
            $result->source,
            $name,
            $product['product_image'] ?? null,
        );

        $ytbActivateUrl = null;
        if ($result->ytbUrl) {
            $ref = $this->refs->issue($voucherLink, $result->source, $result->sourceUrl, $result->ytbUrl);
            $ytbActivateUrl = route('voucher.ytb', $ref);
        }

        return new FacebookDealLink(
            url('/go/'.$link->code),
            $ytbActivateUrl,
            $link->id,
            $result->source,
            $result->canonicalUrl,
            $product,
        );
    }

    /**
     * Nguồn mã không trả thông tin sản phẩm thì hỏi Shopee — y như ZaloGroupLinkReplyService.
     */
    private function productFor(string $canonicalUrl, array $data): ?array
    {
        $ids = $this->resolver->extractIds($canonicalUrl);
        if (! $ids && ! empty($data['shop_id']) && ! empty($data['item_id'])) {
            $ids = ['shop_id' => $data['shop_id'], 'item_id' => $data['item_id']];
        }

        return $ids ? $this->resolver->fetchProductInfo($ids['item_id'], $ids['shop_id']) : null;
    }
}
