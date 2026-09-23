<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Commission;
use App\Models\PayoutAccount;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\CheckInRewardNotification;
use App\Services\DailyCheckInService;
use App\Services\MembershipTierService;
use App\Services\WalletHistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyCheckInTest extends TestCase
{
    use RefreshDatabase;

    private function service(): DailyCheckInService
    {
        return app(DailyCheckInService::class);
    }

    /**
     * Ghi sẵn một lượt điểm danh trong quá khứ. Dựng chuỗi bằng cách này thay vì tua đồng hồ:
     * chuỗi ngày chỉ đọc cột checked_on, nên đặt thẳng cột đó là đủ và không kéo theo rủi ro
     * mock thời gian rơi rớt sang test khác.
     */
    private function seedCheckIn(User $user, int $daysAgo, int $streak, float $prize = 100): CheckIn
    {
        return CheckIn::create([
            'user_id' => $user->id,
            'checked_on' => CarbonImmutable::now()->startOfDay()->subDays($daysAgo)->toDateString(),
            'prize_amount' => $prize,
            'bonus_amount' => 0,
            'amount' => $prize,
            'streak' => $streak,
        ]);
    }

    // ── Điểm danh và tiền vào ví ────────────────────────────────────────────

    public function test_claiming_credits_wallet_and_records_the_day(): void
    {
        $user = $this->createUser();

        $checkIn = $this->service()->claim($user);

        $this->assertSame(1, $checkIn->streak);
        $this->assertSame(0.0, (float) $checkIn->bonus_amount);
        $this->assertGreaterThan(0, (float) $checkIn->amount);
        $this->assertSame(
            CarbonImmutable::now()->startOfDay()->toDateString(),
            CarbonImmutable::parse($checkIn->checked_on)->toDateString(),
        );

        $commission = $user->commissions()->where('type', Commission::TYPE_CHECKIN)->sole();

        $this->assertSame('approved', $commission->status);
        $this->assertNull($commission->affiliate_link_id);
        $this->assertSame((float) $checkIn->amount, (float) $commission->amount);
        $this->assertSame((float) $checkIn->amount, $user->availableBalance());
    }

    public function test_claiming_twice_in_one_day_is_refused_and_pays_once(): void
    {
        $user = $this->createUser();

        $first = $this->service()->claim($user);

        $this->actingAs($user)->post('/diem-danh')->assertRedirect();

        $this->assertSame(1, CheckIn::where('user_id', $user->id)->count());
        $this->assertSame(1, $user->commissions()->count());
        $this->assertSame((float) $first->amount, $user->availableBalance());
    }

    public function test_route_reports_the_reason_instead_of_crashing(): void
    {
        $user = $this->createUser();
        $this->service()->claim($user);

        $this->actingAs($user)
            ->post('/diem-danh')
            ->assertRedirect()
            ->assertSessionHas('error', 'Hôm nay bạn đã điểm danh rồi — quay lại vào ngày mai nhé.');
    }

    public function test_route_flashes_the_prize_for_the_reveal(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->post('/diem-danh');

        $response->assertRedirect();

        $reward = session('checkin');

        $this->assertIsArray($reward);
        $this->assertSame((float) CheckIn::sole()->amount, $reward['amount']);
        $this->assertSame(1, $reward['streak']);
        $this->assertNull($reward['milestone']);
    }

    public function test_guests_cannot_claim(): void
    {
        $this->post('/diem-danh')->assertRedirect('/login');

        $this->assertSame(0, CheckIn::count());
    }

    // ── Chuỗi ngày ──────────────────────────────────────────────────────────

    public function test_checking_in_the_day_after_extends_the_streak(): void
    {
        $user = $this->createUser();
        $this->seedCheckIn($user, daysAgo: 1, streak: 3);

        $this->assertSame(3, $this->service()->currentStreak($user));

        $this->assertSame(4, $this->service()->claim($user)->streak);
    }

    public function test_missing_a_day_resets_the_streak(): void
    {
        $user = $this->createUser();
        $this->seedCheckIn($user, daysAgo: 2, streak: 9);

        $this->assertSame(0, $this->service()->currentStreak($user));

        $this->assertSame(1, $this->service()->claim($user)->streak);
    }

    public function test_streak_survives_until_the_end_of_the_next_day(): void
    {
        $user = $this->createUser();
        $this->seedCheckIn($user, daysAgo: 1, streak: 5);

        // Chưa bấm hôm nay nhưng chuỗi vẫn phải hiện 5, không phải 0: bấm bây giờ là nối tiếp.
        $state = $this->service()->state($user);

        $this->assertSame(5, $state['streak']);
        $this->assertSame(6, $state['next_streak']);
        $this->assertTrue($state['can_claim']);
    }

    // ── Mốc thưởng ──────────────────────────────────────────────────────────

    public function test_seventh_day_in_a_row_pays_the_milestone_bonus(): void
    {
        $user = $this->createUser();
        $this->seedCheckIn($user, daysAgo: 1, streak: 6);

        $checkIn = $this->service()->claim($user);

        $this->assertSame(7, $checkIn->streak);
        $this->assertSame(2000.0, (float) $checkIn->bonus_amount);
        $this->assertSame(
            (float) $checkIn->prize_amount + 2000.0,
            (float) $checkIn->amount,
        );
        $this->assertSame((float) $checkIn->amount, $user->availableBalance());
    }

    public function test_thirtieth_day_wins_the_bigger_milestone_only(): void
    {
        $user = $this->createUser();
        $this->seedCheckIn($user, daysAgo: 1, streak: 29);

        $checkIn = $this->service()->claim($user);

        $this->assertSame(30, $checkIn->streak);
        // 10.000 chứ không phải 12.000: trùng nhiều mốc thì lấy mốc lớn, không cộng dồn.
        $this->assertSame(10000.0, (float) $checkIn->bonus_amount);
    }

    public function test_milestones_repeat_after_the_first_time(): void
    {
        $user = $this->createUser();
        $this->seedCheckIn($user, daysAgo: 1, streak: 13);

        $this->assertSame(2000.0, (float) $this->service()->claim($user)->bonus_amount);
    }

    public function test_ordinary_day_has_no_bonus(): void
    {
        $user = $this->createUser();
        $this->seedCheckIn($user, daysAgo: 1, streak: 4);

        $this->assertSame(0.0, (float) $this->service()->claim($user)->bonus_amount);
    }

    // ── Kho quà ─────────────────────────────────────────────────────────────

    public function test_draw_always_pays_one_of_the_listed_prizes(): void
    {
        $amounts = collect($this->service()->state(null)['prizes'])->pluck('amount')->all();

        for ($i = 0; $i < 60; $i++) {
            $this->assertContains($this->service()->draw(), $amounts);
        }
    }

    public function test_sold_out_prizes_stop_being_drawn_and_stop_being_advertised(): void
    {
        $fresh = $this->service()->state(null);

        $this->assertSame(1000.0, $fresh['top_prize']);
        $this->assertSame(659, $fresh['gifts_left']);

        $this->exhaustTopPrize();

        $after = $this->service()->state(null);

        $this->assertSame(500.0, $after['top_prize']);
        $this->assertSame(650, $after['gifts_left']);

        for ($i = 0; $i < 60; $i++) {
            $this->assertNotSame(1000.0, $this->service()->draw());
        }
    }

    public function test_yesterdays_giveaway_does_not_eat_into_todays_stock(): void
    {
        $this->exhaustTopPrize(daysAgo: 1);

        $this->assertSame(1000.0, $this->service()->state(null)['top_prize']);
    }

    /** Phát hết 9 phần mệnh giá cao nhất của một ngày. */
    private function exhaustTopPrize(int $daysAgo = 0): void
    {
        foreach (range(1, 9) as $i) {
            $this->seedCheckIn($this->createUser(['email' => "prize{$i}-{$daysAgo}@example.com"]), $daysAgo, 1, 1000);
        }
    }

    // ── Công tắc và ranh giới với tiền thật ─────────────────────────────────

    public function test_disabled_program_pays_nothing_and_hides_the_card(): void
    {
        Setting::set(DailyCheckInService::ENABLED_KEY, '0');

        $user = $this->createUser();

        $this->actingAs($user)
            ->post('/diem-danh')
            ->assertSessionHas('error', 'Chương trình điểm danh đang tạm dừng.');

        $this->assertSame(0, CheckIn::count());
        $this->assertSame(0.0, $user->availableBalance());

        $this->actingAs($user)
            ->get('/')
            ->assertInertia(fn ($page) => $page->where('dailyCheckIn', null));
    }

    public function test_home_page_carries_the_card_state(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)
            ->get('/')
            ->assertInertia(fn ($page) => $page
                ->where('dailyCheckIn.can_claim', true)
                ->where('dailyCheckIn.streak', 0)
                ->where('dailyCheckIn.gifts_left', 659)
                ->has('dailyCheckIn.week', 7));
    }

    public function test_account_page_carries_the_card_state(): void
    {
        $user = $this->createUser();
        $this->seedCheckIn($user, daysAgo: 1, streak: 4);

        $this->actingAs($user)
            ->get('/profile')
            ->assertInertia(fn ($page) => $page
                ->where('dailyCheckIn.can_claim', true)
                ->where('dailyCheckIn.streak', 4)
                ->has('dailyCheckIn.week', 7));
    }

    public function test_claiming_from_the_account_page_moves_the_balance_shown_there(): void
    {
        $user = $this->createUser();

        $before = $this->actingAs($user)->get('/profile');
        // So qua closure vì JSON không giữ kiểu float: 0.0 sang tới frontend là 0.
        $before->assertInertia(fn ($page) => $page->where('balance.available', fn ($v) => (float) $v === 0.0));

        $this->actingAs($user)->post('/diem-danh')->assertRedirect();

        // Thẻ gọi partial reload kèm 'balance' (xem reload prop ở Profile.vue) — nên số dư trên
        // đầu trang phải đúng bằng tiền vừa nhận ngay trong cùng lượt đó.
        $amount = (float) CheckIn::sole()->amount;

        $this->actingAs($user)
            ->get('/profile')
            ->assertInertia(fn ($page) => $page
                ->where('balance.available', fn ($v) => (float) $v === $amount)
                ->where('dailyCheckIn.can_claim', false)
                ->where('dailyCheckIn.claimed_today.amount', fn ($v) => (float) $v === $amount));
    }

    public function test_disabled_program_hides_the_card_on_the_account_page_too(): void
    {
        Setting::set(DailyCheckInService::ENABLED_KEY, '0');

        $this->actingAs($this->createUser())
            ->get('/profile')
            ->assertInertia(fn ($page) => $page->where('dailyCheckIn', null));
    }

    public function test_check_in_money_alone_cannot_be_withdrawn(): void
    {
        $user = $this->createUser();
        PayoutAccount::create([
            'user_id' => $user->id,
            'provider' => 'momo',
            'account_number' => '0900000000',
            'account_name' => 'Khách Điểm Danh',
        ]);

        // Đủ tiền trong ví, chỉ là chưa có đồng nào đến từ đơn hàng thật.
        Commission::create([
            'user_id' => $user->id,
            'affiliate_link_id' => null,
            'type' => Commission::TYPE_CHECKIN,
            'amount' => 200000,
            'status' => 'approved',
            'order_id' => 'CHECKIN-TEST',
            'confirmed_at' => now(),
        ]);

        $this->assertFalse($user->hasRealCashback());

        $this->actingAs($user)
            ->post('/withdrawals', ['provider' => 'momo', 'amount' => 50000])
            ->assertSessionHasErrors('amount');
    }

    public function test_check_in_does_not_count_towards_membership_tier(): void
    {
        $user = $this->createUser();

        Commission::create([
            'user_id' => $user->id,
            'affiliate_link_id' => null,
            'type' => Commission::TYPE_CHECKIN,
            'amount' => 5000000,
            'status' => 'approved',
            'order_id' => 'CHECKIN-TIER-TEST',
            'confirmed_at' => now(),
        ]);

        $this->assertSame(0.0, app(MembershipTierService::class)
            ->earnedBetween($user->id, CarbonImmutable::now()->subYear(), CarbonImmutable::now()->addDay()));
    }

    // ── Thông báo và lịch sử ví ─────────────────────────────────────────────

    public function test_claiming_notifies_the_user(): void
    {
        $user = $this->createUser();

        $this->service()->claim($user);

        $this->assertSame([CheckInRewardNotification::class], $user->notifications()->pluck('type')->all());
    }

    public function test_wallet_history_labels_the_check_in(): void
    {
        $user = $this->createUser();
        $checkIn = $this->service()->claim($user);

        $row = app(WalletHistoryService::class)->timeline($user->fresh())->first();

        $this->assertSame('Điểm danh nhận quà', $row['title']);
        $this->assertSame('bonus', $row['kind']);
        $this->assertSame((float) $checkIn->amount, $row['delta']);
    }
}
