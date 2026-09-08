<?php

namespace Tests\Feature;

use App\Exceptions\AffiliateScanException;
use App\Services\UrlValidationService;
use Tests\TestCase;

/**
 * Các dạng URL Shopee mà hệ thống phải nhận ra. Sai ở đây không làm gì sập — nó chỉ lặng lẽ
 * làm hỏng những thứ ở tầng trên (chặn nhầm khách, đăng trùng comment), nên phải khoá lại.
 */
class ShopeeUrlShapesTest extends TestCase
{
    private function validator(): UrlValidationService
    {
        return app(UrlValidationService::class);
    }

    /**
     * /opaanlp/ là trang landing áp mã của chương trình affiliate — đích của link nguồn ganma.
     *
     * Đọc được id ở đây mới có productKey ổn định (ShortLinkController). Không đọc được thì nó
     * rơi xuống băm URL đích, mà Shopee cấp credential_token MỚI sau mỗi lượt bấm nên URL đó
     * khác nhau mỗi lần → mỗi lượt bấm lại đăng một comment Facebook / thuê một reel mới, đúng
     * cái mà cơ chế khoá theo sản phẩm sinh ra để tránh.
     */
    public function test_reads_ids_from_the_affiliate_voucher_landing_page(): void
    {
        $ids = $this->validator()->extractShopeeIds(
            'https://shopee.vn/opaanlp/1541796848/27236640960?credential_token=abc&mmp_pid=an_1'
        );

        $this->assertSame(['shop_id' => '1541796848', 'item_id' => '27236640960'], $ids);
    }

    /** Hai dạng vốn có phải giữ nguyên. */
    public function test_still_reads_the_two_original_shapes(): void
    {
        $this->assertSame(
            ['shop_id' => '564687320', 'item_id' => '29261186260'],
            $this->validator()->extractShopeeIds('https://shopee.vn/Ao-Hoodie-i.564687320.29261186260'),
        );

        $this->assertSame(
            ['shop_id' => '1', 'item_id' => '2'],
            $this->validator()->extractShopeeIds('https://shopee.vn/product/1/2'),
        );
    }

    /**
     * App Shopee phát ra link trên cả shope.ee. Thiếu domain này thì khách dán đúng thứ mà giao
     * diện admin bảo họ dán lại bị chặn ngay từ cửa với "Chỉ hỗ trợ link sản phẩm Shopee".
     */
    public function test_accepts_every_domain_the_shopee_app_shares(): void
    {
        foreach ([
            'https://shopee.vn/Ao-Hoodie-i.1.2',
            'https://s.shopee.vn/abc',
            'https://vn.shp.ee/abc',
            'https://shope.ee/abc',
        ] as $url) {
            $this->validator()->validateShopeeOnly($url);
        }

        // validateShopeeOnly() không trả về gì; không ném exception nào là đã đạt.
        $this->addToAssertionCount(1);
    }

    /** Nới domain đầu vào không được biến nó thành cái cổng mở toang. */
    public function test_still_rejects_links_that_are_not_shopee(): void
    {
        $this->expectException(AffiliateScanException::class);

        $this->validator()->validateShopeeOnly('https://shopee.vn.evil.com/product-i.1.2');
    }
}
