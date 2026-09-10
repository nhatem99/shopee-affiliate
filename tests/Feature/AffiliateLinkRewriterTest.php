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

    private function rewrite(string $url, ?string $userSubId = null): string
    {
        return app(AffiliateLinkRewriterService::class)->rewriteToOwnAffiliate($url, $userSubId);
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
     * Link an_redir (nguồn mã YouTube ganma.vn) PHẢI được đi theo redirect, y như link
     * kieushopee — không được sửa affiliate_id tại chỗ.
     *
     * Đo thật 08-09-2026, lặp 3 lần: affiliate_id quyết định trang đích.
     *   affiliate_id của ganma → shopee.vn/opaanlp/... (trang landing ÁP MÃ độc quyền)
     *   affiliate_id của mình  → shopee.vn/product/... (trang thường, KHÔNG có mã)
     * Tài khoản affiliate của mình không nằm trong chương trình đó, nên bắt Shopee cấp lại
     * token cho mình = mất mã của khách. Cách đúng là giữ nguyên credential_token + trang
     * /opaanlp/ của ganma (mã), chỉ đổi mmp_pid (hoa hồng) — đúng cơ chế kieushopee đang chạy.
     */
    private function fakeAnRedirRedirect(string $utmContent = 'YT3-token'): void
    {
        Http::fake([
            's.shopee.vn/*' => Http::response('', 301, [
                'Location' => 'https://shopee.vn/opaanlp/1541796848/27236640960?__mobile__=1'
                    .'&credential_token=TOKEN-CUA-GANMA&exp_group=rollout&gads_t_sig=CHUKY'
                    .'&mmp_pid=an_17104820001&utm_content='.$utmContent
                    .'&utm_medium=affiliates&utm_source=an_17104820001',
            ]),
        ]);
    }

    private function anRedir(): string
    {
        $origin = rawurlencode('https://shopee.vn/product/1541796848/27236640960?gads_t_sig=CHUKY');

        return "https://s.shopee.vn/an_redir?affiliate_id=17104820001&origin_link={$origin}&sub_id=YT3-token";
    }

    public function test_an_redir_keeps_the_voucher_page_and_token_of_the_source(): void
    {
        $this->fakeAnRedirRedirect();

        $result = $this->rewrite($this->anRedir());

        // Mất một trong hai thứ này là mất mã giảm giá của khách.
        $this->assertStringContainsString('/opaanlp/', $result);
        $this->assertStringContainsString('credential_token=TOKEN-CUA-GANMA', $result);
        $this->assertStringContainsString('exp_group=rollout', $result);
    }

    public function test_an_redir_still_moves_the_commission_to_us(): void
    {
        $this->fakeAnRedirRedirect();

        parse_str(parse_url($this->rewrite($this->anRedir()), PHP_URL_QUERY), $query);

        $this->assertSame(self::MY_PID, $query['mmp_pid']);
        $this->assertSame(self::MY_PID, $query['utm_source']);
        $this->assertStringNotContainsString('17104820001', http_build_query($query));
    }

    /** Traffic YouTube phải tách khỏi cột Sub_id 'fb', nếu không báo cáo gộp chung hai kênh. */
    public function test_youtube_channel_gets_its_own_sub_id(): void
    {
        $this->fakeAnRedirRedirect();

        parse_str(parse_url($this->rewrite($this->anRedir()), PHP_URL_QUERY), $query);

        $this->assertSame('YT', $query['utm_content']);
    }

    /** Nhãn YouTube cũng đổi được bằng config như nhãn fb/IG. */
    public function test_youtube_label_comes_from_config(): void
    {
        $this->fakeAnRedirRedirect();
        config(['services.shopee_affiliate.utm_content_yt' => 'ytb9']);

        parse_str(parse_url($this->rewrite($this->anRedir()), PHP_URL_QUERY), $query);

        $this->assertSame('ytb9', $query['utm_content']);
    }

    /** Tên nhóm chỉ tình cờ chứa "yt" không được tính là kênh YouTube. */
    public function test_group_name_merely_containing_yt_is_not_treated_as_youtube(): void
    {
        $this->fakeAnRedirRedirect('Myth-Skyt---');

        parse_str(parse_url($this->rewrite($this->anRedir()), PHP_URL_QUERY), $query);

        $this->assertSame('fb', $query['utm_content']);
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
    // ── Mã sub_id của khách (nền móng hoàn tiền) ──────────────────────────────

    /**
     * Mã khách phải nằm ở KHE 2, nhãn kênh giữ nguyên khe 1. Đây là hợp đồng mà khâu đọc báo
     * cáo Shopee sẽ dựa vào để biết đơn hàng thuộc về ai — đổi vị trí là hoàn tiền sai người.
     */
    public function test_logged_in_user_sub_id_is_placed_in_the_second_slot(): void
    {
        Http::fake();

        $result = $this->rewrite('https://shopee.vn/product-i.1.2?mmp_pid=x&utm_content=kieushopee', 'u7k2m9');

        parse_str(parse_url($result, PHP_URL_QUERY), $query);

        $this->assertSame('fb-u7k2m9---', $query['utm_content']);
        $this->assertSame('u7k2m9', explode('-', $query['utm_content'])[1]);
    }

    /** Khách vãng lai giữ nguyên hành vi cũ: chỉ nhãn kênh, không thêm khe trống nào. */
    public function test_guest_link_keeps_the_bare_channel_label(): void
    {
        Http::fake();

        $result = $this->rewrite('https://shopee.vn/product-i.1.2?mmp_pid=x&utm_content=kieushopee');

        parse_str(parse_url($result, PHP_URL_QUERY), $query);

        $this->assertSame('fb', $query['utm_content']);
    }

    /**
     * Hai thứ phải cùng sống: nhãn kênh (để tách IG/YT khỏi FB trong báo cáo) và mã khách (để
     * hoàn tiền). Thêm cái sau mà mất cái trước là hỏng chức năng vừa xây xong.
     */
    public function test_channel_label_and_user_sub_id_coexist(): void
    {
        Http::fake();

        $result = $this->rewrite('https://shopee.vn/product-i.1.2?mmp_pid=x&utm_content=IG-sansale---', 'u7k2m9');

        parse_str(parse_url($result, PHP_URL_QUERY), $query);

        $this->assertSame('IG-u7k2m9---', $query['utm_content']);
    }

    /**
     * Nhãn kênh rỗng vốn nghĩa là "xoá hẳn utm_content". Có mã khách thì KHÔNG được xoá nữa,
     * và mã vẫn phải ở đúng khe 2 — mất tham số này là khách đó vĩnh viễn không nhận được tiền.
     */
    public function test_user_sub_id_survives_an_empty_channel_label(): void
    {
        Http::fake();
        config(['services.shopee_affiliate.utm_content' => '']);

        $result = $this->rewrite('https://shopee.vn/product-i.1.2?mmp_pid=x&utm_content=kieushopee', 'u7k2m9');

        parse_str(parse_url($result, PHP_URL_QUERY), $query);

        $this->assertSame('-u7k2m9---', $query['utm_content']);
        $this->assertSame('u7k2m9', explode('-', $query['utm_content'])[1]);
    }

    /** Mã khách cũng phải đi được qua nhánh an_redir (nguồn ganma), không chỉ nhánh kieushopee. */
    public function test_user_sub_id_also_rides_the_an_redir_branch(): void
    {
        $this->fakeAnRedirRedirect();

        parse_str(parse_url($this->rewrite($this->anRedir(), 'u7k2m9'), PHP_URL_QUERY), $query);

        $this->assertSame('YT-u7k2m9---', $query['utm_content']);
    }

    /** Mã khách không được kéo theo định danh website — ràng buộc cũ vẫn phải đúng. */
    public function test_user_sub_id_does_not_leak_our_site_identity(): void
    {
        Http::fake();

        $result = $this->rewrite('https://shopee.vn/product-i.1.2?mmp_pid=x&utm_content=kieushopee', 'u7k2m9');

        $this->assertStringNotContainsString('tietkiemvi', $result);
        $this->assertStringNotContainsString('kieushopee', $result);
    }
}
