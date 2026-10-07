<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Services\GanmaService;
use App\Services\KieuShopeeService;
use App\Services\VoucherSourceResolver;
use App\Services\YtbVoucherStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Khối "Ưu đãi đang có" ở trang chủ — mã YTB còn bao nhiêu %, đọc từ ganma.vn/yt/vouchers.
 *
 * Đáng khoá lại: chỉ hiện khi khách đang nhận đúng mã YTB, ganma hỏng thì trang chủ vẫn chạy, và
 * số liệu cũ quá thì phải ẩn chứ không khoe "còn mã" cho một mã đã hết từ lâu.
 */
class YtbVoucherStatusTest extends TestCase
{
    use RefreshDatabase;

    private const VOUCHERS_URL = 'https://ganma.vn/yt/vouchers';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        // Làm mới ngầm (Cache::flexible) chạy ngay tại chỗ thay vì đợi request kết thúc.
        $this->withoutDefer();
    }

    private function useSource(string $platform): void
    {
        foreach (VoucherSourceResolver::SOURCES as $source) {
            ApiConfig::updateOrCreate(['platform' => $source], [
                'name' => $source,
                // Ghi thẳng ra cho ganma để test không phụ thuộc GANMA_ENDPOINT trong .env.
                'endpoint' => 'https://ganma.vn',
                'is_active' => $source === $platform,
            ]);
        }
    }

    /** Đúng shape đo thật trên ganma.vn/yt/vouchers ngày 02-10-2026. */
    private function ganmaBody(array $overrides = []): array
    {
        return array_merge([
            'vouchers' => [
                ['platform' => 'youtube', 'title' => 'Giảm 22% - Tối đa 100K', 'subtitle' => 'Đơn tối thiểu 150K', 'label' => '', 'brand' => 'SHOPEE', 'logo' => '', 'percent_left' => 13, 'expiry' => '', 'removed' => false, 'updated_at' => '2026-10-02T12:22:33.161080+07:00', 'sold_out' => false],
                ['platform' => 'youtube', 'title' => 'Giảm 20% - Tối đa 2tr', 'subtitle' => 'Đơn tối thiểu 500K', 'label' => '', 'brand' => 'SHOPEE', 'logo' => '', 'percent_left' => 0, 'expiry' => '', 'removed' => false, 'updated_at' => '2026-10-02T12:22:33.161080+07:00', 'sold_out' => true],
                ['platform' => 'youtube', 'title' => 'Mã đã gỡ', 'subtitle' => '', 'percent_left' => 50, 'removed' => true, 'sold_out' => false],
            ],
            'hours' => '0H - 9H - 12H - 18H',
        ], $overrides);
    }

    private function forDisplay(): ?array
    {
        return app(YtbVoucherStatusService::class)->forDisplay();
    }

    /** Lượt đầu lúc cache trống không có khối này (xem test_cache_trong_thi_khong_bat_khach_cho_ganma). */
    private function warm(): void
    {
        $this->assertNull($this->forDisplay());
    }

    /**
     * Cache trống: trang chủ không đứng chờ ganma. Lượt đó không có khối, ganma được đọc SAU khi
     * trả trang, khách kế tiếp mới thấy.
     */
    public function test_cache_trong_thi_khong_bat_khach_cho_ganma(): void
    {
        $this->withDefer();
        $this->useSource(GanmaService::SOURCE);
        Http::fake([self::VOUCHERS_URL => Http::response($this->ganmaBody())]);

        $this->assertNull($this->forDisplay());
        Http::assertNothingSent();

        // Việc dời lại chạy sau response.
        app(DeferredCallbackCollection::class)->invoke();
        Http::assertSentCount(1);

        $this->assertCount(2, $this->forDisplay());
    }

    public function test_trang_chu_hien_ma_ytb_va_phan_tram_da_dung(): void
    {
        $this->useSource(GanmaService::SOURCE);
        Http::fake([self::VOUCHERS_URL => Http::response($this->ganmaBody())]);
        $this->get('/');

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Mã bị gỡ (removed) không hiện.
                ->has('ytbVouchers', 2)
                ->where('ytbVouchers.0', [
                    'title' => 'Giảm 22% - Tối đa 100K',
                    'subtitle' => 'Đơn tối thiểu 150K',
                    'used_percent' => 87,
                    'sold_out' => false,
                ])
                ->where('ytbVouchers.1.used_percent', 100)
                ->where('ytbVouchers.1.sold_out', true)
            );
    }

    /** Đang để kieushopee thì khách nhận mã FB-IG — không hiện, và không gọi sang ganma. */
    public function test_nguon_khong_phai_ganma_thi_khong_hien_va_khong_goi_ganma(): void
    {
        $this->useSource(KieuShopeeService::SOURCE);
        Http::fake();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('ytbVouchers', null));

        Http::assertNothingSent();
    }

    /** Ganma sập lúc cache còn trống: trang chủ vẫn mở bình thường, chỉ không có khối này. */
    public function test_ganma_hong_thi_trang_chu_van_chay(): void
    {
        $this->useSource(GanmaService::SOURCE);
        Http::fake([self::VOUCHERS_URL => Http::response('Bad Gateway', 502)]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('ytbVouchers', null));
    }

    /**
     * Ganma hỏng sau một lượt đọc tốt: giữ bản cũ trong lúc còn mới, quá MAX_AGE_SECONDS thì ẩn —
     * không ghi đè bằng rỗng, nhưng cũng không khoe mãi số liệu đã cũ.
     */
    public function test_ganma_hong_thi_giu_ban_cu_toi_khi_qua_cu_thi_an(): void
    {
        $this->useSource(GanmaService::SOURCE);
        Http::fakeSequence(self::VOUCHERS_URL)
            ->push($this->ganmaBody())
            ->whenEmpty(Http::response('Bad Gateway', 502));

        $this->warm();
        $this->assertCount(2, $this->forDisplay());

        // Quá hạn "còn tươi" → làm mới ngầm, ganma lỗi → vẫn trả bản cũ.
        $this->travel(5)->minutes();
        $this->assertCount(2, $this->forDisplay());

        $this->travel(YtbVoucherStatusService::MAX_AGE_SECONDS)->seconds();
        $this->assertNull($this->forDisplay());
    }

    /** Ganma tắt kênh ({"vouchers":[],"disabled":true}, thấy thật ở /fb/vouchers) thì không hiện. */
    public function test_kenh_bi_tat_thi_khong_hien(): void
    {
        $this->useSource(GanmaService::SOURCE);
        Http::fake([self::VOUCHERS_URL => Http::response($this->ganmaBody(['disabled' => true]))]);

        $this->warm();
        $this->assertNull($this->forDisplay());
    }

    /** Nguồn không báo phần trăm thì vẫn hiện mã, chỉ bỏ thanh tiến độ. */
    public function test_thieu_percent_left_thi_khong_doan_so(): void
    {
        $this->useSource(GanmaService::SOURCE);
        Http::fake([self::VOUCHERS_URL => Http::response(['vouchers' => [['title' => 'Giảm 22%', 'sold_out' => false]]])]);

        $this->warm();
        $this->assertSame([['title' => 'Giảm 22%', 'subtitle' => '', 'used_percent' => null, 'sold_out' => false]], $this->forDisplay());
    }
}
