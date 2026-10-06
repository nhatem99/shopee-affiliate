<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Models\Setting;
use App\Models\ShortLink;
use App\Models\VoucherRef;
use App\Services\KieuShopeeService;
use App\Services\KieuSmartLinkService;
use App\Services\SourceHealthService;
use App\Services\VoucherRefService;
use App\Services\VoucherSourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Nguồn kieushopee.com/smart-links: GET trang tool lấy CSRF token rồi POST /convert, nhận về
 * link affiliate rút gọn của Shopee dưới tài khoản họ. Thứ đáng khoá nhất là link tới tay khách
 * phải mang affiliate của MÌNH mà vẫn giữ nguyên trang đích /opaanlp/ + credential_token.
 */
class KieuSmartLinkSourceTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://kieushopee.com/smart-links';

    private const SHOPEE_URL = 'https://shopee.vn/Ao-Hoodie-i.564687320.29261186260';

    private const SHORT_LINK = 'https://s.shopee.vn/9zyFCW6gQ5';

    /** Đích thật đo 04-10-2026 (rút gọn tham số), vẫn mang affiliate của họ. */
    private const LANDING = 'https://shopee.vn/opaanlp/564687320/29261186260?__mobile__=1&credential_token=tok-abc'
        .'&exp_group=rollout&mmp_pid=an_17312100008&utm_content=Facebook----&utm_medium=affiliates&utm_source=an_17312100008';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.kieusmartlink.endpoint' => self::ENDPOINT,
            'services.kieusmartlink.sub_id1' => 'Facebook',
            'services.shopee_affiliate.mmp_pid' => 'an_17332410386',
            'services.shopee_affiliate.utm_content' => 'fb',
        ]);
    }

    private function toolPage(string $token = 'csrf-123'): string
    {
        return '<!DOCTYPE html><html><head><meta charset="UTF-8">'
            .'<meta name="csrf-token" content="'.$token.'"></head><body></body></html>';
    }

    /** @param  array<string, mixed>  $extra */
    private function fakeSource(array $extra = []): void
    {
        Http::fake($extra + [
            self::ENDPOINT.'/convert' => Http::response(['success' => true, 'message' => 'Đã tạo link thành công!', 'converted_link' => self::SHORT_LINK]),
            self::ENDPOINT => Http::response($this->toolPage()),
            's.shopee.vn/*' => Http::response('', 301, ['Location' => self::LANDING]),
            // Mọi request khác (vd hỏi Shopee thông tin sản phẩm) không được chạm mạng thật.
            '*' => Http::response('', 404),
        ]);
    }

    private function useSource(string $platform): void
    {
        foreach (VoucherSourceResolver::SOURCES as $source) {
            ApiConfig::updateOrCreate(['platform' => $source], [
                'name' => $source,
                'endpoint' => $source === KieuSmartLinkService::SOURCE ? self::ENDPOINT : 'https://example.test',
                'app_secret' => '',
                'is_active' => $source === $platform,
            ]);
        }
    }

    // --- Service ---

    /** Deploy xong không được tự đổi nguồn của khách. */
    public function test_migration_creates_the_row_switched_off(): void
    {
        $row = ApiConfig::where('platform', KieuSmartLinkService::SOURCE)->firstOrFail();

        $this->assertFalse($row->is_active);
        $this->assertSame(self::ENDPOINT, $row->endpoint);
        $this->assertSame(KieuShopeeService::SOURCE, app(VoucherSourceResolver::class)->activeSource());
    }

    public function test_reads_csrf_token_then_converts_in_the_same_session(): void
    {
        $this->fakeSource();

        $result = app(KieuSmartLinkService::class)->fetchProductAndVoucherLink(self::SHOPEE_URL);

        $this->assertSame(self::SHORT_LINK, $result['voucher_link']);
        $this->assertSame('564687320', $result['shop_id']);
        $this->assertSame('29261186260', $result['item_id']);
        $this->assertNull($result['product']);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::ENDPOINT.'/convert'
            && $request->hasHeader('X-CSRF-TOKEN', 'csrf-123')
            && $request->hasHeader('Referer', self::ENDPOINT)
            && $request['url'] === self::SHOPEE_URL
            && $request['sub_id1'] === 'Facebook');
    }

    /** Admin sửa endpoint ở /admin/api-config là phải ăn ngay, không đợi deploy. */
    public function test_uses_the_endpoint_saved_by_admin(): void
    {
        ApiConfig::where('platform', KieuSmartLinkService::SOURCE)->update(['endpoint' => 'https://moi.example/tool']);
        Http::fake([
            'https://moi.example/tool/convert' => Http::response(['success' => true, 'converted_link' => self::SHORT_LINK]),
            'https://moi.example/tool' => Http::response($this->toolPage()),
            '*' => Http::response('', 404),
        ]);

        $result = app(KieuSmartLinkService::class)->fetchProductAndVoucherLink(self::SHOPEE_URL);

        $this->assertSame(self::SHORT_LINK, $result['voucher_link']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'kieushopee.com'));
    }

    /** Trang tool đổi giao diện, mất thẻ token: dừng luôn, không gửi request chắc chắn 419. */
    public function test_missing_csrf_token_stops_before_converting(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response('<html><head></head></html>'),
            '*' => Http::response('', 404),
        ]);

        $this->assertNull(app(KieuSmartLinkService::class)->fetchProductAndVoucherLink(self::SHOPEE_URL));
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/convert'));
    }

    public function test_rejected_conversion_returns_null(): void
    {
        $this->fakeSource([
            self::ENDPOINT.'/convert' => Http::response(['message' => 'CSRF token mismatch.'], 419),
        ]);

        $this->assertNull(app(KieuSmartLinkService::class)->fetchProductAndVoucherLink(self::SHOPEE_URL));
    }

    public function test_connection_test_names_this_source_on_failure(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $result = app(KieuSmartLinkService::class)->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('KieuSmartLinkService', $result['message']);
    }

    public function test_admin_connection_button_calls_this_source(): void
    {
        $this->fakeSource();
        $row = ApiConfig::where('platform', KieuSmartLinkService::SOURCE)->firstOrFail();

        $this->actingAs($this->createAdmin())
            ->postJson("/admin/api-config/{$row->id}/test")
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    // --- Quét link và bấm mua ---

    public function test_scan_goes_to_kieusmartlink_when_it_is_switched_on(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $this->useSource(KieuSmartLinkService::SOURCE);

        $kieu = Mockery::mock(KieuShopeeService::class);
        $kieu->shouldNotReceive('fetchProductAndVoucherLink');
        $this->app->instance(KieuShopeeService::class, $kieu);

        $smart = Mockery::mock(KieuSmartLinkService::class);
        $smart->shouldReceive('fetchProductAndVoucherLink')
            ->once()
            ->with(self::SHOPEE_URL)
            ->andReturn([
                'voucher_link' => self::SHORT_LINK,
                'shop_id' => '564687320',
                'item_id' => '29261186260',
                'product' => ['product_name' => 'Áo Hoodie'],
            ]);
        $this->app->instance(KieuSmartLinkService::class, $smart);

        $this->actingAs($this->createAdmin())
            ->post('/voucher/resolve', ['url' => self::SHOPEE_URL])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('voucherResult.voucher_ref'));

        $ref = VoucherRef::latest('id')->firstOrFail();
        $this->assertSame(self::SHORT_LINK, $ref->url);
        $this->assertSame(KieuSmartLinkService::SOURCE, $ref->source);
    }

    /**
     * Cả chuỗi lúc bấm mua: lấy link mới từ tool → đi theo s.shopee.vn → đổi affiliate về của mình.
     * Trang đích /opaanlp/ và credential_token phải còn nguyên, affiliate của họ phải mất hẳn.
     */
    public function test_buy_click_lands_on_opaanlp_with_our_affiliate(): void
    {
        $this->fakeSource();

        $ref = app(VoucherRefService::class)->issue(self::SHORT_LINK, KieuSmartLinkService::SOURCE, self::SHOPEE_URL);

        $code = $this->postJson('/voucher/shorten', ['ref' => $ref])->assertOk()->json('code');

        $link = ShortLink::where('code', $code)->firstOrFail();
        $this->assertSame(KieuSmartLinkService::SOURCE, $link->source);
        $this->assertStringStartsWith('https://shopee.vn/opaanlp/564687320/29261186260?', $link->target_url);

        parse_str((string) parse_url($link->target_url, PHP_URL_QUERY), $query);
        $this->assertSame('an_17332410386', $query['mmp_pid']);
        $this->assertSame('an_17332410386', $query['utm_source']);
        $this->assertSame('tok-abc', $query['credential_token']);
        $this->assertSame('fb', $query['utm_content']);
        $this->assertStringNotContainsString('an_17312100008', $link->target_url);

        Http::assertSent(fn (Request $request) => $request->url() === self::ENDPOINT.'/convert');
    }

    // --- Bật một nguồn thì tắt các nguồn còn lại ---

    public function test_switching_on_kieusmartlink_turns_kieushopee_off(): void
    {
        $this->actingAs($this->createAdmin())->post('/admin/api-config', [
            'name' => 'Kieu Smart Link',
            'endpoint' => self::ENDPOINT,
            'platform' => KieuSmartLinkService::SOURCE,
            'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(KieuSmartLinkService::SOURCE, app(VoucherSourceResolver::class)->activeSource());
        $this->assertFalse(ApiConfig::where('platform', KieuShopeeService::SOURCE)->value('is_active'));
    }

    // --- Tự bảo trì khi nguồn lỗi ---

    public function test_health_check_follows_kieusmartlink_when_it_is_the_chosen_source(): void
    {
        Setting::set(SourceHealthService::ENABLED_KEY, '1');
        $this->useSource(KieuSmartLinkService::SOURCE);

        $kieu = Mockery::mock(KieuShopeeService::class);
        $kieu->shouldNotReceive('testConnection');
        $this->app->instance(KieuShopeeService::class, $kieu);

        $smart = Mockery::mock(KieuSmartLinkService::class);
        $smart->shouldReceive('testConnection')->once()->andReturn(['ok' => true, 'message' => 'Lấy link thành công']);
        $this->app->instance(KieuSmartLinkService::class, $smart);

        $status = app(SourceHealthService::class)->check();

        $this->assertTrue($status['ok']);
        $this->assertSame(KieuSmartLinkService::SOURCE, $status['source']);
    }
}
