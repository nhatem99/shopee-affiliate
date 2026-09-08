<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Services\KieuShopeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * /voucher/shorten — nơi link của kieushopee được đổi mmp_pid, rút gọn, và (tuỳ cấu hình) bọc
 * thêm một vòng comment Facebook.
 *
 * Lớp comment Facebook vốn được xây trên luồng salesoc nhiều kênh mã; khi đổi sang kieushopee
 * (một link duy nhất) nó bị đổi chữ ký hàm và khoá cache, nên các test dưới đây khoá lại đúng
 * hành vi mong đợi: bật thì đi qua comment, tắt/thiếu cấu hình/Facebook lỗi thì luôn rơi về
 * short-link — không bao giờ được chặn đường mua hàng của khách.
 */
class VoucherShortenTest extends TestCase
{
    use RefreshDatabase;

    private const POST_ID = '111222_333444';

    // shopee.vn để AffiliateLinkRewriterService khỏi phải đi theo redirect thật qua mạng.
    private const VOUCHER_URL = 'https://shopee.vn/product-i.1.2?mmp_pid=kieushopee';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /** Phát một ref hợp lệ y như ShopeeVoucherController::maskVoucherLink() làm. */
    private function ref(string $url = self::VOUCHER_URL): string
    {
        $ref = str_repeat('a', 32);
        Cache::put("voucher_ref:{$ref}", $url, now()->addDay());

        return $ref;
    }

    private function enableCommentRedirect(array $meta = []): void
    {
        ApiConfig::create([
            'name' => 'Facebook',
            'endpoint' => 'https://graph.facebook.com',
            'platform' => 'facebook',
            'app_id' => '111222',
            'app_secret' => 'page-token',
            'is_active' => true,
            'meta' => array_replace([
                'comment_redirect_enabled' => true,
                'target_post_id' => self::POST_ID,
            ], $meta),
        ]);
    }

    /** URL của sản phẩm thứ $item — shop_id giữ nguyên, item_id đổi, y như hàng khác nhau trong cùng shop. */
    private function productUrl(int $item): string
    {
        return "https://shopee.vn/san-pham-i.1.{$item}?mmp_pid=kieushopee";
    }

    private function shorten(?string $ref = null, string $productName = 'Áo Hoodie Test'): TestResponse
    {
        return $this->postJson('/voucher/shorten', [
            'ref' => $ref ?? $this->ref(),
            'product_name' => $productName,
        ]);
    }

    /**
     * Graph API giả lập: trả comment id ghép từ chính bài được gọi, để test biết comment đã
     * rơi vào bài nào ({page}_{story}_999 y như Facebook thật trả về).
     */
    private function fakeGraphEchoingPostId(): void
    {
        Http::fake(function ($request) {
            preg_match('#/(\d+_\d+)/comments#', $request->url(), $matches);

            return Http::response([
                'id' => ($matches[1] ?? 'x_y').'_999',
                'permalink_url' => 'https://www.facebook.com/permalink',
            ]);
        });
    }

    /** Lấy phần "{page}/posts/{story}" trong URL trả về, tức bài đã nhận comment. */
    private function postPartOf(string $shortUrl): string
    {
        preg_match('#facebook\.com/(\d+/posts/\d+)#', $shortUrl, $matches);

        return $matches[1] ?? $shortUrl;
    }

    public function test_returns_own_short_link_when_comment_redirect_is_off(): void
    {
        Http::fake();

        $response = $this->shorten()->assertOk();

        $this->assertStringContainsString('/go/'.$response->json('code'), $response->json('short_url'));
        // Không có cấu hình Facebook thì tuyệt đối không được gọi Graph API.
        Http::assertNothingSent();
    }

    public function test_source_is_recorded_as_kieushopee(): void
    {
        Http::fake();

        $code = $this->shorten()->assertOk()->json('code');

        $this->assertDatabaseHas('short_links', [
            'code' => $code,
            'source' => KieuShopeeService::SOURCE,
        ]);
    }

    public function test_redirects_through_facebook_comment_when_enabled(): void
    {
        $this->enableCommentRedirect();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'id' => self::POST_ID.'_999',
            'permalink_url' => 'https://www.facebook.com/permalink',
        ])]);

        $shortUrl = $this->shorten()->assertOk()->json('short_url');

        // URL canonical /{page_id}/posts/{story_fbid} ghép từ Page ID trong cấu hình, không
        // dùng permalink_url gốc. Dạng /posts/ (thay cho story.php cũ) là dạng Facebook khai
        // báo trong universal link/app link nên điện thoại mới có cơ hội mở thẳng app.
        $this->assertStringContainsString('https://www.facebook.com/111222/posts/333444?', $shortUrl);

        // Graph API trả comment id đầy đủ dạng {page}_{story}_{comment} = 111222_333444_999;
        // phần số riêng của comment là ĐOẠN CUỐI. Ghép sai (còn tiền tố story) thì Facebook
        // bỏ qua param, link chỉ mở tới bài viết chứ không nhảy xuống đúng bình luận.
        $this->assertStringContainsString('comment_id=999', $shortUrl);
        $this->assertStringNotContainsString('comment_id=333444', $shortUrl);
    }

    /**
     * Nhiều bài trong nhóm: các sản phẩm KHÁC nhau phải rải ra các bài khác nhau, không dồn
     * hết vào một bài. Đây là điều kiện để nhiều khách bấm cùng lúc không làm một bài phình
     * comment (comment cần tìm bị đẩy xuống dưới "Xem thêm bình luận") và đỡ bị coi là spam.
     */
    public function test_spreads_comments_across_the_post_pool(): void
    {
        $this->enableCommentRedirect(['target_post_ids' => [
            '111222_333444',
            '111222_555666',
            '111222_777888',
        ]]);
        $this->fakeGraphEchoingPostId();

        $used = [];

        // Sản phẩm khác nhau nghĩa là URL khác nhau: sản phẩm được nhận diện theo shop_id/item_id
        // trong URL đích chứ không theo tên (xem ShortLinkController::productKey).
        foreach (['Áo Hoodie', 'Quần Jean', 'Giày Sneaker'] as $item => $product) {
            $used[] = $this->postPartOf(
                $this->shorten($this->ref($this->productUrl($item)), $product)->assertOk()->json('short_url')
            );
        }

        $this->assertCount(3, array_unique($used), 'Ba sản phẩm phải rơi vào ba bài khác nhau, thực tế: '.implode(', ', $used));
    }

    /**
     * Lượt bấm sau lấy comment từ cache thì phải ghép lại đúng BÀI đã đăng. Nếu quên lưu
     * post_id, URL sẽ trỏ tới bài đầu nhóm — mở ra không có comment đó.
     */
    public function test_cached_comment_keeps_pointing_at_the_post_it_was_posted_on(): void
    {
        $this->enableCommentRedirect(['target_post_ids' => [
            '111222_333444',
            '111222_555666',
            '111222_777888',
        ]]);
        $this->fakeGraphEchoingPostId();

        $first = $this->postPartOf($this->shorten()->assertOk()->json('short_url'));
        $second = $this->postPartOf($this->shorten()->assertOk()->json('short_url'));

        $this->assertSame($first, $second);
        // Chỉ đúng 1 comment được đăng cho cả 2 lượt bấm.
        Http::assertSentCount(1);
    }

    /**
     * Reel: link trả cho khách phải là /reel/{id}?comment_id=..., KHÔNG phải /{page}/posts/...
     * Đo trên máy thật thì chỉ link /reel/ mới bật được ứng dụng Facebook và nhảy tới đúng
     * reel; ghép sai dạng là mất luôn tác dụng đó.
     */
    public function test_reel_target_produces_a_reel_url(): void
    {
        $this->enableCommentRedirect(['target_post_ids' => ['https://www.facebook.com/reel/987654']]);
        Http::fake(['graph.facebook.com/*' => Http::response([
            'id' => '987654_555',
            'permalink_url' => 'https://www.facebook.com/permalink',
        ])]);

        $shortUrl = $this->shorten()->assertOk()->json('short_url');

        $this->assertSame('https://www.facebook.com/reel/987654?comment_id=555', $shortUrl);

        // Comment phải được đăng lên chính id của reel.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/987654/comments'));
    }

    /** Nhóm bài trộn cả reel lẫn bài thường thì mỗi loại vẫn ra đúng dạng URL của nó. */
    public function test_pool_can_mix_reels_and_normal_posts(): void
    {
        $this->enableCommentRedirect(['target_post_ids' => [
            'reel/987654',
            '111222_333444',
        ]]);
        $this->fakeGraphEchoingPostId();

        $urls = [];

        foreach (['Áo Hoodie', 'Quần Jean'] as $item => $product) {
            $urls[] = $this->shorten($this->ref($this->productUrl($item)), $product)->assertOk()->json('short_url');
        }

        sort($urls);

        $this->assertStringContainsString('/111222/posts/333444?comment_id=', $urls[0]);
        $this->assertStringContainsString('/reel/987654?comment_id=', $urls[1]);
    }

    /** Nhóm bài (số nhiều) được ưu tiên hơn cấu hình đời đầu target_post_id (số ít). */
    public function test_post_pool_takes_precedence_over_the_legacy_single_post_id(): void
    {
        $this->enableCommentRedirect(['target_post_ids' => ['111222_999000']]);
        $this->fakeGraphEchoingPostId();

        $shortUrl = $this->shorten()->assertOk()->json('short_url');

        $this->assertSame('111222/posts/999000', $this->postPartOf($shortUrl));
    }

    /** Cùng sản phẩm bấm nhiều lần trong 20 phút chỉ được đăng đúng 1 comment (chống spam). */
    public function test_reuses_the_same_comment_within_the_cooldown(): void
    {
        $this->enableCommentRedirect();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'id' => self::POST_ID.'_999',
            'permalink_url' => 'https://www.facebook.com/permalink',
        ])]);

        $first = $this->shorten()->json('short_url');
        $second = $this->shorten($this->ref())->json('short_url');

        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    /**
     * Trường hợp khó nhất, và cũng là trường hợp thật: hai lượt "lấy mã" của CÙNG một sản phẩm
     * ra hai link voucher khác nhau (nguồn cấp mã phát payload mới mỗi lần) nên short-link cũng
     * khác nhau, đồng thời không đọc được tên sản phẩm. Trước đây khoá nhận diện rơi về
     * short-code — khác nhau ở hai lượt — nên cùng một món hàng bị đăng hai comment.
     *
     * Không có tên, không có short-code ổn định thì chỉ còn id sản phẩm trong URL đích là bấu
     * víu được; đó chính là lý do productKey() đọc shop_id/item_id trước tiên.
     */
    public function test_same_product_with_a_new_voucher_payload_shares_one_comment(): void
    {
        $this->enableCommentRedirect();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'id' => self::POST_ID.'_999',
            'permalink_url' => 'https://www.facebook.com/permalink',
        ])]);

        $shortenPayload = fn (string $payload) => $this->postJson('/voucher/shorten', [
            'ref' => $this->ref("https://shopee.vn/san-pham-i.1.2?mmp_pid=kieushopee&payload={$payload}"),
        ])->assertOk()->json('short_url');

        $this->assertSame($shortenPayload('AAA'), $shortenPayload('BBB'));
        Http::assertSentCount(1);
    }

    /**
     * Cùng một URL sản phẩm là cùng một sản phẩm, kể cả khi hai lượt bấm đọc ra tên khác nhau
     * (nguồn cấp mã và Shopee không phải lúc nào cũng trả về cùng một chuỗi tên).
     */
    public function test_same_product_url_shares_one_comment_even_if_the_name_differs(): void
    {
        $this->enableCommentRedirect();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'id' => self::POST_ID.'_999',
            'permalink_url' => 'https://www.facebook.com/permalink',
        ])]);

        $first = $this->shorten($this->ref(), 'Áo Hoodie Nam')->json('short_url');
        $second = $this->shorten($this->ref(), 'AO HOODIE NAM - Hang Cong Ty')->json('short_url');

        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    /**
     * Bấm nhiều lần cùng một sản phẩm không được sinh mỗi lượt một hàng short_links: bảng sẽ
     * phình theo SỐ LƯỢT BẤM thay vì số sản phẩm, và short-code đổi mỗi lượt còn kéo theo hỏng
     * phần nhận diện sản phẩm phía Facebook.
     */
    public function test_reuses_one_short_link_row_for_repeated_clicks(): void
    {
        Http::fake();

        $first = $this->shorten()->assertOk()->json('code');
        $second = $this->shorten($this->ref())->assertOk()->json('code');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('short_links', 1);
    }

    /** Đích khác nhau thì vẫn phải là hai short-link riêng — dùng lại chỉ khi trỏ cùng một chỗ. */
    public function test_different_targets_still_get_their_own_short_link(): void
    {
        Http::fake();

        $this->shorten($this->ref($this->productUrl(1)));
        $this->shorten($this->ref($this->productUrl(2)));

        $this->assertDatabaseCount('short_links', 2);
    }

    public function test_falls_back_to_short_link_when_target_post_is_missing(): void
    {
        $this->enableCommentRedirect(['target_post_id' => null]);
        Http::fake();

        $response = $this->shorten()->assertOk();

        $this->assertStringContainsString('/go/'.$response->json('code'), $response->json('short_url'));
        Http::assertNothingSent();
    }

    /** Facebook lỗi thì khách vẫn phải mua được — rơi về short-link, không trả lỗi. */
    public function test_falls_back_to_short_link_when_facebook_fails(): void
    {
        $this->enableCommentRedirect();
        Http::fake(['graph.facebook.com/*' => Http::response('nope', 500)]);

        $response = $this->shorten()->assertOk();

        $this->assertStringContainsString('/go/'.$response->json('code'), $response->json('short_url'));
    }

    public function test_rejects_voucher_link_on_unknown_domain(): void
    {
        Http::fake();

        $this->shorten($this->ref('https://evil.example.com/x'))->assertStatus(422);
    }

    public function test_rejects_expired_ref(): void
    {
        Http::fake();

        $this->postJson('/voucher/shorten', ['ref' => str_repeat('z', 32)])->assertStatus(422);
    }
}
