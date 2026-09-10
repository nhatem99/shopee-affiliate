<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Setting;
use App\Models\ShopeeOrder;
use App\Services\CashbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Đường đi đầy đủ của tiền, từ file CSV admin tải lên tới số dư khách rút được.
 */
class AdminShopeeReportTest extends TestCase
{
    use RefreshDatabase;

    private function report(?string $body = null): UploadedFile
    {
        $body ??= file_get_contents(base_path('tests/Fixtures/shopee-report.csv'));

        return UploadedFile::fake()->createWithContent('bao-cao.csv', "\xEF\xBB\xBF".$body);
    }

    // ── Cửa vào ───────────────────────────────────────────────────────────────

    public function test_admin_can_open_the_page(): void
    {
        $this->actingAs($this->createAdmin())
            ->get('/admin/shopee-orders')
            ->assertOk();
    }

    public function test_regular_user_cannot_open_the_page(): void
    {
        $this->actingAs($this->createUser())
            ->get('/admin/shopee-orders')
            ->assertForbidden();
    }

    public function test_guest_cannot_import(): void
    {
        $this->post('/admin/shopee-orders/import', ['report' => $this->report()])
            ->assertRedirect('/login');

        $this->assertSame(0, ShopeeOrder::count());
    }

    public function test_regular_user_cannot_import(): void
    {
        $this->actingAs($this->createUser())
            ->post('/admin/shopee-orders/import', ['report' => $this->report()])
            ->assertForbidden();

        $this->assertSame(0, ShopeeOrder::count());
    }

    // ── Nhập file ─────────────────────────────────────────────────────────────

    public function test_admin_can_import_a_report(): void
    {
        $this->actingAs($this->createAdmin())
            ->post('/admin/shopee-orders/import', ['report' => $this->report()])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(5, ShopeeOrder::count());
    }

    /**
     * Nhầm file phải hiện lỗi đọc được chứ không phải trang trắng hay "đã nhập 0 dòng" —
     * "0 dòng" trông y hệt một kỳ không có đơn nào.
     */
    public function test_a_wrong_file_shows_a_readable_error(): void
    {
        $this->actingAs($this->createAdmin())
            ->post('/admin/shopee-orders/import', [
                'report' => UploadedFile::fake()->createWithContent('linh-tinh.csv', "Ngày,Số tiền\n2026-09-01,1000\n"),
            ])
            ->assertSessionHasErrors('report');

        $this->assertSame(0, ShopeeOrder::count());
    }

    public function test_import_requires_a_file(): void
    {
        $this->actingAs($this->createAdmin())
            ->post('/admin/shopee-orders/import', [])
            ->assertSessionHasErrors('report');
    }

    // ── Từ file tới ví ────────────────────────────────────────────────────────

    /** Nhập xong là tiền vào ví luôn, admin không phải bấm thêm bước nào. */
    public function test_importing_pays_the_matching_customer_straight_away(): void
    {
        Setting::set(CashbackService::RATE_KEY, '50');

        $customer = $this->createUser();
        $customer->forceFill(['sub_id' => 'u7k2m9'])->save();

        $this->actingAs($this->createAdmin())
            ->post('/admin/shopee-orders/import', ['report' => $this->report()]);

        // Chỉ đơn 260827EWCNNXPJ ở trạng thái Hoàn thành: 16900 ròng, hoàn 50%.
        $commission = Commission::where('order_id', '260827EWCNNXPJ')->first();

        $this->assertNotNull($commission);
        $this->assertSame('8450.00', $commission->amount);
        $this->assertSame('approved', $commission->status);
        $this->assertEqualsWithDelta(8450.0, $customer->fresh()->availableBalance(), 0.001);
    }

    /** Đơn đang chờ và đơn huỷ trong cùng file đó không được sinh ra đồng nào. */
    public function test_only_completed_orders_turn_into_money(): void
    {
        Setting::set(CashbackService::RATE_KEY, '50');

        $customer = $this->createUser();
        $customer->forceFill(['sub_id' => 'u7k2m9'])->save();

        $this->actingAs($this->createAdmin())
            ->post('/admin/shopee-orders/import', ['report' => $this->report()]);

        $this->assertSame(1, Commission::count());
        $this->assertSame(0, Commission::where('order_id', '260909JA8XKJ9J')->count());
    }

    public function test_import_without_a_rate_pays_nothing_and_says_so(): void
    {
        $customer = $this->createUser();
        $customer->forceFill(['sub_id' => 'u7k2m9'])->save();

        $response = $this->actingAs($this->createAdmin())
            ->post('/admin/shopee-orders/import', ['report' => $this->report()]);

        $this->assertSame(0, Commission::count());
        $this->assertStringContainsString('CHƯA đặt tỉ lệ', session('success'));
        $response->assertSessionHas('success');
    }

    // ── Tỉ lệ ở trang Cài đặt ─────────────────────────────────────────────────

    /**
     * Đặt tỉ lệ SAU khi đã nhập báo cáo phải áp ngay cho đơn cũ. Nếu không, admin đặt tỉ lệ lần
     * đầu sẽ thấy không có gì xảy ra và tưởng chức năng hỏng.
     */
    public function test_setting_the_rate_pays_out_orders_already_imported(): void
    {
        $customer = $this->createUser();
        $customer->forceFill(['sub_id' => 'u7k2m9'])->save();
        $admin = $this->createAdmin();

        $this->actingAs($admin)->post('/admin/shopee-orders/import', ['report' => $this->report()]);
        $this->assertSame(0, Commission::count());

        $this->actingAs($admin)->post('/admin/settings', ['cashback_rate' => 50]);

        $this->assertSame('8450.00', Commission::where('order_id', '260827EWCNNXPJ')->first()->amount);
    }

    public function test_changing_the_rate_recalculates_existing_commissions(): void
    {
        $customer = $this->createUser();
        $customer->forceFill(['sub_id' => 'u7k2m9'])->save();
        $admin = $this->createAdmin();

        $this->actingAs($admin)->post('/admin/shopee-orders/import', ['report' => $this->report()]);
        $this->actingAs($admin)->post('/admin/settings', ['cashback_rate' => 50]);
        $this->actingAs($admin)->post('/admin/settings', ['cashback_rate' => 20]);

        $this->assertSame('3380.00', Commission::where('order_id', '260827EWCNNXPJ')->first()->amount);
    }

    public function test_rate_above_one_hundred_is_rejected(): void
    {
        $this->actingAs($this->createAdmin())
            ->post('/admin/settings', ['cashback_rate' => 150])
            ->assertSessionHasErrors('cashback_rate');

        $this->assertSame(0.0, app(CashbackService::class)->rate());
    }

    public function test_negative_rate_is_rejected(): void
    {
        $this->actingAs($this->createAdmin())
            ->post('/admin/settings', ['cashback_rate' => -10])
            ->assertSessionHasErrors('cashback_rate');
    }

    /** Lưu một cài đặt khác không được vô tình xoá tỉ lệ đang chạy. */
    public function test_saving_another_setting_leaves_the_rate_alone(): void
    {
        Setting::set(CashbackService::RATE_KEY, '50');

        $this->actingAs($this->createAdmin())
            ->post('/admin/settings', ['maintenance_mode' => true]);

        $this->assertSame(50.0, app(CashbackService::class)->rate());
    }
}
