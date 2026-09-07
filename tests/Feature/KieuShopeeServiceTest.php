<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Services\KieuShopeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    use RefreshDatabase;

    private const ENDPOINT = 'https://sansale.kieushopee.com/22';

    private const SHOPEE_URL = 'https://shopee.vn/Ao-Hoodie-i.564687320.29261186260';

    protected function setUp(): void
    {
        parent::setUp();

        // Cache dùng driver array trong test nhưng cùng một instance sống xuyên các test
        // trong 1 process — không flush thì test sau đọc lại kết quả của test trước.
        Cache::flush();

        // Migration tạo sẵn bản ghi kieushopee với giá trị production; xoá đi để phần lớn
        // test chạy trên nhánh "chưa cấu hình ở admin" → rơi về config/services.php.
        // Hai test cuối tự dựng lại bản ghi để kiểm tra nhánh đọc từ DB.
        ApiConfig::where('platform', KieuShopeeService::SOURCE)->delete();

        config([
            'services.kieushopee.endpoint' => self::ENDPOINT,
            'services.kieushopee.next_action' => 'test-next-action',
            'services.kieushopee.tool_id' => 'test-tool-id',
            'services.kieushopee.action_payload' => '["$K1"]',
        ]);
    }

    private function storeAdminParams(array $meta, bool $isActive = true): void
    {
        ApiConfig::create([
            'name' => 'KieuShopee',
            'endpoint' => self::ENDPOINT,
            'platform' => KieuShopeeService::SOURCE,
            'app_secret' => '',
            'is_active' => $isActive,
            'meta' => $meta,
        ]);
    }

    /**
     * Payload THẬT, chép nguyên từ một lần gọi trên server production ngày 2026-09-07
     * (workflow "Probe voucher source from VPS"). Giữ đúng từng key/kiểu dữ liệu của họ —
     * đây là hợp đồng duy nhất mình có với nguồn này, đoán sai là hỏng trên production.
     */
    private function flightBody(array $overrides = []): string
    {
        $payload = array_replace([
            'success' => true,
            'results' => [
                [
                    'groupName' => '',
                    'link' => 'https://s.afp.ad/neY9ZSQImwTHq9zSrnyT2g',
                    'shortUrl' => null,
                    'affiliateId' => '17354810621',
                    'shopId' => '564687320',
                    'itemId' => '29261186260',
                ],
            ],
            'cached' => false,
            'productInfo' => [
                'itemId' => '29261186260',
                'shopId' => '564687320',
                'name' => 'Áo Hoodie Zip Feeling Cinder, Áo Khoác Nam Nữ Form Rộng Local Brand Chính Hãng',
                'image' => 'https://down-vn.img.susercontent.com/file/vn-11134207-7r98o-m04gtmha7b3h23',
                'currency' => 'VND',
                'price' => 78000,
                'priceBeforeDiscount' => 200000,
                'priceMin' => 78000,
                'priceMax' => 78000,
                'priceMinBeforeDiscount' => 200000,
                'priceMaxBeforeDiscount' => 200000,
            ],
        ], $overrides);

        return '0:{"a":"$@1","f":"","q":"","i":false,"b":"yHOPvt6E6MFlOJ8qN5jHn"}'."\n"
            .'1:'.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n";
    }

    private function fetch(): ?array
    {
        return app(KieuShopeeService::class)->fetchProductAndVoucherLink(self::SHOPEE_URL);
    }

    public function test_parses_real_production_response(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->flightBody())]);

        $result = $this->fetch();

        // s.afp.ad phải nằm trong UrlValidationService::$allowedRedirectDomains và
        // AffiliateLinkRewriterService::HOPS_TO_FOLLOW, nếu không khách bấm mua sẽ ăn 422.
        $this->assertSame('https://s.afp.ad/neY9ZSQImwTHq9zSrnyT2g', $result['voucher_link']);
        $this->assertSame('564687320', $result['shop_id']);
        $this->assertSame('29261186260', $result['item_id']);
        $this->assertStringStartsWith('Áo Hoodie Zip Feeling Cinder', $result['product']['product_name']);
        // Ảnh đã là URL đầy đủ — KHÔNG được ghép thêm tiền tố CDN vào.
        $this->assertSame(
            'https://down-vn.img.susercontent.com/file/vn-11134207-7r98o-m04gtmha7b3h23',
            $result['product']['product_image'],
        );
        // 78000 là 78.000₫ thật, không phải đơn vị micro của Shopee — không được chia 100.000.
        $this->assertSame(78000.0, $result['product']['discounted_price']);
        $this->assertSame(200000.0, $result['product']['original_price']);
        // Họ không trả % giảm, phải tự tính từ 200.000 → 78.000.
        $this->assertSame(61, $result['product']['discount_percent']);
    }

    /** Shape thô của Shopee (snake_case + giá micro + hash ảnh) vẫn phải đọc được. */
    public function test_parses_raw_shopee_product_shape(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->flightBody([
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
        ]))]);

        $product = $this->fetch()['product'];

        // Hash ảnh phải được ghép thành URL CDN mới hiển thị được ở frontend.
        $this->assertSame('https://cf.shopee.vn/file/vn-11134207-7r98o-abcdef', $product['product_image']);
        // Giá micro (×100.000) phải quy về VND, nếu không sẽ hiện ₫15.900.000.000.
        $this->assertSame(159000.0, $product['discounted_price']);
        $this->assertSame(250000.0, $product['original_price']);
        // Có raw_discount thì dùng số của Shopee, không tự tính lại.
        $this->assertSame(36, $product['discount_percent']);
        $this->assertSame(1200, $product['sold_count']);
        $this->assertSame(4.75, $product['rating']);
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

        $this->assertSame('https://s.afp.ad/neY9ZSQImwTHq9zSrnyT2g', $result['voucher_link']);
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

    /**
     * Điểm chính của việc đưa tham số vào /admin/api-config: site nguồn deploy lại là
     * next_action đổi, admin dán ID mới vào là chạy lại ngay, không cần sửa code + deploy.
     */
    public function test_admin_params_win_over_config_defaults(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->flightBody())]);
        $this->storeAdminParams([
            'next_action' => 'id-moi-dan-tu-admin',
            'tool_id' => 'tool-moi',
            'action_payload' => '["$K2"]',
        ]);

        $this->fetch();

        Http::assertSent(function (Request $request) {
            $body = $request->body();

            return $request->hasHeader('Next-Action', 'id-moi-dan-tu-admin')
                && str_contains($body, 'tool-moi')
                && str_contains($body, '["$K2"]');
        });
    }

    /** Ô để trống ở admin thì rơi về config, không gửi request rỗng. */
    public function test_blank_admin_field_falls_back_to_config(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->flightBody())]);
        $this->storeAdminParams(['next_action' => '', 'tool_id' => 'tool-moi']);

        $this->fetch();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Next-Action', 'test-next-action')
            && str_contains($request->body(), 'tool-moi'));
    }

    /** Tắt is_active = tạm ngưng dùng cấu hình admin, quay về giá trị trong code. */
    public function test_inactive_admin_config_is_ignored(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->flightBody())]);
        $this->storeAdminParams(['next_action' => 'id-moi-dan-tu-admin'], isActive: false);

        $this->fetch();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Next-Action', 'test-next-action'));
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
