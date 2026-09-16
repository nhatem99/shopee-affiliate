<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Models\Setting;
use App\Services\GanmaService;
use App\Services\KieuShopeeService;
use App\Services\RestockScheduleService;
use App\Services\VoucherSourceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tự chuyển nguồn sang FB-IG trong khung giờ back mã (Setting fbig_window_auto_switch).
 *
 * Đây là lớp ghi đè LẶNG LẼ lên lựa chọn nguồn của admin: không sửa is_active, không ghi log gì
 * cho admin thấy ngoài một dòng chữ trên trang Cấu hình API. Hỏng thì không ai biết, chỉ thấy
 * khách lấy mã sai kênh. Nên ba thứ đáng khoá lại: mặc định phải TẮT, chỉ ghi đè đúng chiều
 * ganma → kieushopee, và đúng biên khung giờ.
 */
class VoucherSourceAutoSwitchTest extends TestCase
{
    use RefreshDatabase;

    private function useSource(string $platform): void
    {
        foreach (VoucherSourceResolver::SOURCES as $source) {
            ApiConfig::updateOrCreate(['platform' => $source], [
                'name' => $source,
                'endpoint' => 'https://example.test',
                'is_active' => $source === $platform,
            ]);
        }
    }

    private function enableAutoSwitch(): void
    {
        Setting::set(VoucherSourceResolver::AUTO_SWITCH_KEY, '1');
    }

    /** Giờ VN, để test đọc ra là biết ngay đang ở trong hay ngoài khung. */
    private function atVn(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse("2026-09-16 {$time}", RestockScheduleService::TIMEZONE));
    }

    private function activeSource(): string
    {
        return app(VoucherSourceResolver::class)->activeSource();
    }

    /**
     * Mặc định phải tắt. Bật sẵn là đổi hành vi của mọi cài đặt đang chạy mà không ai yêu cầu —
     * và đây đúng là loại thay đổi không nhìn thấy cho tới lúc khách nhận mã sai kênh.
     */
    public function test_mac_dinh_tat_nen_khung_gio_khong_doi_gi(): void
    {
        $this->useSource(GanmaService::SOURCE);
        $this->atVn('09:30');

        $this->assertFalse(VoucherSourceResolver::autoSwitchEnabled());
        $this->assertSame(GanmaService::SOURCE, $this->activeSource());
    }

    public function test_bat_len_thi_trong_khung_gio_ganma_tam_nhuong_cho_kieushopee(): void
    {
        $this->useSource(GanmaService::SOURCE);
        $this->enableAutoSwitch();
        $this->atVn('09:30');

        $this->assertSame(KieuShopeeService::SOURCE, $this->activeSource());
        // Lựa chọn của admin trong DB không được đụng tới — hết khung là tự quay về.
        $this->assertTrue(ApiConfig::where('platform', GanmaService::SOURCE)->value('is_active'));
        $this->assertSame(GanmaService::SOURCE, app(VoucherSourceResolver::class)->configuredSource());
    }

    public function test_ngoai_khung_gio_thi_giu_nguyen_ganma(): void
    {
        $this->useSource(GanmaService::SOURCE);
        $this->enableAutoSwitch();
        $this->atVn('12:30');

        $this->assertSame(GanmaService::SOURCE, $this->activeSource());
    }

    /** Admin vốn đã để kieushopee thì không có gì để chuyển — và cũng không được chuyển ngược lại. */
    public function test_dang_o_kieushopee_thi_khong_doi_gi_ca(): void
    {
        $this->useSource(KieuShopeeService::SOURCE);
        $this->enableAutoSwitch();

        foreach (['09:30', '12:30'] as $time) {
            $this->atVn($time);
            $this->assertSame(KieuShopeeService::SOURCE, $this->activeSource(), "lúc {$time}");
        }
    }

    /**
     * Biên khung giờ: mở ngay tại mốc, đóng đúng WINDOW_MINUTES sau đó. Lệch một phút ở đây là
     * khách dán link đúng lúc mã vừa back mà vẫn bị đẩy qua nguồn chậm.
     */
    public function test_khung_gio_mo_tai_moc_va_dong_sau_dung_mot_tieng(): void
    {
        $this->useSource(GanmaService::SOURCE);
        $this->enableAutoSwitch();

        $this->atVn('08:59');
        $this->assertSame(GanmaService::SOURCE, $this->activeSource(), 'trước mốc 9h');

        $this->atVn('09:00');
        $this->assertSame(KieuShopeeService::SOURCE, $this->activeSource(), 'đúng mốc 9h');

        $this->atVn('09:59');
        $this->assertSame(KieuShopeeService::SOURCE, $this->activeSource(), 'phút cuối của khung');

        $this->atVn('10:00');
        $this->assertSame(GanmaService::SOURCE, $this->activeSource(), 'hết khung');
    }

    /** Cả bốn mốc 0h/9h/15h/20h đều phải mở khung, không riêng mốc nào. */
    public function test_ca_bon_moc_trong_ngay_deu_mo_khung(): void
    {
        $this->useSource(GanmaService::SOURCE);
        $this->enableAutoSwitch();

        foreach (RestockScheduleService::FB_IG_HOURS as $hour) {
            $this->atVn(sprintf('%02d:05', $hour));
            $this->assertSame(KieuShopeeService::SOURCE, $this->activeSource(), "mốc {$hour}h");
        }
    }

    /** Múi giờ là của nguồn cấp mã (VN), không phải giờ UTC của server. */
    public function test_khung_gio_tinh_theo_gio_viet_nam(): void
    {
        $this->useSource(GanmaService::SOURCE);
        $this->enableAutoSwitch();

        // 02:30 UTC = 09:30 giờ VN → đang trong khung.
        $this->travelTo(CarbonImmutable::parse('2026-09-16 02:30', 'UTC'));
        $this->assertSame(KieuShopeeService::SOURCE, $this->activeSource());

        // 09:30 UTC = 16:30 giờ VN → đã ngoài khung 15h.
        $this->travelTo(CarbonImmutable::parse('2026-09-16 09:30', 'UTC'));
        $this->assertSame(GanmaService::SOURCE, $this->activeSource());
    }

    public function test_admin_bat_tat_duoc_o_trang_cai_dat(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->post('/admin/settings', ['fbig_window_auto_switch' => true])->assertRedirect();
        $this->assertTrue(VoucherSourceResolver::autoSwitchEnabled());

        $this->actingAs($admin)->post('/admin/settings', ['fbig_window_auto_switch' => false])->assertRedirect();
        $this->assertFalse(VoucherSourceResolver::autoSwitchEnabled());
    }

    /**
     * Trang Cấu hình API phải nói ra lúc đang ghi đè. Nếu không, admin nhìn thấy ganma "Đang phục
     * vụ khách" trong khi mọi lượt quét đi qua kieushopee — và sẽ đi tìm lỗi ở đúng chỗ không có.
     */
    public function test_trang_cau_hinh_api_noi_ro_nguon_nao_dang_thuc_su_chay(): void
    {
        $this->useSource(GanmaService::SOURCE);
        $this->enableAutoSwitch();
        $this->atVn('09:30');

        $this->actingAs($this->createAdmin())->get('/admin/api-config')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('voucherSource.configured', GanmaService::SOURCE)
                ->where('voucherSource.active', KieuShopeeService::SOURCE)
                ->where('voucherSource.fbIgWindowEndsAt', '10:00')
            );
    }

    public function test_ngoai_khung_gio_thi_trang_cau_hinh_api_khong_bao_gi_them(): void
    {
        $this->useSource(GanmaService::SOURCE);
        $this->enableAutoSwitch();
        $this->atVn('12:30');

        $this->actingAs($this->createAdmin())->get('/admin/api-config')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('voucherSource.configured', GanmaService::SOURCE)
                ->where('voucherSource.active', GanmaService::SOURCE)
                ->where('voucherSource.fbIgWindowEndsAt', null)
            );
    }
}
