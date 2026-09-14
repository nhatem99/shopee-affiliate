<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Models\VoucherRef;
use App\Services\GanmaService;
use App\Services\KieuShopeeService;
use App\Services\UrlValidationService;
use App\Services\VoucherSourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Hai nguồn lấy mã chạy song song và admin chọn nguồn đang dùng ở /admin/api-config:
 *  • kieushopee — mã Facebook/Instagram, nhận mọi link Shopee.
 *  • ganma      — mã YouTube, CHỈ nhận link ngắn từ app Shopee.
 *
 * Chọn nhầm nguồn thì khách hoặc không lấy được mã, hoặc lấy được mã của kênh sai — mà cả hai
 * ca đều không có dấu hiệu gì trên giao diện. Test này khoá lại đúng nguồn nào được gọi.
 */
class VoucherSourceSelectionTest extends TestCase
{
    use RefreshDatabase;

    private const SHORT_URL = 'https://vn.shp.ee/r1MhnNPY';

    private const FULL_URL = 'https://shopee.vn/Ao-Hoodie-i.564687320.29261186260';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::fake();
    }

    private function sourceConfig(string $platform, bool $active): void
    {
        ApiConfig::updateOrCreate(['platform' => $platform], [
            'name' => $platform,
            'endpoint' => 'https://example.test',
            'is_active' => $active,
        ]);
    }

    private function resolver(): VoucherSourceResolver
    {
        return app(VoucherSourceResolver::class);
    }

    /**
     * Bật đúng một nguồn, tắt hẳn nguồn kia — đúng trạng thái mà makeExclusive() tạo ra khi
     * admin lưu cấu hình. Migration tạo sẵn CẢ HAI bản ghi (kieushopee bật), nên chỉ bật ganma
     * mà quên tắt kieushopee là rơi vào nhánh tie-break chứ không phải nhánh đang muốn kiểm tra.
     */
    private function useSource(string $platform): void
    {
        foreach (VoucherSourceResolver::SOURCES as $source) {
            $this->sourceConfig($source, $source === $platform);
        }
    }

    // --- Tầng resolver ---

    /** Chưa cấu hình gì thì vẫn phải chạy được bằng nguồn vốn có, không được sập. */
    public function test_falls_back_to_kieushopee_when_nothing_is_configured(): void
    {
        $this->assertSame(KieuShopeeService::SOURCE, $this->resolver()->activeSource());
    }

    public function test_picks_the_source_the_admin_turned_on(): void
    {
        $this->sourceConfig(KieuShopeeService::SOURCE, false);
        $this->sourceConfig(GanmaService::SOURCE, true);

        $this->assertSame(GanmaService::SOURCE, $this->resolver()->activeSource());
    }

    /** Admin tắt hết = quay về nguồn mặc định, không phải "không có nguồn nào". */
    public function test_falls_back_when_every_source_is_switched_off(): void
    {
        $this->sourceConfig(KieuShopeeService::SOURCE, false);
        $this->sourceConfig(GanmaService::SOURCE, false);

        $this->assertSame(KieuShopeeService::SOURCE, $this->resolver()->activeSource());
    }

    /**
     * Dữ liệu lệch (sửa tay trong DB) không được làm hành vi phụ thuộc thứ tự dòng trong bảng —
     * cùng một DB phải luôn ra cùng một nguồn.
     */
    public function test_choice_is_deterministic_when_both_sources_are_on(): void
    {
        $this->sourceConfig(KieuShopeeService::SOURCE, true);
        $this->sourceConfig(GanmaService::SOURCE, true);

        // Nghiêng về kieushopee: nó nhận MỌI dạng link Shopee, còn ganma từ chối link đầy đủ.
        // Lệch dữ liệu mà rơi vào ganma thì phần lớn khách bị từ chối ngay từ cửa.
        $this->assertSame(KieuShopeeService::SOURCE, $this->resolver()->activeSource());
    }

    // --- Bật một nguồn thì tắt nguồn kia ---

    public function test_turning_on_one_source_turns_the_other_off(): void
    {
        $this->sourceConfig(KieuShopeeService::SOURCE, true);

        $this->actingAs($this->createAdmin())->post('/admin/api-config', [
            'name' => 'Ganma',
            'endpoint' => 'https://ganma.vn',
            'app_secret' => 'x',
            'platform' => GanmaService::SOURCE,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertFalse(ApiConfig::where('platform', KieuShopeeService::SOURCE)->value('is_active'));
        $this->assertTrue(ApiConfig::where('platform', GanmaService::SOURCE)->value('is_active'));
    }

    /** Lưu cấu hình Facebook không được đụng tới nguồn mã đang bật. */
    public function test_saving_an_unrelated_platform_leaves_the_sources_alone(): void
    {
        $this->sourceConfig(KieuShopeeService::SOURCE, true);

        $this->actingAs($this->createAdmin())->post('/admin/api-config', [
            'name' => 'Facebook',
            'endpoint' => 'https://graph.facebook.com',
            'app_id' => '1',
            'app_secret' => 'token',
            'platform' => 'facebook',
            'is_active' => true,
        ])->assertRedirect();

        $this->assertTrue(ApiConfig::where('platform', KieuShopeeService::SOURCE)->value('is_active'));
    }

    // --- Tầng controller: nguồn nào thật sự được gọi ---

    private function mockSources(): array
    {
        $kieu = Mockery::mock(KieuShopeeService::class);
        // Partial để canHandle() chạy thật — truyền constructor args để các thuộc tính của
        // service được khởi tạo, không thì method thật nào đụng tới chúng cũng nổ.
        $ganma = Mockery::mock(GanmaService::class, [app(UrlValidationService::class)])->makePartial();

        $this->app->instance(KieuShopeeService::class, $kieu);
        $this->app->instance(GanmaService::class, $ganma);

        return [$kieu, $ganma];
    }

    private const YTB_LINK = 'https://s.shopee.vn/an_redir?affiliate_id=1&sub_id=YT3-a';

    private const KIEU_LINK = 'https://s.afp.ad/kieu-link';

    /** Ref vừa phát cho khách — để biết link nào, nguồn nào thật sự được đưa ra. */
    private function issuedRef(): VoucherRef
    {
        return VoucherRef::latest('id')->firstOrFail();
    }

    /**
     * Chế độ mã YTB gọi CẢ HAI nguồn: ganma bằng link ngắn GỐC (resolveCanonicalUrl() bung nó
     * thành link shopee.vn đầy đủ, đúng dạng ganma từ chối), kieushopee bằng link đã bung. Link
     * đưa cho khách là của kieushopee; link YTB đi kèm trong ref để lúc bấm mua xâu vào trước.
     * Server KHÔNG tự mở link YTB — đã thử, Shopee không ghi nhận (mã phải kích hoạt trên máy khách).
     */
    public function test_ytb_mode_calls_both_sources_and_keeps_the_ytb_link_in_the_ref(): void
    {
        $this->useSource(GanmaService::SOURCE);
        [$kieu, $ganma] = $this->mockSources();

        $ganma->shouldReceive('fetchProductAndVoucherLink')
            ->once()
            ->with(self::SHORT_URL)
            ->andReturn(['voucher_link' => self::YTB_LINK, 'shop_id' => '1', 'item_id' => '2', 'product' => null]);
        // Http::fake() không có Location nên link ngắn "bung" ra vẫn là chính nó.
        $kieu->shouldReceive('fetchProductAndVoucherLink')
            ->once()
            ->with(self::SHORT_URL)
            ->andReturn(['voucher_link' => self::KIEU_LINK, 'shop_id' => '1', 'item_id' => '2', 'product' => null]);

        $this->actingAs($this->createAdmin())
            ->post('/voucher/resolve', ['url' => self::SHORT_URL])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('voucherResult.voucher_ref'));

        Http::assertNotSent(fn ($request) => $request->url() === self::YTB_LINK);

        $ref = $this->issuedRef();
        $this->assertSame(self::KIEU_LINK, $ref->url);
        $this->assertSame(KieuShopeeService::SOURCE, $ref->source);
        $this->assertSame(self::YTB_LINK, $ref->ytb_url);
    }

    /** Ganma không ra mã thì khách vẫn nhận link kieushopee như thường, ref không có link YTB. */
    public function test_ytb_mode_still_serves_kieushopee_when_ganma_has_no_code(): void
    {
        $this->useSource(GanmaService::SOURCE);
        [$kieu, $ganma] = $this->mockSources();

        $ganma->shouldReceive('fetchProductAndVoucherLink')->once()->andReturnNull();
        $kieu->shouldReceive('fetchProductAndVoucherLink')
            ->once()
            ->andReturn(['voucher_link' => self::KIEU_LINK, 'shop_id' => '1', 'item_id' => '2', 'product' => null]);

        $this->actingAs($this->createAdmin())
            ->post('/voucher/resolve', ['url' => self::SHORT_URL])
            ->assertOk();

        $ref = $this->issuedRef();
        $this->assertSame(self::KIEU_LINK, $ref->url);
        $this->assertNull($ref->ytb_url);
    }

    /**
     * Kieushopee không ra mã thì rơi về link ganma (hành vi cũ của chế độ này) — thà có mã còn
     * hơn không. Ref phải ghi nguồn ganma kèm link ngắn gốc, để lúc khách bấm mua
     * ShortLinkController gọi lại đúng nguồn bằng đúng dạng link nó nhận.
     */
    public function test_ytb_mode_falls_back_to_the_ganma_link_when_kieushopee_has_no_code(): void
    {
        $this->useSource(GanmaService::SOURCE);
        [$kieu, $ganma] = $this->mockSources();

        $ganma->shouldReceive('fetchProductAndVoucherLink')
            ->once()
            ->andReturn(['voucher_link' => self::YTB_LINK, 'shop_id' => '1', 'item_id' => '2', 'product' => null]);
        $kieu->shouldReceive('fetchProductAndVoucherLink')->once()->andReturnNull();

        $this->actingAs($this->createAdmin())
            ->post('/voucher/resolve', ['url' => self::SHORT_URL])
            ->assertOk();

        $ref = $this->issuedRef();
        $this->assertSame(self::YTB_LINK, $ref->url);
        $this->assertSame(GanmaService::SOURCE, $ref->source);
        $this->assertSame(self::SHORT_URL, $ref->source_url);
        // Chính url đã là link YTB — không xâu thêm lần nữa lúc bấm mua.
        $this->assertNull($ref->ytb_url);
    }

    /**
     * Ganma đang bật nhưng khách dán link đầy đủ: phải báo cho khách biết phải làm gì, thay vì
     * đợi 20 giây rồi hiện "không lấy được mã".
     */
    public function test_explains_what_to_do_when_ganma_cannot_handle_the_link(): void
    {
        $this->useSource(GanmaService::SOURCE);
        [$kieu, $ganma] = $this->mockSources();

        $kieu->shouldNotReceive('fetchProductAndVoucherLink');
        $ganma->shouldNotReceive('fetchProductAndVoucherLink');

        $this->actingAs($this->createAdmin())
            ->post('/voucher/resolve', ['url' => self::FULL_URL])
            ->assertSessionHasErrors('voucher_url');
    }

    /** Nguồn mặc định vẫn phải chạy y như trước khi có ganma. */
    public function test_kieushopee_still_receives_the_canonical_url(): void
    {
        $this->useSource(KieuShopeeService::SOURCE);
        [$kieu, $ganma] = $this->mockSources();

        $ganma->shouldNotReceive('fetchProductAndVoucherLink');
        $kieu->shouldReceive('fetchProductAndVoucherLink')
            ->once()
            ->with(self::FULL_URL)
            ->andReturn(['voucher_link' => 'https://shopee.vn/product-i.1.2?mmp_pid=x', 'shop_id' => '1', 'item_id' => '2', 'product' => null]);

        $this->actingAs($this->createAdmin())
            ->post('/voucher/resolve', ['url' => self::FULL_URL])
            ->assertOk();
    }
}
