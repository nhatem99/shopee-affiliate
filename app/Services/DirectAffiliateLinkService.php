<?php

namespace App\Services;

use App\Exceptions\AffiliateScanException;
use App\Models\Setting;

/**
 * Công tắc "FB nhóm & Zalo: chỉ đổi sang link affiliate" ở Admin > Cài đặt. Bật thì bài đăng
 * nhóm Facebook và bot Zalo nhóm KHÔNG lấy mã (kieushopee/ganma) nữa: link Shopee khách dán
 * được đổi thẳng thành link affiliate chính thức của Shopee mang ID của mình, gửi nguyên link đó
 * — không bọc /go/ của site. Tắt (mặc định) thì vẫn link có mã như cũ.
 *
 * Dạng link là "link tuỳ chỉnh" của Shopee Affiliate:
 *   https://s.shopee.vn/an_redir?origin_link=<trang sản phẩm>&affiliate_id=<ID>&sub_id=<nhãn>
 * Đo thật 04-10-2026: Shopee trả 301 tới shopee.vn/opaanlp/{shop}/{item}?mmp_pid=an_<ID>&
 * utm_content=<nhãn>&utm_term=<mã lượt bấm do Shopee sinh> — tức Shopee tự ghi lượt bấm và tự
 * gắn affiliate; trình duyệt điện thoại nhận trang mở app Shopee như mọi link affiliate.
 *
 * Chọn dạng này chứ không gửi thẳng shopee.vn/product/...?mmp_pid= (cách FlashSaleSyncService
 * dựng link): trang sản phẩm Shopee khai og:url là link SẠCH, không mmp_pid — bot xem trước của
 * Facebook/Zalo đọc thẻ đó thì khung xem trước dẫn khách tới link sạch, mất hoa hồng (chính lý do
 * ShortLinkController::redirect trả HTML riêng cho bot). Còn s.shopee.vn/an_redir thì trả 403
 * cho bot của Facebook (đo cùng ngày), không có og:url nào để bóc.
 *
 * Không gắn sub_id khách: trong nhóm không biết ai là ai — đơn từ link này về hoa hồng cho mình
 * nhưng không quy được về tài khoản nào để hoàn tiền (y như link có mã của nhóm hiện nay).
 */
class DirectAffiliateLinkService
{
    public const ENABLED_KEY = 'group_links_direct_affiliate';

    /** Giá trị cột `source` của bài deal/short-link cho biết link này không có mã. */
    public const SOURCE = 'affiliate';

    private const REDIRECT_BASE = 'https://s.shopee.vn/an_redir';

    public function __construct(
        private ShopeeLinkResolverService $resolver,
    ) {}

    /** Mặc định TẮT — bật là đổi hẳn thứ khách trong nhóm nhận được (mất mã giảm giá). */
    public static function enabled(): bool
    {
        return Setting::getBool(self::ENABLED_KEY, false);
    }

    /**
     * @param  string  $label  nhãn Sub_id trong báo cáo Shopee — khách thấy trên thanh địa chỉ,
     *                         không đặt tên miền/thương hiệu vào đây.
     * @return array{url: string, canonical_url: string, product: ?array}
     *
     * @throws AffiliateScanException Thông điệp viết sẵn để hiện thẳng cho người dùng.
     */
    public function build(string $shopeeUrl, string $label): array
    {
        $canonicalUrl = $this->resolver->resolveCanonicalUrl($shopeeUrl);
        $ids = $this->resolver->extractIds($canonicalUrl);
        if (! $ids) {
            throw new AffiliateScanException('Chỉ đổi được link sản phẩm Shopee (link mở ra một sản phẩm) — link shop, danh mục hay tìm kiếm thì chưa.');
        }

        return [
            'url' => self::url($ids['shop_id'], $ids['item_id'], $label),
            'canonical_url' => $canonicalUrl,
            'product' => $this->resolver->fetchProductInfo($ids['item_id'], $ids['shop_id']),
        ];
    }

    public static function url(int|string $shopId, int|string $itemId, string $label): string
    {
        $query = [
            'origin_link' => "https://shopee.vn/product/{$shopId}/{$itemId}",
            'affiliate_id' => self::affiliateId(),
        ];
        if ($label !== '') {
            $query['sub_id'] = $label;
        }

        return self::REDIRECT_BASE.'?'.http_build_query($query);
    }

    /**
     * Link này đúng là link affiliate của mình — trang admin gửi lại link lúc soạn bài, không để
     * nó chèn link của người khác vào bài mà nick cá nhân sẽ đăng.
     */
    public static function isOwn(string $url): bool
    {
        if (! str_starts_with($url, self::REDIRECT_BASE.'?')) {
            return false;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $origin = (string) ($query['origin_link'] ?? '');

        return ($query['affiliate_id'] ?? null) === self::affiliateId()
            && preg_match('#^https://shopee\.vn/product/\d+/\d+$#', $origin) === 1;
    }

    /** mmp_pid "an_17332410386" → affiliate_id "17332410386" (Shopee tự thêm lại "an_"). */
    private static function affiliateId(): string
    {
        $mmpPid = (string) config('services.shopee_affiliate.mmp_pid');

        return str_starts_with($mmpPid, 'an_') ? substr($mmpPid, 3) : $mmpPid;
    }
}
