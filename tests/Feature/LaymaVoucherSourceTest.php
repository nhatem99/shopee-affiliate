<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Models\Setting;
use App\Models\ShortLink;
use App\Models\VoucherRef;
use App\Services\KieuShopeeService;
use App\Services\LaymaVoucherService;
use App\Services\SourceHealthService;
use App\Services\VoucherRefService;
use App\Services\VoucherSourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Nguồn dự phòng laymavoucher.afp.ad — bật lên đúng lúc kieushopee đang chết. Thứ đáng khoá
 * nhất vì thế là SỰ TÁCH BẠCH: bật laymavoucher thì không một request nào của khách (quét, bấm
 * mua) hay của lượt kiểm tra sức khoẻ còn đi sang kieushopee, và tham số của hai nguồn không
 * bao giờ lẫn sang nhau dù chung một thân code.
 */
class LaymaVoucherSourceTest extends TestCase
{
    use RefreshDatabase;

    private const LAYMA_ENDPOINT = 'https://laymavoucher.afp.ad/';

    private const KIEU_ENDPOINT = 'https://sansale.kieushopee.com/22';

    private const SHOPEE_URL = 'https://shopee.vn/Ao-Hoodie-i.564687320.29261186260';

    private const LAYMA_LINK = 'https://s.afp.ad/layma-link';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'services.laymavoucher.endpoint' => self::LAYMA_ENDPOINT,
            'services.laymavoucher.next_action' => 'layma-next-action',
            'services.laymavoucher.tool_id' => 'layma-tool-id',
            'services.laymavoucher.action_payload' => '["$K1"]',
            'services.kieushopee.endpoint' => self::KIEU_ENDPOINT,
            'services.kieushopee.next_action' => 'kieu-next-action',
            'services.kieushopee.tool_id' => 'kieu-tool-id',
            'services.kieushopee.action_payload' => '["$K1"]',
        ]);
    }

    /** Cùng shape với phản hồi thật của kieushopee — hai site chung một nền tảng. */
    private function flightBody(string $link = self::LAYMA_LINK): string
    {
        return '0:{"a":"$@1","f":"","q":"","i":false,"b":"x"}'."\n"
            .'1:'.json_encode([
                'success' => true,
                'results' => [['link' => $link, 'shopId' => '564687320', 'itemId' => '29261186260']],
                'productInfo' => ['name' => 'Áo Hoodie', 'price' => 78000, 'priceBeforeDiscount' => 200000],
            ], JSON_UNESCAPED_UNICODE)."\n";
    }

    private function useSource(string $platform): void
    {
        foreach (VoucherSourceResolver::SOURCES as $source) {
            ApiConfig::updateOrCreate(['platform' => $source], [
                'name' => $source,
                'endpoint' => 'https://example.test',
                'app_secret' => '',
                'is_active' => $source === $platform,
            ]);
        }
    }

    // --- Service ---

    /** Migration tạo sẵn bản ghi ở trạng thái TẮT: deploy xong không được tự đổi nguồn của khách. */
    public function test_migration_creates_the_row_switched_off(): void
    {
        $row = ApiConfig::where('platform', LaymaVoucherService::SOURCE)->firstOrFail();

        $this->assertFalse($row->is_active);
        $this->assertSame(KieuShopeeService::SOURCE, app(VoucherSourceResolver::class)->activeSource());
    }

    public function test_calls_its_own_endpoint_with_its_own_params(): void
    {
        ApiConfig::where('platform', LaymaVoucherService::SOURCE)->delete();
        Http::fake([self::LAYMA_ENDPOINT => Http::response($this->flightBody())]);

        $result = app(LaymaVoucherService::class)->fetchProductAndVoucherLink(self::SHOPEE_URL);

        $this->assertSame(self::LAYMA_LINK, $result['voucher_link']);
        $this->assertSame('Áo Hoodie', $result['product']['product_name']);

        Http::assertSent(fn (Request $request) => $request->url() === self::LAYMA_ENDPOINT
            && $request->hasHeader('Next-Action', 'layma-next-action')
            && $request->hasHeader('Origin', 'https://laymavoucher.afp.ad')
            && str_contains($request->body(), 'layma-tool-id')
            && str_contains($request->body(), self::SHOPEE_URL));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'kieushopee'));
    }

    /**
     * Chung thân code nhưng tham số admin dán cho nguồn này không được đọc nhầm của nguồn kia —
     * next_action của kieushopee gửi sang laymavoucher là 404 chắc chắn.
     */
    public function test_reads_its_own_admin_row_not_kieushopees(): void
    {
        ApiConfig::where('platform', KieuShopeeService::SOURCE)->update([
            'endpoint' => self::KIEU_ENDPOINT,
            'meta' => ['next_action' => 'kieu-from-admin', 'tool_id' => 'kieu-tool-from-admin'],
        ]);
        ApiConfig::where('platform', LaymaVoucherService::SOURCE)->update([
            'endpoint' => self::LAYMA_ENDPOINT,
            'meta' => ['next_action' => 'layma-from-admin', 'tool_id' => 'layma-tool-from-admin'],
        ]);
        Http::fake([self::LAYMA_ENDPOINT => Http::response($this->flightBody())]);

        app(LaymaVoucherService::class)->fetchProductAndVoucherLink(self::SHOPEE_URL);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Next-Action', 'layma-from-admin')
            && str_contains($request->body(), 'layma-tool-from-admin'));
        Http::assertNotSent(fn (Request $request) => $request->hasHeader('Next-Action', 'kieu-from-admin'));
    }

    /** Log phải nói đúng nguồn nào hỏng — admin đang cân nhắc đổi qua lại giữa hai nguồn. */
    public function test_failure_message_names_this_source(): void
    {
        Http::fake([self::LAYMA_ENDPOINT => Http::response('Server action not found', 404)]);

        $result = app(LaymaVoucherService::class)->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('LaymaVoucherService', $result['message']);
    }

    // --- Quét link và bấm mua ---

    public function test_scan_goes_to_laymavoucher_when_it_is_switched_on(): void
    {
        $this->useSource(LaymaVoucherService::SOURCE);

        $kieu = Mockery::mock(KieuShopeeService::class);
        $kieu->shouldNotReceive('fetchProductAndVoucherLink');
        $this->app->instance(KieuShopeeService::class, $kieu);

        $layma = Mockery::mock(LaymaVoucherService::class);
        $layma->shouldReceive('fetchProductAndVoucherLink')
            ->once()
            ->with(self::SHOPEE_URL)
            ->andReturn(['voucher_link' => self::LAYMA_LINK, 'shop_id' => '1', 'item_id' => '2', 'product' => null]);
        $this->app->instance(LaymaVoucherService::class, $layma);

        $this->actingAs($this->createAdmin())
            ->post('/voucher/resolve', ['url' => self::SHOPEE_URL])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('voucherResult.voucher_ref'));

        $ref = VoucherRef::latest('id')->firstOrFail();
        $this->assertSame(self::LAYMA_LINK, $ref->url);
        $this->assertSame(LaymaVoucherService::SOURCE, $ref->source);
    }

    /**
     * Bấm mua lấy lại mã từ đúng nguồn đã phát ref — không theo nguồn đang bật. Ref laymavoucher
     * mà đi hỏi kieushopee là hỏi đúng nguồn đang chết.
     */
    public function test_buy_click_refetches_from_laymavoucher(): void
    {
        Http::fake([
            self::LAYMA_ENDPOINT => Http::response($this->flightBody('https://shopee.vn/product-i.1.2?promo=layma-fresh')),
            'sansale.kieushopee.com/*' => Http::response('should not be called', 500),
        ]);

        $ref = app(VoucherRefService::class)->issue(
            'https://shopee.vn/product-i.1.2?promo=old',
            LaymaVoucherService::SOURCE,
            'https://shopee.vn/product-i.1.2',
        );

        $code = $this->postJson('/voucher/shorten', ['ref' => $ref])->assertOk()->json('code');

        $link = ShortLink::where('code', $code)->firstOrFail();
        $this->assertStringContainsString('promo=layma-fresh', $link->target_url);
        $this->assertSame(LaymaVoucherService::SOURCE, $link->source);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'kieushopee'));
    }

    // --- Bật một nguồn thì tắt các nguồn còn lại ---

    public function test_switching_on_laymavoucher_turns_kieushopee_off_and_back(): void
    {
        $admin = $this->createAdmin();
        $save = fn (string $platform, string $endpoint) => $this->actingAs($admin)->post('/admin/api-config', [
            'name' => $platform,
            'endpoint' => $endpoint,
            'platform' => $platform,
            'is_active' => true,
        ])->assertRedirect();

        $save(LaymaVoucherService::SOURCE, self::LAYMA_ENDPOINT);

        $this->assertSame(LaymaVoucherService::SOURCE, app(VoucherSourceResolver::class)->activeSource());
        $this->assertFalse(ApiConfig::where('platform', KieuShopeeService::SOURCE)->value('is_active'));

        // kieushopee sống lại: bật nó lên là xong, laymavoucher tự tắt.
        $save(KieuShopeeService::SOURCE, self::KIEU_ENDPOINT);

        $this->assertSame(KieuShopeeService::SOURCE, app(VoucherSourceResolver::class)->activeSource());
        $this->assertFalse(ApiConfig::where('platform', LaymaVoucherService::SOURCE)->value('is_active'));
    }

    // --- Tự bảo trì khi nguồn lỗi ---

    private function healthAnswers(string $serviceClass, bool $ok): void
    {
        $mock = Mockery::mock($serviceClass);
        $mock->shouldReceive('testConnection')
            ->once()
            ->andReturn(['ok' => $ok, 'message' => $ok ? 'Lấy mã thành công' : 'Không lấy được mã.']);

        $this->app->instance($serviceClass, $mock);
    }

    /**
     * Lý do cả tính năng tồn tại: kieushopee chết, admin chuyển sang laymavoucher. Lượt kiểm tra
     * mà vẫn gọi kieushopee thì trang bị đóng đúng lúc nguồn dự phòng đang chạy ngon.
     */
    public function test_health_check_follows_laymavoucher_when_it_is_the_chosen_source(): void
    {
        Setting::set(SourceHealthService::ENABLED_KEY, '1');
        $this->useSource(LaymaVoucherService::SOURCE);

        $kieu = Mockery::mock(KieuShopeeService::class);
        $kieu->shouldNotReceive('testConnection');
        $this->app->instance(KieuShopeeService::class, $kieu);
        $this->healthAnswers(LaymaVoucherService::class, true);

        $status = app(SourceHealthService::class)->check();

        $this->assertTrue($status['ok']);
        $this->assertSame(LaymaVoucherService::SOURCE, $status['source']);
    }

    /** Lỗi của nguồn cũ không phải bằng chứng gì về nguồn mới — đổi nguồn là đếm lại từ đầu. */
    public function test_failure_count_restarts_after_switching_source(): void
    {
        Setting::set(SourceHealthService::ENABLED_KEY, '1');
        Setting::set(SourceHealthService::STATUS_KEY, json_encode([
            'checked_at' => now()->toIso8601String(),
            'source' => KieuShopeeService::SOURCE,
            'ok' => false,
            'message' => 'kieushopee lỗi',
            'consecutive_failures' => 1,
            'action' => 'cho_them_luot',
        ]));
        $this->useSource(LaymaVoucherService::SOURCE);
        $this->healthAnswers(LaymaVoucherService::class, false);

        $status = app(SourceHealthService::class)->check();

        $this->assertSame(1, $status['consecutive_failures']);
        $this->assertFalse(Setting::getBool('maintenance_mode', false));
    }
}
