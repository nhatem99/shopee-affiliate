<?php

namespace Tests\Feature;

use App\Services\ChannelVoucher\ChannelVoucherLinkBuilder;
use App\Services\ChannelVoucher\ChannelVoucherMinter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannelVoucherTest extends TestCase
{
    private const SHOPEE_URL = 'https://shopee.vn/Ao-Hoodie-i.973033125.26480867602';

    private const POST_ID = '111_222';

    private const COMMENT_ID = '111_333';

    private const PERMALINK = 'https://www.facebook.com/nguoiban/posts/222?comment_id=333';

    /** Link Shopee ĐÃ đúc mã — rút gọn từ link thật, giữ đúng các tham số quyết định. */
    private const MINTED_URL = 'https://shopee.vn/product/973033125/26480867602?channel_type=fb&credential_token=8wEwiDL7YDtwoNqUv79PdX&encrypted_payload=0XB0zjexzPblubO0ff9f6x&mmp_pid=an_KOL&utm_source=an_KOL';

    /** Cùng sản phẩm nhưng KHÔNG có chữ ký — tức là chuỗi chạy xong mà không ra mã nào. */
    private const UNSIGNED_URL = 'https://shopee.vn/product/973033125/26480867602?mmp_pid=an_KOL&utm_source=an_KOL';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.channel_voucher.enabled' => true,
            'services.channel_voucher.channels' => ['fb'],
            'services.channel_voucher.delete_comment_after' => true,
            'services.facebook.post_id' => self::POST_ID,
            'services.facebook.page_access_token' => 'test-page-token',
            'services.browser_resolver.url' => 'http://resolver.test',
            'services.browser_resolver.secret' => 'test-secret',
            'services.shopee_affiliate.kol_pid' => 'an_KOL',
            'services.shopee_affiliate.sub_id' => 'tietkiemvi',
        ]);
    }

    /**
     * @param  string  $finalUrl  URL mà trình duyệt "thu được" sau khi bấm link
     */
    private function fakeChain(string $finalUrl = self::MINTED_URL, bool $browserFails = false): void
    {
        Http::fake(function (Request $request) use ($finalUrl, $browserFails) {
            $url = $request->url();

            if (str_contains($url, '/resolve')) {
                return $browserFails
                    ? Http::response(['error' => 'Không thấy link chứa marker'], 422)
                    : Http::response(['final_url' => $finalUrl, 'matched_href' => 'https://l.facebook.com/l.php', 'duration_ms' => 4200]);
            }

            if (str_contains($url, '/comments')) {
                return Http::response(['id' => self::COMMENT_ID]);
            }

            if (str_contains($url, self::COMMENT_ID)) {
                return $request->method() === 'DELETE'
                    ? Http::response(['success' => true])
                    : Http::response(['id' => self::COMMENT_ID, 'message' => 'x', 'permalink_url' => self::PERMALINK]);
            }

            return Http::response(['error' => ['message' => 'không nên gọi tới đây']], 500);
        });
    }

    public function test_builds_a_plain_kol_link_carrying_the_channel_and_marker(): void
    {
        $url = app(ChannelVoucherLinkBuilder::class)->build(self::SHOPEE_URL, 'fb', 'tkvabc123456');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        // Dạng /product/{shop_id}/{item_id} — đúng dạng Shopee dùng cho link affiliate.
        $this->assertStringStartsWith('https://shopee.vn/product/973033125/26480867602?', $url);
        $this->assertSame('fb', $query['channel_type']);
        $this->assertSame('an_KOL', $query['mmp_pid']);
        $this->assertSame('an_KOL', $query['utm_source']);
        $this->assertSame('tietkiemvi', $query['utm_content']);
        // marker phải đi theo link, nếu không trình duyệt không nhận ra link nào là của mình.
        $this->assertSame('tkvabc123456', $query['utm_term']);
        // Link đem đi đăng CHƯA có mã — chữ ký là thứ chỉ Shopee cấp được.
        $this->assertArrayNotHasKey('credential_token', $query);
    }

    public function test_builds_the_same_product_url_from_the_affiliate_link_form(): void
    {
        $url = app(ChannelVoucherLinkBuilder::class)->build(
            'https://shopee.vn/product/973033125/26480867602?mmp_pid=an_NGUOI_KHAC',
            'ig',
            'tkvxyz000000',
        );

        $this->assertStringStartsWith('https://shopee.vn/product/973033125/26480867602?', $url);
        // Tham số affiliate của người khác trong link gốc phải bị bỏ, không được mang theo.
        $this->assertStringNotContainsString('an_NGUOI_KHAC', $url);
    }

    public function test_mints_a_voucher_link_through_the_full_chain(): void
    {
        $this->fakeChain();

        $option = app(ChannelVoucherMinter::class)->mintChannel(self::SHOPEE_URL, 'fb');

        $this->assertSame('Mã FB', $option['label']);
        $this->assertSame(self::MINTED_URL, $option['url']);
    }

    public function test_a_final_url_without_a_shopee_signature_counts_as_failure(): void
    {
        // Đây là kịch bản đáng sợ nhất: mọi bước đều "thành công" nhưng link thu về không có mã.
        // Trả link đó cho khách nghĩa là nút "Mã FB" bấm vào chẳng giảm đồng nào.
        $this->fakeChain(self::UNSIGNED_URL);

        $this->assertNull(app(ChannelVoucherMinter::class)->mintChannel(self::SHOPEE_URL, 'fb'));
    }

    public function test_the_comment_is_deleted_even_when_the_browser_step_fails(): void
    {
        $this->fakeChain(browserFails: true);

        $this->assertNull(app(ChannelVoucherMinter::class)->mintChannel(self::SHOPEE_URL, 'fb'));

        // Hỏng mà không dọn thì bài đăng vẫn đầy rác y như lúc chạy đúng, chỉ khác là không ai
        // để ý cho tới khi Facebook khoá Page.
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), self::COMMENT_ID));
    }

    public function test_run_reports_which_link_in_the_chain_broke(): void
    {
        $this->fakeChain(browserFails: true);

        $steps = app(ChannelVoucherMinter::class)->run(self::SHOPEE_URL, 'fb');

        $this->assertSame(
            ['dựng link KOL', 'comment lên fb', 'lấy permalink', 'mở bằng trình duyệt'],
            array_column($steps, 'step'),
        );
        $this->assertTrue($steps[2]['ok']);
        $this->assertFalse($steps[3]['ok']);
    }

    public function test_missing_configuration_fails_before_touching_facebook(): void
    {
        config(['services.facebook.page_access_token' => '']);
        Http::fake();

        $steps = app(ChannelVoucherMinter::class)->run(self::SHOPEE_URL, 'fb');

        $this->assertSame('cấu hình', $steps[0]['step']);
        $this->assertFalse($steps[0]['ok']);
        Http::assertNothingSent();
    }

    public function test_minting_is_skipped_entirely_when_the_feature_is_off(): void
    {
        config(['services.channel_voucher.enabled' => false]);

        $this->assertFalse(app(ChannelVoucherMinter::class)->isEnabled());
    }
}
