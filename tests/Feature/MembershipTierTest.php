<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Setting;
use App\Models\ShopeeOrder;
use App\Models\User;
use App\Services\CashbackService;
use App\Services\MembershipTierService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hạng thành viên đụng thẳng vào tiền: mỗi hạng cộng tới 5 điểm phần trăm vào tỉ lệ hoàn của
 * từng đơn. Thứ tự các test dưới đây theo mức thiệt hại nếu sai:
 *
 *  1. Tiền đã vào ví bị tính lại theo hạng mới (sổ sách lệch, khách rút được tiền không có thật).
 *  2. Hạng nhảy giữa quý (khách mất/được đặc quyền mà không hiểu vì sao).
 *  3. Tiền cho không (thưởng người mới) đẩy hạng.
 */
class MembershipTierTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();
        Setting::set(CashbackService::RATE_KEY, '50');
    }

    private function tiers(): MembershipTierService
    {
        return app(MembershipTierService::class);
    }

    /** Một khoản hoàn tiền đã duyệt, ghi nhận vào ví tại thời điểm chỉ định. */
    private function credit(float $amount, CarbonImmutable $at, array $attributes = []): Commission
    {
        static $n = 0;
        $n++;

        return Commission::create(array_merge([
            'user_id' => $this->user->id,
            'affiliate_link_id' => null,
            'type' => Commission::TYPE_CASHBACK,
            'order_id' => 'CREDIT'.$n,
            'amount' => $amount,
            'status' => 'approved',
            'confirmed_at' => $at,
        ], $attributes));
    }

    private function line(string $orderId, float $netCommission): ShopeeOrder
    {
        return ShopeeOrder::create([
            'order_id' => $orderId,
            'item_id' => 'ITEM'.$orderId,
            'model_id' => '',
            'status' => 'completed',
            'net_commission' => $netCommission,
            'user_id' => $this->user->id,
            'user_sub_id' => $this->user->sub_id,
        ]);
    }

    private function setTier(string $tier): void
    {
        $this->user->forceFill(['tier' => $tier])->save();
    }

    public function test_tien_da_vao_vi_khong_bi_tinh_lai_khi_khach_len_hang(): void
    {
        // Ghi tiền lúc còn Tân binh: 50% của 10.000 = 5.000.
        $this->line('A', 10000);
        app(CashbackService::class)->sync();

        $this->assertSame('5000.00', Commission::where('order_id', 'A')->value('amount'));

        // Lên Kim cương rồi chạy lại: sync() tính lại TOÀN BỘ đơn, nên đây đúng là chỗ tiền cũ
        // dễ tự phình lên theo đặc quyền của quý sau.
        $this->setTier('kim_cuong');
        app(CashbackService::class)->sync();

        $this->assertSame('5000.00', Commission::where('order_id', 'A')->value('amount'));

        // Đơn mới thì được hưởng hạng mới: 55% của 10.000.
        $this->line('B', 10000);
        app(CashbackService::class)->sync();

        $this->assertSame('5500.00', Commission::where('order_id', 'B')->value('amount'));
        $this->assertSame('5.00', Commission::where('order_id', 'B')->value('tier_bonus_rate'));
    }

    public function test_hang_cong_diem_phan_tram_vao_ti_le_hoan(): void
    {
        $this->setTier('vang');

        $this->line('A', 10000);
        app(CashbackService::class)->sync();

        // 50% nền + 3 điểm của hạng Vàng = 53% của 10.000.
        $this->assertSame('5300.00', Commission::where('order_id', 'A')->value('amount'));
    }

    public function test_tat_chuong_trinh_hang_la_het_thuong_them(): void
    {
        Setting::set(MembershipTierService::ENABLED_KEY, '0');
        $this->setTier('kim_cuong');

        $this->line('A', 10000);
        app(CashbackService::class)->sync();

        $this->assertSame('5000.00', Commission::where('order_id', 'A')->value('amount'));
    }

    public function test_ti_le_hoan_cong_thuong_hang_khong_vuot_qua_100_phan_tram(): void
    {
        // Trả quá 100% hoa hồng ròng là trả nhiều hơn số Shopee đưa cho mình.
        Setting::set(CashbackService::RATE_KEY, '98');
        $this->setTier('kim_cuong');

        $this->line('A', 10000);
        app(CashbackService::class)->sync();

        $this->assertSame('10000.00', Commission::where('order_id', 'A')->value('amount'));
    }

    public function test_hang_xet_theo_tong_cua_quy_truoc(): void
    {
        $now = CarbonImmutable::create(2026, 8, 15);
        $previousQuarter = $now->startOfQuarter()->subQuarter();

        $this->credit(250000, $previousQuarter->addDays(10));

        $this->tiers()->refresh($this->user->fresh(), $now);

        $this->assertSame('dong', $this->user->fresh()->tier);
        $this->assertSame('2026-Q3', $this->user->fresh()->tier_quarter);
    }

    public function test_tien_hoan_cua_quy_nay_chi_doi_hang_tu_quy_sau(): void
    {
        $now = CarbonImmutable::create(2026, 8, 15);

        // 600.000đ trong chính quý này — thừa sức chạm hạng Bạc.
        $this->credit(600000, $now->subDays(5));

        $this->tiers()->refresh($this->user->fresh(), $now);
        $this->assertSame('tan_binh', $this->user->fresh()->tier, 'Hạng không được nhảy giữa quý');

        // Nhưng khách phải thấy trước mình sẽ lên hạng gì, không thì tích luỹ trong mù mịt.
        $progress = $this->tiers()->progressFor($this->user->fresh(), $now);

        $this->assertSame('tan_binh', $progress['current']['key']);
        $this->assertSame('bac', $progress['pending']['key']);
        $this->assertSame('vang', $progress['next']['key']);
        $this->assertSame(400000.0, $progress['to_next']);
        $this->assertSame('01/10/2026', $progress['next_review']);
    }

    public function test_thuong_nguoi_moi_khong_duoc_tinh_vao_hang(): void
    {
        $now = CarbonImmutable::create(2026, 8, 15);
        $previousQuarter = $now->startOfQuarter()->subQuarter();

        // Tiền cho không: ai đăng ký cũng có, để nó đẩy hạng là biếu không đặc quyền.
        $this->credit(900000, $previousQuarter->addDays(10), [
            'type' => Commission::TYPE_WELCOME_BONUS,
            'order_id' => 'WELCOME-'.$this->user->id,
        ]);

        $this->tiers()->refresh($this->user->fresh(), $now);

        $this->assertSame('tan_binh', $this->user->fresh()->tier);
    }

    public function test_lenh_xet_hang_nang_va_ha_hang_ca_bang(): void
    {
        $now = CarbonImmutable::now();
        $previousQuarter = $now->toImmutable()->startOfQuarter()->subQuarter();

        $this->credit(3500000, $previousQuarter->addDays(10));

        // Khách thứ hai đang mang hạng cao còn sót lại từ một quý cũ, quý trước không mua gì.
        $stale = $this->createUser();
        $stale->forceFill(['tier' => 'kim_cuong', 'tier_quarter' => '2025-Q1'])->save();

        $this->artisan('tiers:refresh')->assertSuccessful();

        $this->assertSame('kim_cuong', $this->user->fresh()->tier);
        $this->assertSame('tan_binh', $stale->fresh()->tier, 'Không mua nữa thì phải tụt hạng');
    }

    public function test_admin_khong_duoc_xep_hang(): void
    {
        // Admin tự mua để kiểm thử là tài khoản dễ đạt hạng cao nhất của một hệ thống mới dựng.
        $admin = $this->createAdmin();

        $this->artisan('tiers:refresh')->assertSuccessful();

        $this->assertNull($admin->fresh()->tier);
    }

    public function test_trang_hoan_tien_va_trang_chu_dua_ra_bang_hang(): void
    {
        $this->get('/hoan-tien')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Cashback')
                ->has('membershipTiers.tiers', 6)
                ->where('membershipTiers.tiers.0.key', 'tan_binh')
                ->where('membershipTiers.tiers.5.bonus', 5)
                ->where('membershipTiers.me', null));

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('membershipTiers.tiers', 6));
    }

    public function test_tat_hoan_tien_la_bang_hang_bien_mat_khoi_trang_chu(): void
    {
        // Tỉ lệ nền = 0 nghĩa là không ai được chia đồng nào, lúc đó "thưởng thêm 5%" là thưởng
        // thêm trên số 0 — hiện bảng hạng trong trạng thái đó là hứa suông.
        Setting::set(CashbackService::RATE_KEY, '0');

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('membershipTiers', null));
    }

    public function test_trang_tai_khoan_hien_tien_do_len_hang(): void
    {
        $this->setTier('dong');
        $this->credit(300000, CarbonImmutable::now()->subDay());

        $this->actingAs($this->user->fresh())->get('/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Profile')
                ->where('tier.current.key', 'dong')
                ->where('tier.current.bonus', 1)
                ->where('tier.earned', 300000)
                ->where('tier.next.key', 'bac'));
    }

    public function test_trang_don_hang_noi_ro_ti_le_da_gom_thuong_hang(): void
    {
        $this->setTier('vang');
        $this->line('A', 10000);

        $this->actingAs($this->user->fresh())->get('/don-hang')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders')
                ->where('cashbackRate', 53)
                ->where('tierBonus.bonus', 3)
                ->where('tierBonus.label', 'Vàng'));
    }
}
