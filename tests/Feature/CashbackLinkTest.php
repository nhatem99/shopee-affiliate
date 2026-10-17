<?php

namespace Tests\Feature;

use App\Models\AffiliateLink;
use App\Models\Setting;
use App\Services\CashbackLinkService;
use App\Services\CashbackService;
use App\Services\KieuShopeeService;
use App\Services\ShopeeProductLookupService;
use App\Services\SourceHealthService;
use App\Services\VoucherRefService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Công tắc "Lấy mã giảm giá trên web" TẮT (mặc định từ 17-10-2026): ô dán link ở trang chủ chỉ ra
 * link affiliate chính thức của Shopee — khách đăng nhập có mã khách để hoàn tiền, khách vãng lai
 * vẫn có link nhưng không mã khách; không còn mã FB/IG/YTB ở bất cứ đường nào của web.
 */
class CashbackLinkTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_URL = 'https://shopee.vn/Ao-thun-nam-i.123456.987654321';

    private const DESKTOP_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set(CashbackService::RATE_KEY, '40');

        Http::fake([
            'shopee.vn/api/v4/item/get*' => Http::response(['data' => [
                'name' => 'Áo thun nam',
                'image' => 'abc123',
                'price' => 15000000000,
                'price_before_discount' => 20000000000,
            ]]),
            ShopeeProductLookupService::BASE_URL.'*' => Http::response([], 404),
        ]);
    }

    public function test_switch_is_off_by_default_and_home_works_on_desktop(): void
    {
        $this->assertFalse(CashbackLinkService::voucherToolEnabled());

        $this->withHeaders(['User-Agent' => self::DESKTOP_UA])
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('HomeCashback')
                ->where('settings.voucherToolEnabled', false)
                ->where('ytbVouchers', null)
            );
    }

    public function test_customer_gets_affiliate_link_carrying_their_sub_id(): void
    {
        $user = $this->createUser(['sub_id' => 'khach01']);

        $this->actingAs($user)
            ->post(route('cashback.link'), ['url' => self::PRODUCT_URL])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('HomeCashback')
                ->where('cashbackResult.logged_in', true)
                ->where('cashbackResult.product.product_name', 'Áo thun nam')
                ->where('cashbackResult.link', fn ($link) => $this->assertCashbackLink($link, 'CB-khach01---'))
                // Vẫn là trang chủ đầy đủ: bảng xếp hạng không biến mất sau khi dán link.
                ->has('leaderboard')
            );

        $this->assertDatabaseHas('affiliate_links', [
            'user_id' => $user->id,
            'platform' => 'shopee',
            'product_name' => 'Áo thun nam',
        ]);
    }

    public function test_customer_sees_estimated_cashback_from_the_products_commission(): void
    {
        // Http::fake() lần hai chỉ nối thêm sau giả lập của setUp — thay hẳn bằng bộ mới.
        Http::swap(new Factory);
        Http::fake([
            'shopee.vn/api/v4/item/get*' => Http::response([], 403),
            ShopeeProductLookupService::BASE_URL.'*' => Http::response(['productInfo' => [
                'productName' => 'Máy lọc không khí',
                'price' => 2545000,
                'commission' => 156700,
                'sellerRate' => 0.06,
                'shopeeRate' => 0.025,
            ]]),
        ]);
        $user = $this->createUser();
        // Hạng Vàng +3 điểm phần trăm: 156.700 × (40 + 3)% = 67.381.
        $user->forceFill(['tier' => 'vang'])->save();

        $this->actingAs($user)
            ->post(route('cashback.link'), ['url' => self::PRODUCT_URL])
            ->assertInertia(fn ($page) => $page
                // Số hoa hồng gốc không xuống trình duyệt — chia ra là lộ tỉ lệ thực trả.
                ->missing('cashbackResult.product.commission')
                ->where('cashbackResult.cashback_rate', 40)
                ->where('cashbackResult.estimate', 67381)
            );
    }

    /** Khách thấy tỉ lệ hiển thị (60%) nhưng tiền dự kiến tính theo tỉ lệ thực (40%) — không hứa quá số vào ví. */
    public function test_shows_the_display_rate_but_estimates_with_the_real_rate(): void
    {
        Http::swap(new Factory);
        Http::fake([
            'shopee.vn/api/v4/item/get*' => Http::response([], 403),
            ShopeeProductLookupService::BASE_URL.'*' => Http::response(['productInfo' => ['productName' => 'Cà phê', 'price' => 218400, 'commission' => 18564]]),
        ]);
        Setting::set(CashbackService::DISPLAY_RATE_KEY, '60');

        $this->actingAs($this->createUser())
            ->post(route('cashback.link'), ['url' => self::PRODUCT_URL])
            ->assertInertia(fn ($page) => $page
                ->where('cashbackResult.cashback_rate', 60)
                ->where('cashbackResult.estimate', 7426)
            );

        // Khách vãng lai: link không mang mã khách nên không có tiền dự kiến.
        auth()->logout();
        $this->post(route('cashback.link'), ['url' => self::PRODUCT_URL])
            ->assertInertia(fn ($page) => $page->where('cashbackResult.estimate', null));
    }

    public function test_no_estimate_without_a_commission_figure(): void
    {
        // setUp: Shopee trả thông tin nhưng nguồn có số hoa hồng thì 404 — không đoán bằng tỉ lệ cứng.
        $this->actingAs($this->createUser())
            ->post(route('cashback.link'), ['url' => self::PRODUCT_URL])
            ->assertInertia(fn ($page) => $page->where('cashbackResult.estimate', null));
    }

    public function test_guest_still_gets_a_link_without_customer_code(): void
    {
        $this->post(route('cashback.link'), ['url' => self::PRODUCT_URL])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cashbackResult.logged_in', false)
                ->where('cashbackResult.product.product_name', 'Áo thun nam')
                ->where('cashbackResult.link', fn ($link) => $this->assertCashbackLink($link, 'CB'))
            );

        $this->assertSame(0, AffiliateLink::count());
    }

    public function test_short_share_link_is_resolved_and_extra_text_ignored(): void
    {
        Http::fake([
            's.shopee.vn/*' => Http::response('', 302, ['Location' => 'https://shopee.vn/product/123456/987654321?smtt=0.0']),
        ]);
        $user = $this->createUser(['sub_id' => 'khach02']);

        $this->actingAs($user)
            ->post(route('cashback.link'), ['url' => 'Xem sản phẩm này nè https://s.shopee.vn/AbCdEf123'])
            ->assertInertia(fn ($page) => $page
                ->where('cashbackResult.link', fn ($link) => $this->assertCashbackLink($link, 'CB-khach02---'))
            );
    }

    public function test_link_from_another_site_is_rejected(): void
    {
        $this->post(route('cashback.link'), ['url' => 'https://lazada.vn/products/abc-i123.html'])
            ->assertRedirect('/')
            ->assertSessionHasErrors('link_url');
    }

    public function test_shopee_link_that_is_not_a_product_is_rejected(): void
    {
        $this->post(route('cashback.link'), ['url' => 'https://shopee.vn/shop/123456'])
            ->assertRedirect('/')
            ->assertSessionHasErrors('link_url');
    }

    public function test_refresh_after_post_goes_home(): void
    {
        $this->get('/link-hoan-tien')->assertRedirect('/');
    }

    public function test_voucher_endpoints_refuse_while_switch_is_off(): void
    {
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148'])
            ->post(route('voucher.resolve'), ['url' => self::PRODUCT_URL])
            ->assertRedirect('/')
            ->assertSessionHasErrors('voucher_url');

        // Ref cũ còn trong lịch sử trình duyệt cũng không đổi ra link có mã được nữa.
        $ref = app(VoucherRefService::class)->issue('https://s.shopee.vn/an_redir?voucher=1', KieuShopeeService::SOURCE);
        $this->postJson(route('voucher.shorten'), ['ref' => $ref])->assertStatus(422);

        $this->assertDatabaseCount('short_links', 0);
        Http::assertNothingSent();
    }

    public function test_guide_page_redirects_to_cashback_page(): void
    {
        $this->get('/huong-dan')->assertRedirect(route('cashback.info'));

        Setting::set(CashbackService::RATE_KEY, '0');
        $this->get('/huong-dan')->assertRedirect(route('home'));
    }

    public function test_switch_on_brings_the_voucher_tool_back(): void
    {
        $this->enableVoucherTool();

        $this->withHeaders(['User-Agent' => self::DESKTOP_UA])
            ->get('/')
            ->assertInertia(fn ($page) => $page
                ->component('Home')
                ->where('settings.voucherToolEnabled', true)
                ->where('canUseVoucherTool', false)
            );
        $this->get('/huong-dan')->assertOk();
    }

    public function test_admin_can_flip_the_switch(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->post('/admin/settings', ['voucher_tool_enabled' => true])->assertRedirect();
        $this->assertTrue(CashbackLinkService::voucherToolEnabled());

        $this->actingAs($admin)->post('/admin/settings', ['voucher_tool_enabled' => false])->assertRedirect();
        $this->assertFalse(CashbackLinkService::voucherToolEnabled());

        $this->actingAs($admin)->get('/admin/settings')
            ->assertInertia(fn ($page) => $page->where('voucherToolEnabled', false));
    }

    public function test_dead_voucher_source_does_not_close_the_site_while_switch_is_off(): void
    {
        Setting::set(SourceHealthService::ENABLED_KEY, '1');
        Setting::set(SourceHealthService::AUTO_FLAG_KEY, '1');
        Setting::set('maintenance_mode', '1');

        $source = Mockery::mock(KieuShopeeService::class);
        $source->shouldNotReceive('testConnection');
        $this->instance(KieuShopeeService::class, $source);

        $status = app(SourceHealthService::class)->check();

        $this->assertTrue($status['skipped']);
        // Lần bảo trì do lượt kiểm tra trước bật thì mở lại luôn.
        $this->assertFalse(Setting::getBool('maintenance_mode', false));
    }

    private function assertCashbackLink(?string $link, string $expectedSubId): bool
    {
        $this->assertNotNull($link);
        $this->assertStringStartsWith('https://s.shopee.vn/an_redir?', $link);

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $this->assertSame('https://shopee.vn/product/123456/987654321', $query['origin_link']);
        $this->assertSame('17332410386', $query['affiliate_id']);
        $this->assertSame($expectedSubId, $query['sub_id']);

        return true;
    }
}
