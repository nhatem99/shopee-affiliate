<?php

namespace Tests\Feature;

use App\Services\AffiliateLinkRewriterService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Đây là chỗ quyết định đơn hàng tính hoa hồng cho ai, nên hai thứ phải được khoá lại:
 *  1. mmp_pid luôn bị đổi sang affiliate ID của mình;
 *  2. URL cuối gửi tới Shopee KHÔNG chứa bất kỳ định danh nào của website mình.
 */
class AffiliateLinkRewriterTest extends TestCase
{
    private const MY_PID = 'an_17332410386';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopee_affiliate.mmp_pid' => self::MY_PID]);
    }

    private function rewrite(string $url): string
    {
        return app(AffiliateLinkRewriterService::class)->rewriteToOwnAffiliate($url);
    }

    public function test_swaps_mmp_pid_to_our_own_affiliate_id(): void
    {
        Http::fake();

        $result = $this->rewrite('https://shopee.vn/product-i.1.2?mmp_pid=kieushopee_fb&utm_source=kieushopee_fb');

        parse_str(parse_url($result, PHP_URL_QUERY), $query);

        $this->assertSame(self::MY_PID, $query['mmp_pid']);
        $this->assertSame(self::MY_PID, $query['utm_source']);
    }

    /** Không được để lộ tên website mình cho Shopee qua tham số URL. */
    public function test_final_url_carries_no_identifier_of_our_site(): void
    {
        Http::fake();

        $result = $this->rewrite('https://shopee.vn/product-i.1.2?mmp_pid=x&utm_content=kieushopee&utm_source=x');

        $this->assertStringNotContainsString('tietkiemvi', $result);
        // Xoá hẳn thay vì giữ giá trị của nguồn cấp mã — đó cũng là định danh không phải của mình.
        $this->assertStringNotContainsString('utm_content', $result);
    }

    /** Payload đã ký (nơi chứa mã giảm giá) phải đi qua nguyên vẹn. */
    public function test_keeps_the_signed_voucher_payload_untouched(): void
    {
        Http::fake();

        $result = $this->rewrite('https://shopee.vn/product-i.1.2?mmp_pid=x&encrypted_payload=ABC123&credential_token=TOK456');

        parse_str(parse_url($result, PHP_URL_QUERY), $query);

        $this->assertSame('ABC123', $query['encrypted_payload']);
        $this->assertSame('TOK456', $query['credential_token']);
    }

    /** Link lạ (không nằm trong chuỗi redirect biết trước) thì trả nguyên, không sửa mù. */
    public function test_leaves_unknown_domains_alone(): void
    {
        Http::fake();

        $url = 'https://example.com/whatever';

        $this->assertSame($url, $this->rewrite($url));
    }

    /** Đi theo chuỗi redirect s.afp.ad -> shopee.vn rồi mới đổi mmp_pid. */
    public function test_follows_the_redirect_chain_before_swapping(): void
    {
        Http::fake([
            'https://s.afp.ad/*' => Http::response('', 302, [
                'Location' => 'https://shopee.vn/product-i.1.2?mmp_pid=kieushopee_fb&utm_content=kieushopee',
            ]),
        ]);

        $result = $this->rewrite('https://s.afp.ad/neY9ZSQImwTHq9zSrnyT2g');

        $this->assertStringStartsWith('https://shopee.vn/product-i.1.2?', $result);
        $this->assertStringContainsString('mmp_pid='.self::MY_PID, $result);
        $this->assertStringNotContainsString('utm_content', $result);
    }
}
