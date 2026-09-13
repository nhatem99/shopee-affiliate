<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Setting;
use App\Models\User;
use App\Services\CashbackLeaderboardService;
use App\Services\CashbackService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Bảng vàng là thứ công khai trước người lạ, nên hai chiều hỏng đều đáng test: khoe tiền chưa
 * có thật (đơn chưa duyệt, tháng khác, admin test) và lộ danh tính (tên đầy đủ, user_id).
 */
class CashbackLeaderboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set(CashbackService::RATE_KEY, '50');
        Cache::flush();
    }

    private function credit(User $user, float $amount, ?CarbonImmutable $at = null, string $status = 'approved'): Commission
    {
        static $n = 0;
        $n++;

        return Commission::create([
            'user_id' => $user->id,
            'affiliate_link_id' => null,
            'order_id' => 'LB'.$n,
            'amount' => $amount,
            'status' => $status,
            'confirmed_at' => $at ?? now(),
        ]);
    }

    private function board(?User $viewer = null): array
    {
        return app(CashbackLeaderboardService::class)->forMonth($viewer);
    }

    public function test_xep_theo_tong_tien_trong_thang_va_che_ten(): void
    {
        $a = $this->createUser(['name' => 'Nguyễn Văn An']);
        $b = $this->createUser(['name' => 'Trần Bình']);

        $this->credit($a, 10000);
        $this->credit($a, 5000);
        $this->credit($b, 20000);

        $board = $this->board();

        $this->assertSame(2, $board['total_users']);
        $this->assertSame(35000.0, $board['total_amount']);
        $this->assertSame([1, 2], array_column($board['entries'], 'rank'));
        $this->assertSame('Trần B***', $board['entries'][0]['name']);
        $this->assertSame(20000.0, $board['entries'][0]['amount']);
        $this->assertSame('Nguyễn V*** A***', $board['entries'][1]['name']);
        $this->assertSame(15000.0, $board['entries'][1]['amount']);
        $this->assertSame(2, $board['entries'][1]['orders']);
        $this->assertArrayNotHasKey('user_id', $board['entries'][0]);
    }

    public function test_chi_tinh_tien_da_vao_vi_trong_thang_hien_tai(): void
    {
        $user = $this->createUser();

        $this->credit($user, 1000, CarbonImmutable::now()->subMonth());   // tháng trước
        $this->credit($user, 2000, null, 'pending');                        // chưa duyệt
        $this->credit($user, 3000, null, 'paid');                           // đã rút — vẫn tính
        $this->credit($user, 4000);

        $board = $this->board();

        $this->assertCount(1, $board['entries']);
        $this->assertSame(7000.0, $board['entries'][0]['amount']);
    }

    public function test_admin_va_tai_khoan_bi_khoa_khong_len_bang(): void
    {
        $admin = $this->createAdmin();
        $banned = $this->createUser();
        $banned->forceFill(['banned_at' => now()])->save();
        $ok = $this->createUser();

        $this->credit($admin, 99000);
        $this->credit($banned, 88000);
        $this->credit($ok, 1000);

        $board = $this->board();

        $this->assertCount(1, $board['entries']);
        $this->assertSame(1000.0, $board['entries'][0]['amount']);
        $this->assertSame(1, $board['total_users']);
    }

    public function test_gioi_han_top_10_nhung_tong_thang_tinh_tat_ca(): void
    {
        foreach (range(1, 12) as $i) {
            $this->credit($this->createUser(), $i * 1000);
        }

        $board = $this->board();

        $this->assertCount(CashbackLeaderboardService::LIMIT, $board['entries']);
        $this->assertSame(12000.0, $board['entries'][0]['amount']);
        $this->assertSame(12, $board['total_users']);
        $this->assertSame(78000.0, $board['total_amount']);
    }

    public function test_nguoi_xem_thay_hang_cua_minh_ke_ca_khi_ngoai_top(): void
    {
        foreach (range(1, 10) as $i) {
            $this->credit($this->createUser(), $i * 1000 + 500);
        }
        $me = $this->createUser();
        $this->credit($me, 1200);

        $board = $this->board($me);

        $this->assertSame(11, $board['me']['rank']);
        $this->assertSame(1200.0, $board['me']['amount']);
        $this->assertFalse(collect($board['entries'])->contains('is_me', true));
    }

    public function test_dong_cua_nguoi_xem_trong_top_duoc_danh_dau(): void
    {
        $other = $this->createUser();
        $me = $this->createUser();
        $this->credit($other, 5000);
        $this->credit($me, 9000);

        $board = $this->board($me);

        $this->assertTrue($board['entries'][0]['is_me']);
        $this->assertFalse($board['entries'][1]['is_me']);
        $this->assertSame(1, $board['me']['rank']);

        // Cùng bảng cache nhưng người xem khác thì không được thấy dấu của người trước.
        $board = $this->board($other);
        $this->assertFalse($board['entries'][0]['is_me']);
        $this->assertTrue($board['entries'][1]['is_me']);
        $this->assertSame(2, $board['me']['rank']);
    }

    public function test_chua_co_tien_thang_nay_thi_me_la_null(): void
    {
        $me = $this->createUser();

        $this->assertNull($this->board($me)['me']);
        $this->assertNull($this->board()['me']);
    }

    public function test_trang_chu_va_hoan_tien_gui_bang_khi_dang_bat(): void
    {
        $this->credit($this->createUser(['name' => 'Lê Thị Hoa']), 7000);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('leaderboard.entries.0.name', 'Lê T*** H***')
                ->where('leaderboard.total_users', 1)
            );

        $this->get('/hoan-tien')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('leaderboard.entries.0.amount', 7000));
    }

    public function test_tat_hoan_tien_thi_trang_chu_khong_gui_bang(): void
    {
        Setting::set(CashbackService::RATE_KEY, '0');

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('leaderboard', null));
    }

    public function test_sync_xoa_cache_de_bang_cap_nhat_ngay(): void
    {
        $user = $this->createUser();
        $this->assertSame([], $this->board()['entries']);

        $this->credit($user, 3000);
        // Chưa sync thì cache vẫn giữ bảng trống.
        $this->assertSame([], $this->board()['entries']);

        app(CashbackService::class)->sync();

        $this->assertSame(3000.0, $this->board()['entries'][0]['amount']);
    }

    public function test_mac_dinh_khong_hien_so_lieu_minh_hoa(): void
    {
        $board = $this->board();

        $this->assertSame([], $board['entries']);
        $this->assertFalse($board['demo']);
    }

    public function test_bat_minh_hoa_thi_bang_trong_hien_nguoi_mau_co_gan_co_demo(): void
    {
        Setting::set(CashbackLeaderboardService::DEMO_KEY, '1');

        $board = $this->board($this->createUser());

        $this->assertTrue($board['demo']);
        $this->assertNotEmpty($board['entries']);
        $this->assertSame(1, $board['entries'][0]['rank']);
        $this->assertFalse($board['entries'][0]['is_me']);
        $this->assertSame(count($board['entries']), $board['total_users']);
        $this->assertNull($board['me']);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('leaderboard.demo', true));
    }

    public function test_co_nguoi_that_thi_minh_hoa_tu_bien_mat(): void
    {
        Setting::set(CashbackLeaderboardService::DEMO_KEY, '1');
        $this->credit($this->createUser(['name' => 'Lê Thị Hoa']), 7000);

        $board = $this->board();

        $this->assertFalse($board['demo']);
        $this->assertCount(1, $board['entries']);
        $this->assertSame('Lê T*** H***', $board['entries'][0]['name']);
    }

    public function test_admin_bat_tat_duoc_minh_hoa_o_trang_cai_dat(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->post('/admin/settings', ['leaderboard_demo' => true])->assertRedirect();
        $this->assertTrue(Setting::getBool(CashbackLeaderboardService::DEMO_KEY, false));

        $this->actingAs($admin)->post('/admin/settings', ['leaderboard_demo' => false])->assertRedirect();
        $this->assertFalse(Setting::getBool(CashbackLeaderboardService::DEMO_KEY, false));
    }

    public function test_che_ten(): void
    {
        $this->assertSame('Nguyễn V*** A***', CashbackLeaderboardService::maskName('  Nguyễn   Văn An '));
        $this->assertSame('ho***', CashbackLeaderboardService::maskName('hoangvu'));
        $this->assertSame('A***', CashbackLeaderboardService::maskName('An'));
        $this->assertSame('Ẩn danh', CashbackLeaderboardService::maskName('   '));
    }
}
