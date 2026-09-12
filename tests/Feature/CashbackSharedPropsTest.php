<?php

namespace Tests\Feature;

use App\Http\Controllers\ProfileController;
use App\Models\Setting;
use App\Services\CashbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `settings.cashbackRate` là CÔNG TẮC của toàn bộ nội dung quảng bá hoàn tiền trên giao diện
 * khách: composable useCashback đọc đúng prop này, và mọi khối nội dung đều bọc `cashbackOn`.
 *
 * Prop này mà vắng mặt hoặc đọc nhầm khoá thì frontend nhận `undefined` → về 0 → nội dung hoàn
 * tiền BIẾN MẤT trên toàn site mà không có lỗi nào cả. Ngược lại, để nó lọt giá trị > 0 trong
 * khi admin đã tắt chương trình thì trang web đi hứa hoàn tiền trong lúc CashbackService::sync()
 * trả 0đ cho tất cả mọi người. Hai hướng hỏng đều âm thầm, nên khoá lại bằng test.
 */
class CashbackSharedPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_trang_chu_chia_se_ti_le_hoan_tien_va_muc_rut_toi_thieu(): void
    {
        Setting::set(CashbackService::RATE_KEY, '35');

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('settings.cashbackRate', 35)
                ->where('settings.minWithdrawal', ProfileController::MIN_WITHDRAWAL)
            );
    }

    public function test_chua_dat_ti_le_thi_tra_ve_0_de_giao_dien_tu_an_noi_dung_hoan_tien(): void
    {
        // Không đặt setting nào — đây đúng là trạng thái mặc định của một hệ thống mới dựng.
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('settings.cashbackRate', 0));
    }

    public function test_ti_le_duoc_chia_se_duoi_dang_so_chu_khong_phai_chuoi(): void
    {
        // Setting::get() trả về CHUỖI từ DB. Lọt chuỗi '0' xuống frontend là hỏng nặng: trong
        // JavaScript '0' > 0 cho ra false nhưng Boolean('0') lại là true, nên chỉ cần một chỗ
        // nào đó kiểm tra kiểu truthy thay vì so sánh > 0 là nội dung hoàn tiền hiện lên trong
        // lúc chương trình đang tắt.
        Setting::set(CashbackService::RATE_KEY, '0');

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('settings.cashbackRate', 0));
    }

    public function test_trang_giai_thich_hoan_tien_mo_duoc_khi_dang_bat(): void
    {
        Setting::set(CashbackService::RATE_KEY, '30');

        $this->get('/hoan-tien')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Cashback'));
    }

    public function test_tat_hoan_tien_thi_trang_giai_thich_bien_mat(): void
    {
        // Không để tồn tại một trang công khai nói về chương trình mà hệ thống đang trả 0đ —
        // thanh điều hướng dưới cũng tự ẩn mục đó, nhưng ai gõ thẳng URL vẫn phải bị chặn.
        Setting::set(CashbackService::RATE_KEY, '0');

        $this->get('/hoan-tien')->assertNotFound();
    }
}
