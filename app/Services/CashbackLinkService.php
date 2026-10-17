<?php

namespace App\Services;

use App\Exceptions\AffiliateScanException;
use App\Models\AffiliateLink;
use App\Models\Setting;
use App\Models\User;

/**
 * Ô dán link trên trang chủ khi web TẮT lấy mã (công tắc "Lấy mã giảm giá trên web", mặc định
 * tắt từ 17-10-2026 — giống dealngon, web chỉ làm hoàn tiền). Dán link Shopee → link affiliate
 * chính thức của Shopee (s.shopee.vn/an_redir, xem DirectAffiliateLinkService) mang ID affiliate
 * của mình, không đi qua nguồn mã nào: không còn mã FB/IG/YTB.
 *
 * Khách đăng nhập: Sub_id "CB-<sub_id>---" theo quy ước 5 khe của
 * AffiliateLinkRewriterService::buildSubId, để ShopeeReportImportService đọc ra đúng khách.
 * Khách vãng lai VẪN nhận link (như trước giờ, không bắt đăng nhập) — chỉ mang nhãn kênh, đơn về
 * hoa hồng cho mình nhưng không quy được về ai để hoàn tiền.
 */
class CashbackLinkService
{
    /** Bật = trang chủ lấy mã như cũ (ShopeeVoucherController); tắt = chỉ trả link hoàn tiền. */
    public const VOUCHER_TOOL_KEY = 'web_voucher_tool_enabled';

    public function __construct(
        private UrlValidationService $urlValidator,
        private DirectAffiliateLinkService $direct,
        private AffiliateLinkRewriterService $rewriter,
    ) {}

    public static function voucherToolEnabled(): bool
    {
        return Setting::getBool(self::VOUCHER_TOOL_KEY, false);
    }

    /**
     * @return array{product: ?array<string, mixed>, link: string, logged_in: bool}
     *
     * @throws AffiliateScanException Thông điệp viết sẵn để hiện thẳng cho khách.
     */
    public function forShopee(string $url, ?User $user): array
    {
        $this->urlValidator->validateShopeeOnly($url);

        $label = (string) config('services.shopee_affiliate.utm_content_cashback', 'CB');
        $built = $this->direct->build($url, $this->rewriter->buildSubId($label, $user?->sub_id));

        if ($user !== null) {
            // Ghi vào lịch sử của khách (/history) — chỗ tìm lại link đã dán ở máy khác.
            AffiliateLink::create([
                'user_id' => $user->id,
                'original_url' => $url,
                'short_url' => $built['url'],
                'platform' => 'shopee',
                'product_name' => $built['product']['product_name'] ?? null,
                'product_image' => $built['product']['product_image'] ?? null,
                'original_price' => $built['product']['original_price'] ?? null,
                'discounted_price' => $built['product']['discounted_price'] ?? null,
            ]);
        }

        return [
            'product' => $built['product'],
            'link' => $built['url'],
            'logged_in' => $user !== null,
        ];
    }
}
