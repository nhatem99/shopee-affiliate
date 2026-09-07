<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Services\KieuShopeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Cờ `viaFacebookComment` quyết định câu chữ trên trang: bật thì trang hứa với khách rằng bấm
 * nút sẽ mở Facebook và phải bấm tiếp link trong bình luận.
 *
 * Điều kiện của nó BẮT BUỘC khớp với ShortLinkController::facebookCommentRedirectUrl() — bên
 * đó thiếu Page ID/token/bài viết là lặng lẽ rơi về Shopee. Lệch nhau thì trang hướng dẫn
 * khách đi tìm Facebook trong khi thực tế mở thẳng Shopee, hoặc ngược lại: khách bấm "Mua
 * ngay" xong thấy Facebook hiện ra thì tưởng bị lừa. Test này khoá hai bên lại với nhau.
 */
class VoucherResolveFlagsTest extends TestCase
{
    use RefreshDatabase;

    private const SHOPEE_URL = 'https://shopee.vn/Ao-Hoodie-i.564687320.29261186260';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::fake();

        // Không gọi ra kieushopee thật; chỉ cần request đi hết được controller.
        $mock = Mockery::mock(KieuShopeeService::class);
        $mock->shouldReceive('fetchProductAndVoucherLink')->andReturn([
            'voucher_link' => 'https://shopee.vn/product-i.1.2?mmp_pid=x',
            'shop_id' => '1',
            'item_id' => '2',
            'product' => null,
        ]);
        $this->app->instance(KieuShopeeService::class, $mock);
    }

    private function facebookConfig(array $meta, array $attributes = []): void
    {
        ApiConfig::create(array_replace([
            'name' => 'Facebook',
            'endpoint' => 'https://graph.facebook.com',
            'platform' => 'facebook',
            'app_id' => '111222',
            'app_secret' => 'page-token',
            'is_active' => true,
            'meta' => $meta,
        ], $attributes));
    }

    private function resolveAsAdmin(): TestResponse
    {
        // Công cụ chỉ mở cho mobile hoặc admin — dùng admin để khỏi phải giả UA điện thoại.
        return $this->actingAs($this->createAdmin())
            ->post('/voucher/resolve', ['url' => self::SHOPEE_URL]);
    }

    public function test_flag_is_false_when_facebook_is_not_configured(): void
    {
        $this->resolveAsAdmin()->assertOk()->assertInertia(
            fn ($page) => $page->where('viaFacebookComment', false)->where('autoRedirect', false)
        );
    }

    public function test_flag_is_true_when_comment_redirect_is_fully_configured(): void
    {
        $this->facebookConfig([
            'comment_redirect_enabled' => true,
            'target_post_id' => '111222_333444',
        ]);

        $this->resolveAsAdmin()->assertOk()->assertInertia(
            fn ($page) => $page->where('viaFacebookComment', true)
        );
    }

    /** Bật công tắc nhưng chưa chọn bài viết -> ShortLinkController rơi về Shopee, đừng hứa Facebook. */
    public function test_flag_is_false_when_target_post_is_missing(): void
    {
        $this->facebookConfig(['comment_redirect_enabled' => true]);

        $this->resolveAsAdmin()->assertOk()->assertInertia(
            fn ($page) => $page->where('viaFacebookComment', false)
        );
    }

    public function test_flag_is_false_when_config_is_inactive(): void
    {
        $this->facebookConfig(
            ['comment_redirect_enabled' => true, 'target_post_id' => '111222_333444'],
            ['is_active' => false],
        );

        $this->resolveAsAdmin()->assertOk()->assertInertia(
            fn ($page) => $page->where('viaFacebookComment', false)
        );
    }

    /** auto_source là tên cũ thời salesoc — cấu hình đang chạy không được mất tác dụng. */
    public function test_legacy_auto_source_still_turns_on_auto_redirect(): void
    {
        $this->facebookConfig([
            'comment_redirect_enabled' => true,
            'target_post_id' => '111222_333444',
            'auto_source' => 'facebook',
        ]);

        $this->resolveAsAdmin()->assertOk()->assertInertia(
            fn ($page) => $page->where('autoRedirect', true)
        );
    }
}
