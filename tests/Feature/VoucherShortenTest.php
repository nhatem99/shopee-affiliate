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

    private function shorten(?string $ref = null): TestResponse
    {
        return $this->postJson('/voucher/shorten', [
            'ref' => $ref ?? $this->ref(),
            'product_name' => 'Áo Hoodie Test',
        ]);
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
