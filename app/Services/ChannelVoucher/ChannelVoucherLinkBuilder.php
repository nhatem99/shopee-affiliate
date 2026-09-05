<?php

namespace App\Services\ChannelVoucher;

use App\Services\UrlValidationService;

/**
 * Dựng link affiliate TRƠN (chưa có mã) mang mmp_pid của tài khoản KOL, để đem đăng lên
 * Facebook/Instagram.
 *
 * Link này CỐ TÌNH chưa có mã: credential_token/encrypted_payload là chữ ký của Shopee, buộc
 * chặt vào tài khoản KOL + sản phẩm + kênh, không thể tự sinh. Mã chỉ xuất hiện ở link mà Shopee
 * đúc ra khi có người bấm vào link này TỪ TRONG nền tảng — đó là thứ ChannelVoucherMinter đi đọc
 * ngược về.
 */
class ChannelVoucherLinkBuilder
{
    public function __construct(private UrlValidationService $urlValidator) {}

    /**
     * @param  string  $marker  Chuỗi duy nhất của lượt đúc này, nhét vào utm_term. Trình duyệt dùng
     *                          nó để nhận ra ĐÚNG link của mình giữa các link khác trong bài đăng —
     *                          bài đăng dùng chung cho mọi khách nên lúc nào cũng có comment lạ.
     */
    public function build(string $canonicalUrl, string $channel, string $marker): string
    {
        $kolPid = (string) config('services.shopee_affiliate.kol_pid');

        $params = [
            // Shopee đọc 2 tham số này để biết link được chia sẻ qua kênh nào, từ đó quyết định
            // đúc mã FB hay mã IG. Đây chỉ là gợi ý kênh — chữ ký thật vẫn do Shopee cấp lại.
            'channel_type' => $channel,
            'content_source' => $channel,
            'mmp_pid' => $kolPid,
            'utm_source' => $kolPid,
            'utm_medium' => 'affiliates',
            'utm_campaign' => '-',
            'utm_content' => (string) config('services.shopee_affiliate.sub_id'),
            'utm_term' => $marker,
        ];

        return $this->productUrl($canonicalUrl).'?'.http_build_query($params);
    }

    /**
     * Đưa link về dạng https://shopee.vn/product/{shop_id}/{item_id} — đúng dạng Shopee dùng cho
     * link affiliate (đo trên link thật của KOL). Link người dùng dán vào thường ở dạng
     * /ten-san-pham-i.{shop_id}.{item_id}; hai dạng này cùng trỏ tới một sản phẩm.
     *
     * Public vì ChannelVoucherMinter dùng chính kết quả này làm khoá cache: chuẩn hoá ở đây mà
     * cache lại băm URL thô thì hai khách dán cùng một sản phẩm kèm tham số tracking khác nhau
     * sẽ ra hai khoá khác nhau, cache không bao giờ trúng, và mỗi lượt là thêm comment lên
     * Facebook. Một phép chuẩn hoá duy nhất cho cả hai việc thì không thể lệch nhau.
     *
     * Không tách được ID thì giữ nguyên đường dẫn gốc và bỏ query cũ đi (query cũ hay chứa tham số
     * affiliate của người khác, để lại là gắn nhầm hoa hồng cho họ).
     */
    public function productUrl(string $canonicalUrl): string
    {
        $ids = $this->urlValidator->extractShopeeIds($canonicalUrl);

        if ($ids !== []) {
            return "https://shopee.vn/product/{$ids['shop_id']}/{$ids['item_id']}";
        }

        // Chốt chặn cuối: link đi ra từ đây sẽ được đăng lên Facebook và mở bằng trình duyệt đang
        // đăng nhập, nên tuyệt đối không mang theo host lạ. Người gọi đã lọc rồi, nhưng lọc ở
        // đúng chỗ sinh ra URL là chỗ không ai vô tình đi vòng qua được.
        $parts = parse_url($canonicalUrl);
        $host = strtolower(preg_replace('/^www\./', '', $parts['host'] ?? ''));

        if (! in_array($host, ['shopee.vn', 's.shopee.vn', 'shp.ee'], true)) {
            throw new \InvalidArgumentException("Không dựng được link KOL từ host lạ: {$host}");
        }

        return 'https://'.$parts['host'].($parts['path'] ?? '');
    }
}
