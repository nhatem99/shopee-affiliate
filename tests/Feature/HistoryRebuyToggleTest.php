<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Công tắc "Mua lại từ lịch sử" (Setting 'history_rebuy_enabled').
 *
 * Cái đáng hỏng ở đây là MẶC ĐỊNH: cờ này quyết định khách có bấm mua thẳng từ mục đã quét cũ
 * được hay không, mà mã trong link cũ thì có thể đã hết lượt/hết hạn. Đặt nhầm mặc định thành
 * bật là lặng lẽ trả lại đúng hành vi vừa bỏ đi, không có gì báo.
 *
 * Cờ chia sẻ toàn cục (HandleInertiaRequests) vì hai trang lịch sử cùng dùng: khối lịch sử ở
 * trang chủ và trang /history.
 */
class HistoryRebuyToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_mac_dinh_tat_khi_admin_chua_dat_gi(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('settings.historyRebuyEnabled', false));
    }

    public function test_admin_bat_tat_duoc_o_trang_cai_dat(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->post('/admin/settings', ['history_rebuy_enabled' => true])->assertRedirect();
        $this->assertTrue(Setting::getBool('history_rebuy_enabled', false));

        $this->actingAs($admin)->post('/admin/settings', ['history_rebuy_enabled' => false])->assertRedirect();
        $this->assertFalse(Setting::getBool('history_rebuy_enabled', false));
    }

    public function test_bat_len_thi_moi_trang_khach_thay_co_bat(): void
    {
        Setting::set('history_rebuy_enabled', '1');

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('settings.historyRebuyEnabled', true));

        $this->actingAs($this->createUser())->get('/history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('settings.historyRebuyEnabled', true));
    }

    public function test_trang_cai_dat_hien_dung_trang_thai_dang_luu(): void
    {
        Setting::set('history_rebuy_enabled', '1');

        $this->actingAs($this->createAdmin())->get('/admin/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('historyRebuyEnabled', true));
    }
}
