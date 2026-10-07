<?php

namespace Tests\Unit;

use App\Services\ShopeeLinkResolverService;
use App\Services\ShopeeProductLookupService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** data.addlivetag.com bắt buộc API Key từ 01-10-2026 — thiếu là 401, mất luôn tên/ảnh/giá sản phẩm. */
class ShopeeProductLookupServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.addlivetag.key' => 'test-key']);
        Http::fake([
            'data.addlivetag.com/*' => Http::response(['status' => 'success', 'productInfo' => ['productName' => 'Gối chữ U', 'price' => 145820]]),
            'shopee.vn/*' => Http::response([], 403),
        ]);
    }

    private function sentWithKey(): bool
    {
        return Http::recorded(fn (Request $request) => str_starts_with($request->url(), ShopeeProductLookupService::BASE_URL)
            && $request->header('X-API-Key') === ['test-key'])->isNotEmpty();
    }

    public function test_lookup_sends_api_key(): void
    {
        $product = app(ShopeeProductLookupService::class)->getByItemId('27345778106');

        $this->assertSame('Gối chữ U', $product['product_name']);
        $this->assertTrue($this->sentWithKey());
    }

    public function test_pooled_lookup_sends_api_key(): void
    {
        $product = app(ShopeeLinkResolverService::class)->fetchProductInfo('27345778106', '1703926009');

        $this->assertSame('Gối chữ U', $product['product_name']);
        $this->assertTrue($this->sentWithKey());
    }
}
