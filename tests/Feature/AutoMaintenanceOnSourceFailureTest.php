<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\KieuShopeeService;
use App\Services\SourceHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Tự bật chế độ bảo trì khi nguồn kieushopee chết (SourceHealthService + lệnh kieushopee:health).
 *
 * Đây là thứ TỰ ĐÓNG CỬA HÀNG, nên mấy ranh giới dưới đây đáng khoá lại bằng test hơn là đáng
 * tin vào việc đọc code: mặc định phải tắt, không đóng trang vì một nhịp lỗi lẻ, và tuyệt đối
 * không tự tắt lần bảo trì do admin bật tay.
 */
class AutoMaintenanceOnSourceFailureTest extends TestCase
{
    use RefreshDatabase;

    /** Nguồn trả lời thế nào cũng được, test chỉ quan tâm ok=true/false. */
    private function sourceAnswers(bool ...$answers): void
    {
        $mock = Mockery::mock(KieuShopeeService::class);

        foreach ($answers as $ok) {
            $mock->shouldReceive('testConnection')
                ->once()
                ->ordered()
                ->andReturn(['ok' => $ok, 'message' => $ok ? 'Lấy mã thành công — Áo Hoodie' : 'Không lấy được mã.']);
        }

        $this->instance(KieuShopeeService::class, $mock);
    }

    private function enableSwitch(): void
    {
        Setting::set(SourceHealthService::ENABLED_KEY, '1');
    }

    private function inMaintenance(): bool
    {
        return Setting::getBool('maintenance_mode', false);
    }

    private function check(): array
    {
        return app(SourceHealthService::class)->check();
    }

    /**
     * Mặc định TẮT. Bật sẵn cho mọi cài đặt nghĩa là một hôm nguồn trục trặc thì trang tự đóng
     * mà chủ trang không hề yêu cầu điều đó.
     */
    public function test_mac_dinh_tat(): void
    {
        $this->assertFalse(SourceHealthService::enabled());
    }

    /** Công tắc tắt thì lệnh theo lịch không được gọi sang nguồn, cũng không đụng vào bảo trì. */
    public function test_cong_tac_tat_thi_lenh_khong_goi_nguon(): void
    {
        $mock = Mockery::mock(KieuShopeeService::class);
        $mock->shouldNotReceive('testConnection');
        $this->instance(KieuShopeeService::class, $mock);

        $this->artisan('kieushopee:health')->assertSuccessful();

        $this->assertFalse($this->inMaintenance());
    }

    /** Một nhịp lỗi lẻ (timeout, mạng chớp) không được đá khách đang dán link ra khỏi trang. */
    public function test_loi_mot_lan_chua_dong_trang(): void
    {
        $this->enableSwitch();
        $this->sourceAnswers(false);

        $status = $this->check();

        $this->assertFalse($this->inMaintenance());
        $this->assertSame('cho_them_luot', $status['action']);
        $this->assertSame(1, $status['consecutive_failures']);
    }

    public function test_loi_hai_lan_lien_tiep_thi_tu_bat_bao_tri(): void
    {
        $this->enableSwitch();
        $this->sourceAnswers(false, false);

        $this->check();
        $status = $this->check();

        $this->assertTrue($this->inMaintenance());
        $this->assertSame('da_bat_bao_tri', $status['action']);
        $this->assertTrue(Setting::getBool(SourceHealthService::AUTO_FLAG_KEY, false));
    }

    /** Lỗi rồi OK rồi lỗi lại thì bộ đếm phải về 0, không được cộng dồn qua nhiều giờ. */
    public function test_mot_luot_ok_xen_giua_lam_bo_dem_ve_khong(): void
    {
        $this->enableSwitch();
        $this->sourceAnswers(false, true, false);

        $this->check();
        $this->check();
        $status = $this->check();

        $this->assertFalse($this->inMaintenance());
        $this->assertSame(1, $status['consecutive_failures']);
    }

    public function test_nguon_song_lai_thi_tu_tat_bao_tri(): void
    {
        $this->enableSwitch();
        $this->sourceAnswers(false, false, true);

        $this->check();
        $this->check();
        $this->assertTrue($this->inMaintenance());

        $status = $this->check();

        $this->assertFalse($this->inMaintenance());
        $this->assertSame('da_tat_bao_tri', $status['action']);
    }

    /**
     * Ranh giới quan trọng nhất: admin bật bảo trì tay (đang sửa gì đó) mà nguồn tình cờ sống
     * thì KHÔNG được mở cửa hộ — làm thế là đẩy khách vào giữa lúc admin đang sửa.
     */
    public function test_khong_tat_ho_lan_bao_tri_do_admin_bat_tay(): void
    {
        $this->enableSwitch();
        Setting::set('maintenance_mode', '1');
        $this->sourceAnswers(true);

        $status = $this->check();

        $this->assertTrue($this->inMaintenance());
        $this->assertNull($status['action']);
    }

    /** Admin gạt công tắc bảo trì ở trang Cài đặt = giành lại quyền, kể cả khi máy vừa bật. */
    public function test_admin_gat_tay_thi_he_thong_khong_tat_ho_nua(): void
    {
        $this->enableSwitch();
        $this->sourceAnswers(false, false, true);
        $this->check();
        $this->check();
        $this->assertTrue($this->inMaintenance());

        // Admin vào /admin/settings bật lại công tắc bảo trì (giữ đóng, tự tay).
        $this->actingAs($this->createAdmin())
            ->post('/admin/settings', ['maintenance_mode' => true])
            ->assertRedirect();

        $this->check();

        $this->assertTrue($this->inMaintenance());
        $this->assertFalse(Setting::getBool(SourceHealthService::AUTO_FLAG_KEY, false));
    }

    /** Tắt công tắc tự động trong lúc trang đang bảo trì tự động = mở lại trang ngay. */
    public function test_tat_cong_tac_thi_mo_lai_trang_dang_bao_tri_tu_dong(): void
    {
        $this->enableSwitch();
        $this->sourceAnswers(false, false);
        $this->check();
        $this->check();
        $this->assertTrue($this->inMaintenance());

        $this->actingAs($this->createAdmin())
            ->post('/admin/settings', ['auto_maintenance_enabled' => false])
            ->assertRedirect();

        $this->assertFalse($this->inMaintenance());
        $this->assertFalse(SourceHealthService::enabled());
    }

    /** Ngược lại: tắt công tắc KHÔNG được mở hộ lần bảo trì do admin bật tay. */
    public function test_tat_cong_tac_khong_dung_vao_bao_tri_cua_admin(): void
    {
        $this->enableSwitch();
        Setting::set('maintenance_mode', '1');

        $this->actingAs($this->createAdmin())
            ->post('/admin/settings', ['auto_maintenance_enabled' => false])
            ->assertRedirect();

        $this->assertTrue($this->inMaintenance());
    }
}
