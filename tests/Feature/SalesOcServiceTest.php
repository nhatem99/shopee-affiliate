<?php

namespace Tests\Feature;

use App\Services\SalesOcService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SalesOcServiceTest extends TestCase
{
    private const SHOPEE_URL = 'https://shopee.vn/Ao-Hoodie-i.564687320.29261186260';

    private const RELAY_URL = 'https://relay.test/salesoc';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.salesoc.relay_url' => self::RELAY_URL,
            'services.salesoc.relay_secret' => 'test-secret',
            'services.salesoc.proxy' => null,
        ]);
    }

    /** Response thật rút gọn của salesoc.vn (giữ đúng tên field họ dùng). */
    private function salesOcPayload(): array
    {
        return [
            'success' => true,
            'productName' => 'Áo Hoodie Zip Feeling Cinder',
            'imageUrl' => 'https://cf.shopee.vn/file/abc',
            'price' => '138.000đ',
            'facebookAffiliateUrls' => [
                ['url' => 'https://s.afp.ad/1p2TvOgEA', 'label' => 'Mã FB 22%'],
                ['url' => 'https://s.afp.ad/J57349thAEP', 'label' => 'Mã FB 20%'],
            ],
        ];
    }

    public function test_falls_back_to_direct_when_the_relay_is_blocked(): void
    {
        // Đúng kịch bản production 2026-09-04: relay ăn 403 HTML của nginx salesoc.
        Http::fake([
            self::RELAY_URL => Http::response('<html><head><title>403 Forbidden</title></head></html>', 403),
            'https://salesoc.vn/*' => Http::response($this->salesOcPayload()),
        ]);

        $result = app(SalesOcService::class)->fetchProductAndVoucherLabels(self::SHOPEE_URL);

        $this->assertSame('Áo Hoodie Zip Feeling Cinder', $result['product_name']);
        $this->assertSame('Mã FB 22%', $result['voucher_links']['facebook'][0]['label']);
        // 2 request: relay (hỏng) rồi direct (được) — không dừng lại ở lần hỏng đầu tiên.
        Http::assertSentCount(2);
    }

    public function test_uses_the_relay_first_when_it_works(): void
    {
        Http::fake([
            self::RELAY_URL => Http::response($this->salesOcPayload()),
            'https://salesoc.vn/*' => Http::response('không nên gọi tới đây', 500),
        ]);

        $result = app(SalesOcService::class)->fetchProductAndVoucherLabels(self::SHOPEE_URL);

        $this->assertSame('Áo Hoodie Zip Feeling Cinder', $result['product_name']);
        Http::assertSentCount(1);
    }

    public function test_returns_null_when_every_route_is_blocked(): void
    {
        Http::fake([
            self::RELAY_URL => Http::response('Forbidden', 403),
            'https://salesoc.vn/*' => Http::response('Forbidden', 403),
        ]);

        $this->assertNull(app(SalesOcService::class)->fetchProductAndVoucherLabels(self::SHOPEE_URL));
        Http::assertSentCount(2);
    }

    public function test_a_connection_error_on_one_route_does_not_stop_the_next(): void
    {
        Http::fake([
            self::RELAY_URL => fn () => throw new ConnectionException('timeout'),
            'https://salesoc.vn/*' => Http::response($this->salesOcPayload()),
        ]);

        $result = app(SalesOcService::class)->fetchProductAndVoucherLabels(self::SHOPEE_URL);

        $this->assertSame('Áo Hoodie Zip Feeling Cinder', $result['product_name']);
    }

    public function test_a_200_response_with_success_false_is_treated_as_failure(): void
    {
        Http::fake([
            self::RELAY_URL => Http::response(['success' => false, 'message' => 'Không tìm thấy sản phẩm']),
            'https://salesoc.vn/*' => Http::response(['success' => false]),
        ]);

        $this->assertNull(app(SalesOcService::class)->fetchProductAndVoucherLabels(self::SHOPEE_URL));
    }

    public function test_diagnose_reports_the_state_of_every_route(): void
    {
        Http::fake([
            self::RELAY_URL => Http::response('<html>403 Forbidden</html>', 403),
            'https://salesoc.vn/*' => Http::response($this->salesOcPayload()),
        ]);

        $results = app(SalesOcService::class)->diagnose(self::SHOPEE_URL);

        $this->assertSame(['relay:relay.test', 'direct'], array_column($results, 'route'));
        $this->assertFalse($results[0]['ok']);
        $this->assertSame(403, $results[0]['status']);
        $this->assertTrue($results[1]['ok']);
        $this->assertStringContainsString('2 mã giảm giá', $results[1]['detail']);
    }

    public function test_proxy_route_is_included_when_configured(): void
    {
        config(['services.salesoc.proxy' => 'http://proxy.test:8080']);

        Http::fake(['*' => Http::response('Forbidden', 403)]);

        $this->assertSame(
            ['relay:relay.test', 'proxy', 'direct'],
            array_column(app(SalesOcService::class)->diagnose(self::SHOPEE_URL), 'route'),
        );
    }

    public function test_several_relays_are_tried_in_the_order_they_are_configured(): void
    {
        config(['services.salesoc.relay_url' => 'https://a.test/relay, https://b.test/relay']);

        Http::fake([
            'https://a.test/*' => Http::response('<html>403 Forbidden</html>', 403),
            'https://b.test/*' => Http::response($this->salesOcPayload()),
            'https://salesoc.vn/*' => Http::response('không nên gọi tới đây', 500),
        ]);

        $result = app(SalesOcService::class)->fetchProductAndVoucherLabels(self::SHOPEE_URL);

        // Relay đầu chết, relay thứ hai gánh — không đụng tới direct (IP server vốn đã bị chặn).
        $this->assertSame('Áo Hoodie Zip Feeling Cinder', $result['product_name']);
        Http::assertSentCount(2);

        $this->assertSame(
            ['relay:a.test', 'relay:b.test', 'direct'],
            array_column(app(SalesOcService::class)->diagnose(self::SHOPEE_URL), 'route'),
        );
    }
}
