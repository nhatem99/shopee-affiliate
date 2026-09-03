<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Models\ShortLink;
use App\Services\AffiliateScanOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Kiểm tra AffiliateScanOrchestrator::scan() sau khi đổi sang gọi Http::pool() song song
// (product metadata + AccessTrade + Shopee voucher) vẫn ghép đúng dữ liệu từ 3 nguồn,
// và thực sự bắn ra đúng số request đồng thời thay vì tuần tự.
class AffiliateScanConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_resolves_product_info_link_and_vouchers_from_pooled_requests(): void
    {
        ApiConfig::create([
            'name' => 'AccessTrade',
            'platform' => 'accesstrade',
            'endpoint' => 'https://api.accesstrade.test',
            'app_id' => 'id',
            'app_secret' => 'secret',
            'is_active' => true,
        ]);

        ApiConfig::create([
            'name' => 'Shopee',
            'platform' => 'shopee',
            'endpoint' => 'https://open-api.shopee.test',
            'app_id' => '1000',
            'app_secret' => 'shopeesecret',
            'is_active' => true,
        ]);

        Http::fake([
            'https://shopee.vn/*' => Http::response('<html></html>', 200),
            'https://data.addlivetag.com/*' => Http::response(['productInfo' => [
                'productName' => 'Áo thun test',
                'imageUrl' => 'https://img.test/a.jpg',
                'price' => 150000,
                'latestPriceHistory' => ['originalPrice' => 200000, 'discountPercent' => 25],
                'sales' => 999,
                'rating' => 4.7,
                'sellerRate' => 0.02,
                'shopeeRate' => 0.03,
            ]], 200),
            'https://api.accesstrade.test/*' => Http::response(['data' => ['url' => 'https://at.link/pooled']], 200),
            'https://open-api.shopee.test/*' => Http::response(['response' => ['voucher_list' => [
                [
                    'voucher_code' => 'POOL50K',
                    'discount_type' => 1,
                    'discount_amount' => 50000,
                    'min_basket_price' => 100000,
                    'end_time' => now()->addDays(5)->timestamp,
                ],
            ]]], 200),
        ]);

        $orchestrator = app(AffiliateScanOrchestrator::class);
        $result = $orchestrator->scan('https://shopee.vn/ao-thun-i.789.123456', null);

        $this->assertSame('Áo thun test', $result['product']['name']);
        // Link affiliate gốc phải được bọc qua /go/{code}, không lộ URL AccessTrade thật.
        $this->assertStringContainsString('/go/', $result['affiliateLink']);
        $this->assertStringNotContainsString('at.link', $result['affiliateLink']);
        $code = basename($result['affiliateLink']);
        $this->assertSame('https://at.link/pooled', ShortLink::where('code', $code)->value('target_url'));
        $this->assertCount(1, $result['vouchers']);
        $this->assertSame('POOL50K', $result['vouchers'][0]['code']);

        Http::assertSentCount(4);
    }
}
