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
        // Giá trị của nguồn cấp mã cũng không được giữ lại — đó là định danh không phải của mình.
        $this->assertStringNotContainsString('kieushopee', $result);
        $this->assertStringContainsString('utm_content=fb', $result);
    }

    /** Đổi nhãn chỉ bằng config, không phải sửa code. */
    public function test_utm_content_label_comes_from_config(): void
    {
        Http::fake();
        config(['services.shopee_affiliate.utm_content' => 'ch7']);

        $result = $this->rewrite('https://shopee.vn/product-i.1.2?mmp_pid=x&utm_content=kieushopee');

        $this->assertStringContainsString('utm_content=ch7', $result);
    }

    /** Config để rỗng = xoá hẳn tham số, không gửi nhãn nào cho Shopee. */
    public function test_empty_config_removes_the_label_entirely(): void
    {
        Http::fake();
        config(['services.shopee_affiliate.utm_content' => '']);

        $result = $this->rewrite('https://shopee.vn/product-i.1.2?mmp_pid=x&utm_content=kieushopee');

        $this->assertStringNotContainsString('utm_content', $result);
    }

    /**
     * Mã lấy từ kênh IG phải mang nhãn riêng, nếu không thì trong báo cáo Shopee traffic IG
     * và FB dồn chung một cột Sub_id, không tách được kênh nào ra đơn.
     *
     * Chuỗi nguồn theo đúng shape đo được từ kieushopee: 5 khe nối bằng dấu "-".
     */
    public function test_ig_channel_code_gets_its_own_sub_id(): void
    {
        Http::fake();

        $result = $this->rewrite('https://shopee.vn/product-i.1.2?mmp_pid=x&utm_content=IG-sansale---');

        $this->assertStringContainsString('utm_content=IG', $result);
    }

    /** Nhãn kênh nằm ở khe nào cũng phải nhận ra, không chỉ khe đầu. */
    public function test_ig_marker_is_matched_in_any_slot(): void
    {
        Http::fake();

        $result = $this->rewrite('https://shopee.vn/product-i.1.2?mmp_pid=x&utm_content=sansale-instagram---');

        $this->assertStringContainsString('utm_content=IG', $result);
    }

    /**
     * Cốt lõi của việc so khớp theo khe: tên nhóm bình thường có chứa chữ "ig" KHÔNG được
     * tính là kênh IG, nếu không thì traffic FB bị dồn nhầm sang nhãn IG mà không ai biết.
     */
    public function test_group_name_merely_containing_ig_is_not_treated_as_instagram(): void
    {
        Http::fake();

        $result = $this->rewrite('https://shopee.vn/product-i.1.2?mmp_pid=x&utm_content=Signature-Big---');

        $this->assertStringContainsString('utm_content=fb', $result);
        $this->assertStringNotContainsString('utm_content=IG', $result);
    }

    /** Nhãn IG cũng phải đổi được bằng config; marker viết hoa hay thường đều khớp. */
    public function test_ig_label_and_markers_come_from_config(): void
    {
        Http::fake();
        config([
            'services.shopee_affiliate.utm_content_ig' => 'ch9',
            'services.shopee_affiliate.ig_markers' => ['Reels'],
        ]);

        $result = $this->rewrite('https://shopee.vn/product-i.1.2?mmp_pid=x&utm_content=reels-x---');

        $this->assertStringContainsString('utm_content=ch9', $result);
    }

    /**
     * Marker rỗng trong config không được biến thành "khớp mọi thứ": chuỗi nguồn luôn có khe
     * trống ("Test-22---"), nên nếu không lọc thì mọi link đều bị gắn nhãn IG — báo cáo Shopee
     * vẫn ra số nên lỗi này rất lâu mới lộ.
     */
    public function test_blank_marker_in_config_does_not_match_everything(): void
    {
        Http::fake();
        config(['services.shopee_affiliate.ig_markers' => ['', 'ig']]);

        $result = $this->rewrite('https://shopee.vn/product-i.1.2?mmp_pid=x&utm_content=Test-22---');

        $this->assertStringContainsString('utm_content=fb', $result);
    }

    /** Không tự thêm utm_content vào link vốn không có — tránh mở rộng thông tin gửi đi. */
    public function test_does_not_add_the_label_to_links_that_had_none(): void
    {
        Http::fake();

        $result = $this->rewrite('https://shopee.vn/product-i.1.2?mmp_pid=x');

        $this->assertStringNotContainsString('utm_content', $result);
    }

    /**
     * Link an_redir (nguồn mã YouTube ganma.vn) — shape khác hẳn kieushopee: affiliate nằm ở
     * `affiliate_id` dạng TRẦN chứ không phải `mmp_pid` có tiền tố 'an_'.
     *
     * Đo thật 08-09-2026: đổi affiliate_id thì chính Shopee cấp credential_token + mmp_pid +
     * utm_source cho tài khoản gửi lên. Không đổi được ở đây = hoa hồng về túi ganma.
     */
    private function anRedir(string $affiliateId = '17104820001', string $subId = 'YT3-abc'): string
    {
        $origin = rawurlencode('https://shopee.vn/product/1541796848/27236640960?gads_t_sig=CHUKY');

        return "https://s.shopee.vn/an_redir?affiliate_id={$affiliateId}&origin_link={$origin}&sub_id={$subId}";
    }

    public function test_an_redir_link_gets_our_bare_affiliate_id(): void
    {
        Http::fake();

        $result = $this->rewrite($this->anRedir());

        parse_str(parse_url($result, PHP_URL_QUERY), $query);

        // Dạng TRẦN: an_redir không dùng tiền tố 'an_' như mmp_pid, dù cùng một tài khoản.
        $this->assertSame('17332410386', $query['affiliate_id']);
        $this->assertStringNotContainsString('17104820001', $result);
    }

    /** sub_id của an_redir là cùng ô Sub_id mà utm_content ghi ở link kieushopee. */
    public function test_an_redir_sub_id_becomes_the_youtube_label(): void
    {
        Http::fake();

        $result = $this->rewrite($this->anRedir());

        parse_str(parse_url($result, PHP_URL_QUERY), $query);

        $this->assertSame('YT', $query['sub_id']);
        // Token của nguồn vừa là định danh không phải của mình, vừa gần như duy nhất mỗi job —
        // giữ lại thì cột Sub_id nở ra hàng nghìn dòng rác.
        $this->assertStringNotContainsString('YT3-abc', $result);
    }

    /**
     * origin_link mang gads_t_sig — tham số có dạng chữ ký, nhiều khả năng là nơi giữ ưu đãi.
     * Đụng vào nó là mất mã của khách.
     */
    public function test_an_redir_keeps_the_signed_origin_link_untouched(): void
    {
        Http::fake();

        $result = $this->rewrite($this->anRedir());

        parse_str(parse_url($result, PHP_URL_QUERY), $query);

        $this->assertSame(
            'https://shopee.vn/product/1541796848/27236640960?gads_t_sig=CHUKY',
            $query['origin_link'],
        );
    }

    /**
     * Cố ý KHÔNG đi theo redirect của an_redir. Đi theo nghĩa là server mình tự bấm link
     * affiliate của ganma: đốt một lượt click ghi cho ID của họ, từ IP datacenter — và nhận về
     * landing page Shopee cấp cho HỌ thay vì để Shopee cấp attribution cho mình.
     */
    public function test_an_redir_is_rewritten_in_place_without_any_http_call(): void
    {
        Http::fake();

        $this->rewrite($this->anRedir());

        Http::assertNothingSent();
    }

    /** Nhãn YouTube cũng đổi được bằng config như nhãn fb/IG. */
    public function test_youtube_label_comes_from_config(): void
    {
        Http::fake();
        config(['services.shopee_affiliate.utm_content_yt' => 'ytb2']);

        $result = $this->rewrite($this->anRedir());

        parse_str(parse_url($result, PHP_URL_QUERY), $query);

        $this->assertSame('ytb2', $query['sub_id']);
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
        // Nhãn của nguồn cấp mã bị thay bằng nhãn trong config, không đi tiếp tới Shopee.
        $this->assertStringNotContainsString('kieushopee', $result);
    }
}
