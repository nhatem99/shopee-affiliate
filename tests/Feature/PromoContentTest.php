<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Models\Setting;
use App\Models\User;
use App\Services\CashbackService;
use App\Services\PromoContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kho mẫu bài đăng: admin copy thẳng từ đây ra Facebook/Zalo, nên mọi con số lọt ra đều là
 * CAM KẾT với người tiêu dùng. Ba thứ phải khoá bằng test vì hỏng cái nào cũng âm thầm:
 *  1. Token chưa thay thế lọt ra ngoài — admin đăng nguyên chuỗi "{{ cashbackRate }}".
 *  2. Tỉ lệ hoàn tiền trong bài lệch với tỉ lệ hệ thống thật sự trả.
 *  3. Bài nhắc hoàn tiền vẫn hiện khi chương trình đã tắt — hứa suông.
 */
class PromoContentTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PromoContentService
    {
        return app(PromoContentService::class);
    }

    public function test_khong_con_token_nao_chua_thay_the_trong_moi_mau(): void
    {
        Setting::set(CashbackService::RATE_KEY, '30');

        foreach ($this->service()->templates() as $template) {
            $this->assertDoesNotMatchRegularExpression(
                '/\{\{.*?\}\}/',
                $template['body'],
                "Mẫu {$template['id']} còn token chưa được bơm giá trị.",
            );
        }
    }

    public function test_ti_le_hoan_tien_trong_bai_dung_bang_ti_le_he_thong(): void
    {
        Setting::set(CashbackService::RATE_KEY, '30');

        $body = collect($this->service()->templates())->firstWhere('id', 'fb-group-cashback')['body'];

        $this->assertStringContainsString('30%', $body);
        // Chốt chặn cho đúng thứ đã bị yêu cầu một lần rồi: KHÔNG được cộng thêm điểm nào vào
        // con số hiển thị. Đặt 30 mà bài ghi 40 là nói sai với người tiêu dùng về số tiền.
        $this->assertStringNotContainsString('40%', $body);
    }

    /** Bài mẫu phải nói cùng một số với trang chủ — tức số HIỂN THỊ, khi admin đặt riêng. */
    public function test_bai_mau_dung_ti_le_hien_thi_khi_admin_dat_rieng(): void
    {
        Setting::set(CashbackService::RATE_KEY, '50');
        Setting::set(CashbackService::DISPLAY_RATE_KEY, '60');

        $body = collect($this->service()->templates())->firstWhere('id', 'fb-group-cashback')['body'];

        $this->assertStringContainsString('lên đến 60%', $body);
        $this->assertStringNotContainsString('50%', $body);
    }

    public function test_tat_hoan_tien_thi_moi_mau_nhac_hoan_tien_bien_mat(): void
    {
        Setting::set(CashbackService::RATE_KEY, '0');

        $templates = $this->service()->templates();

        $this->assertNotEmpty($templates, 'Vẫn phải còn mẫu chỉ nói về mã giảm giá.');

        foreach ($templates as $template) {
            $this->assertStringNotContainsStringIgnoringCase(
                'hoàn tiền',
                $template['body'],
                "Mẫu {$template['id']} còn nhắc hoàn tiền trong khi chương trình đang tắt.",
            );
            $this->assertStringNotContainsStringIgnoringCase('chia lại', $template['body']);
        }
    }

    public function test_chua_dat_muc_giam_thi_an_bai_quang_cao_muc_giam(): void
    {
        Setting::set(PromoContentService::TOP_PERCENT_KEY, '0');
        Setting::set(PromoContentService::SECOND_PERCENT_KEY, '0');

        $ids = collect($this->service()->templates())->pluck('id');

        $this->assertNotContains('fb-group-codes', $ids);
    }

    public function test_chua_dat_link_cong_dong_thi_cat_han_dong_do(): void
    {
        Setting::set(CashbackService::RATE_KEY, '30');
        Setting::set('community_url', '');

        $body = collect($this->service()->templates())->firstWhere('id', 'fb-group-cashback')['body'];

        // Không được để lại dòng cụt "👥 Nhóm săn sale của tụi mình:" với link trống phía sau.
        $this->assertStringNotContainsString('Nhóm săn sale của tụi mình', $body);
    }

    public function test_dat_link_cong_dong_thi_dong_do_xuat_hien_lai(): void
    {
        Setting::set(CashbackService::RATE_KEY, '30');
        Setting::set('community_url', 'https://zalo.me/g/abcxyz');

        $body = collect($this->service()->templates())->firstWhere('id', 'fb-group-cashback')['body'];

        $this->assertStringContainsString('https://zalo.me/g/abcxyz', $body);
    }

    /**
     * Bài đăng phải mô tả ĐÚNG luồng đang bật, vì admin copy nó ra cho người lạ làm theo.
     * Dạy "bấm nút Mua ngay" trong khi nút thật tên "Lấy mã qua Facebook" là khách nhìn quanh
     * không thấy nút nào như vậy rồi bỏ — và ngược lại, dạy chờ Facebook mở ra trong khi luồng
     * đi thẳng Shopee cũng sai y như thế.
     */
    public function test_mau_mo_ta_dung_luong_khi_tat_chuyen_huong_facebook(): void
    {
        Setting::set(CashbackService::RATE_KEY, '30');

        $body = collect($this->service()->templates())->firstWhere('id', 'zalo-close')['body'];

        $this->assertStringContainsString('Mua ngay (đã áp mã)', $body);
        $this->assertStringNotContainsString('Lấy mã qua Facebook', $body);
    }

    public function test_mau_doi_sang_luong_facebook_khi_bat_chuyen_huong(): void
    {
        Setting::set(CashbackService::RATE_KEY, '30');

        ApiConfig::create([
            'name' => 'Facebook',
            'endpoint' => 'https://graph.facebook.com',
            'platform' => 'facebook',
            'app_id' => '111222',
            'app_secret' => 'page-token',
            'is_active' => true,
            'meta' => ['comment_redirect_enabled' => true, 'target_post_id' => '111222_333444'],
        ]);

        $body = collect($this->service()->templates())->firstWhere('id', 'zalo-close')['body'];

        $this->assertStringContainsString('Lấy mã qua Facebook', $body);
        $this->assertStringContainsString('bình luận', $body);
    }

    public function test_tat_dang_ky_khach_thi_an_mau_hoan_tien(): void
    {
        // Middleware customer.auth.enabled chuyển /register về trang chủ, nên người mới đọc bài
        // không tạo được tài khoản — mà không có tài khoản thì không thể nhận hoàn tiền.
        Setting::set(CashbackService::RATE_KEY, '30');
        Setting::set('customer_auth_enabled', '0');

        $this->assertFalse($this->service()->cashbackOn());

        $ids = collect($this->service()->templates())->pluck('id');
        $this->assertNotContains('fb-group-cashback', $ids);
    }

    public function test_admin_xem_duoc_trang_va_khach_thi_khong(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();

        $this->actingAs($admin)->get('/admin/promo')->assertOk();

        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/admin/promo')->assertForbidden();
    }

    public function test_admin_luu_duoc_muc_giam_dung_trong_bai(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();

        $this->actingAs($admin)
            ->post('/admin/promo', ['top_percent' => 25, 'second_percent' => 22])
            ->assertRedirect();

        $this->assertSame(25.0, $this->service()->topPercent());
        $this->assertSame(22.0, $this->service()->secondPercent());
    }

    public function test_muc_giam_ngoai_khoang_bi_tu_choi(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();

        $this->actingAs($admin)
            ->post('/admin/promo', ['top_percent' => 140])
            ->assertSessionHasErrors('top_percent');
    }
}
