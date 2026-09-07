<?php

namespace Tests\Feature;

use App\Services\KieuShopeeService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Điểm dễ vỡ nhất của nguồn này không phải HTTP mà là PARSING: response là React Flight
 * stream (nhiều dòng "<id>:<json>") chứ không phải JSON thuần, và `productInfo` do bên
 * họ quyết shape. Test bám vào đúng hai chỗ đó.
 */
class KieuShopeeServiceTest extends TestCase
{
    private const ENDPOINT = 'https://sansale.kieushopee.com/22';

    private const SHOPEE_URL = 'https://shopee.vn/Ao-Hoodie-i.564687320.29261186260';

    protected function setUp(): void
    {
        parent::setUp();

        // Cache dùng driver array trong test nhưng cùng một instance sống xuyên các test
        // trong 1 process — không flush thì test sau đọc lại kết quả của test trước.
        Cache::flush();

        config([
            'services.kieushopee.endpoint' => self::ENDPOINT,
            'services.kieushopee.next_action' => 'test-next-action',
            'services.kieushopee.tool_id' => 'test-tool-id',
        ]);
    }

    /**
     * Response thật rút gọn: dòng "0:" là khung của React, dữ liệu nằm ở dòng "1:".
     * productInfo ở đây dùng shape THÔ của Shopee (snake_case + giá micro + hash ảnh).
     */
    private function flightBody(array $overrides = []): string
    {
        $payload = array_replace([
            'success' => true,
            'results' => [
                ['link' => 'https://shope.ee/abc123', 'shopId' => '564687320', 'itemId' => '29261186260'],
            ],
            'productInfo' => [
                'itemid' => 29261186260,
                'name' => 'Áo Hoodie Nỉ Bông',
                'image' => 'vn-11134207-7r98o-abcdef',
                'price' => 15900000000,
                'price_before_discount' => 25000000000,
                'raw_discount' => 36,
                'sold' => 1200,
                'item_rating' => ['rating_star' => 4.75],
            ],
        ], $overrides);

        return '0:{"a":"$@1","f":"","b":"build"}'."\n".'1:'.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n";
    }

    private function fetch(): ?array
    {
        return app(KieuShopeeService::class)->fetchProductAndVoucherLink(self::SHOPEE_URL);
    }

    public function test_parses_link_ids_and_product_from_flight_stream(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->flightBody())]);

        $result = $this->fetch();

        $this->assertSame('https://shope.ee/abc123', $result['voucher_link']);
        $this->assertSame('564687320', $result['shop_id']);
        $this->assertSame('29261186260', $result['item_id']);
        $this->assertSame('Áo Hoodie Nỉ Bông', $result['product']['product_name']);
        // Hash ảnh phải được ghép thành URL CDN mới hiển thị được ở frontend.
        $this->assertSame('https://cf.shopee.vn/file/vn-11134207-7r98o-abcdef', $result['product']['product_image']);
        // Giá micro của Shopee (×100.000) phải quy về VND, nếu không sẽ hiện ₫15.900.000.000.
        $this->assertSame(159000.0, $result['product']['discounted_price']);
        $this->assertSame(250000.0, $result['product']['original_price']);
        $this->assertSame(36, $result['product']['discount_percent']);
        $this->assertSame(1200, $result['product']['sold_count']);
        $this->assertSame(4.75, $result['product']['rating']);
    }

    public function test_sends_server_action_headers_and_multipart_fields(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->flightBody())]);

        $this->fetch();

        Http::assertSent(function (Request $request) {
            $body = $request->body();

            return $request->url() === self::ENDPOINT
                && $request->hasHeader('Next-Action', 'test-next-action')
                && $request->hasHeader('Origin', 'https://sansale.kieushopee.com')
                && str_contains($body, self::SHOPEE_URL)
                && str_contains($body, 'test-tool-id')
                && str_contains($body, '["$K1"]');
        });
    }

    /** productInfo shape khác (camelCase, giá đã format) vẫn phải đọc được. */
    public function test_parses_normalised_product_shape(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->flightBody([
            'productInfo' => [
                'productName' => 'Bình Giữ Nhiệt',
                'imageUrl' => 'https://down-vn.img.susercontent.com/file/xyz',
                'price' => '₫199.000',
            ],
        ]))]);

        $product = $this->fetch()['product'];

        $this->assertSame('Bình Giữ Nhiệt', $product['product_name']);
        $this->assertSame('https://down-vn.img.susercontent.com/file/xyz', $product['product_image']);
        // Chuỗi đã format thì chỉ lấy chữ số, KHÔNG chia 100.000 như giá micro.
        $this->assertSame(199000.0, $product['discounted_price']);
    }

    /**
     * productInfo lạ/rỗng không được làm hỏng cả lượt quét — link vẫn phải trả về, còn
     * product để null cho ShopeeVoucherController tự hỏi lại Shopee.
     */
    public function test_returns_link_with_null_product_when_product_info_unrecognised(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->flightBody(['productInfo' => ['foo' => 'bar']]))]);

        $result = $this->fetch();

        $this->assertSame('https://shope.ee/abc123', $result['voucher_link']);
        $this->assertNull($result['product']);
    }

    public function test_returns_null_when_success_is_false(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->flightBody(['success' => false, 'results' => []]))]);

        $this->assertNull($this->fetch());
    }

    public function test_returns_null_when_no_link_in_results(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->flightBody(['results' => []]))]);

        $this->assertNull($this->fetch());
    }

    /** next_action hết hạn sau khi họ deploy lại → server trả 404/500, không được vỡ. */
    public function test_returns_null_on_http_error(): void
    {
        Http::fake([self::ENDPOINT => Http::response('Not Found', 404)]);

        $this->assertNull($this->fetch());
    }

    /** Body không phải Flight stream (vd trang HTML chặn bot) cũng phải nuốt được. */
    public function test_returns_null_on_non_flight_body(): void
    {
        Http::fake([self::ENDPOINT => Http::response('<html><body>Forbidden</body></html>')]);

        $this->assertNull($this->fetch());
    }

    public function test_caches_result_so_same_url_is_fetched_once(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->flightBody())]);

        $this->fetch();
        $this->fetch();

        Http::assertSentCount(1);
    }

    public function test_bypasses_cache_when_asked(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->flightBody())]);

        $service = app(KieuShopeeService::class);
        $service->fetchProductAndVoucherLink(self::SHOPEE_URL, useCache: false);
        $service->fetchProductAndVoucherLink(self::SHOPEE_URL, useCache: false);

        Http::assertSentCount(2);
    }
}
