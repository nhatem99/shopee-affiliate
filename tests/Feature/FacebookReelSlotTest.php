<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Services\FacebookReelSlotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Chế độ đổi caption reel: mỗi sản phẩm thuê một reel, caption reel đổi thành link sản phẩm,
 * khách được đưa tới đúng reel đó.
 *
 * Điều kiện sống còn của chế độ này là KHÔNG BAO GIỜ đưa khách tới một reel đang hiện link của
 * sản phẩm khác — bấm nhầm là mua nhầm hàng. Phần lớn test dưới đây khoá đúng ràng buộc đó.
 */
class FacebookReelSlotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function config(array $reels, int $leaseMinutes = 10): ApiConfig
    {
        return ApiConfig::create([
            'name' => 'Facebook',
            'endpoint' => 'https://graph.facebook.com',
            'platform' => 'facebook',
            'app_id' => '111222',
            'app_secret' => 'page-token',
            'is_active' => true,
            'meta' => [
                'comment_redirect_enabled' => true,
                'reel_caption_enabled' => true,
                'target_reel_ids' => $reels,
                'reel_lease_minutes' => $leaseMinutes,
            ],
        ]);
    }

    private function service(array $reels, int $leaseMinutes = 10): FacebookReelSlotService
    {
        return new FacebookReelSlotService($this->config($reels, $leaseMinutes));
    }

    public function test_leases_a_reel_and_writes_the_link_into_its_caption(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        $url = $this->service(['https://www.facebook.com/reel/111'])
            ->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/abc');

        $this->assertSame('https://www.facebook.com/reel/111', $url);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/111')
            && str_contains($request['description'], 'https://tietkiemvi.com/go/abc')
            && str_contains($request['description'], 'Áo Hoodie'));
    }

    /** Hai sản phẩm khác nhau phải nằm trên hai reel khác nhau, không được đè caption của nhau. */
    public function test_different_products_get_different_reels(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $service = $this->service(['reel/111', 'reel/222']);

        $first = $service->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/a');
        $second = $service->reelUrlFor('quan-jean', 'Quần Jean', 'https://tietkiemvi.com/go/b');

        $this->assertNotSame($first, $second);
    }

    /** Cùng sản phẩm bấm lại thì dùng lại reel cũ, không tốn thêm lời gọi API. */
    public function test_same_product_reuses_its_reel(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $service = $this->service(['reel/111', 'reel/222']);

        $first = $service->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/a');
        $second = $service->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/a');

        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    /**
     * Hết reel trống thì phải trả null để người gọi đưa khách đi thẳng Shopee. Tuyệt đối không
     * được tái sử dụng reel đang thuộc về sản phẩm khác.
     */
    public function test_returns_null_when_every_reel_is_taken(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $service = $this->service(['reel/111']);

        $service->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/a');
        $overflow = $service->reelUrlFor('quan-jean', 'Quần Jean', 'https://tietkiemvi.com/go/b');

        $this->assertNull($overflow);
    }

    /** Hết hạn thuê thì reel được thả ra cho sản phẩm sau dùng. */
    public function test_reel_is_released_after_the_lease_expires(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $service = $this->service(['reel/111'], leaseMinutes: 5);

        $service->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/a');

        $this->travel(6)->minutes();

        $this->assertSame(
            'https://www.facebook.com/reel/111',
            $service->reelUrlFor('quan-jean', 'Quần Jean', 'https://tietkiemvi.com/go/b'),
        );
    }

    /**
     * Graph API từ chối đổi caption thì phải TRẢ SLOT lại rồi thử reel kế tiếp — nếu giữ slot,
     * reel đó bị khoá vô ích suốt thời gian thuê dù caption chưa hề đổi.
     */
    public function test_failed_caption_update_frees_the_slot_and_tries_the_next_reel(): void
    {
        Http::fake([
            'graph.facebook.com/*/111' => Http::response(['error' => ['message' => 'nope']], 400),
            'graph.facebook.com/*' => Http::response(['success' => true]),
        ]);

        $service = $this->service(['reel/111', 'reel/222']);

        $this->assertSame(
            'https://www.facebook.com/reel/222',
            $service->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/a'),
        );
        // reel/111 phải được thả ra, không bị treo vì lần hỏng vừa rồi.
        $this->assertNull(Cache::get('fb_reel_lease:reel/111'));
    }

    public function test_does_nothing_when_no_reel_is_configured(): void
    {
        Http::fake();

        $this->assertNull(
            $this->service([])->reelUrlFor('ao-hoodie', 'Áo Hoodie', 'https://tietkiemvi.com/go/a'),
        );
        Http::assertNothingSent();
    }
}
