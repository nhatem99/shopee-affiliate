<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\PayoutAccount;
use App\Models\Setting;
use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\WelcomeBonusNotification;
use App\Notifications\WelcomeNotification;
use App\Notifications\WithdrawalStatusNotification;
use App\Services\CashbackLeaderboardService;
use App\Services\WelcomeBonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WelcomeBonusTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $email = 'new@example.com'): User
    {
        $this->setUpRoles();

        $this->post('/register', [
            'name' => 'Khách Mới',
            'email' => $email,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertRedirect('/');

        return User::where('email', $email)->firstOrFail();
    }

    // ── Thưởng khi đăng ký ──────────────────────────────────────────────────

    public function test_registering_grants_welcome_bonus_and_two_notifications(): void
    {
        $user = $this->register();

        $bonus = $user->commissions()->where('type', Commission::TYPE_WELCOME_BONUS)->first();

        $this->assertNotNull($bonus);
        $this->assertSame('approved', $bonus->status);
        $this->assertSame(5000.0, (float) $bonus->amount);
        $this->assertNull($bonus->affiliate_link_id);
        $this->assertSame('WELCOME-'.$user->id, $bonus->order_id);

        $this->assertSame(5000.0, $user->availableBalance());

        $types = $user->notifications()->pluck('type')->all();
        $this->assertContains(WelcomeNotification::class, $types);
        $this->assertContains(WelcomeBonusNotification::class, $types);
        $this->assertSame(2, $user->unreadNotifications()->count());
    }

    public function test_bonus_amount_follows_setting(): void
    {
        Setting::set(WelcomeBonusService::AMOUNT_KEY, '12000');

        $user = $this->register();

        $this->assertSame(12000.0, $user->availableBalance());
    }

    public function test_disabled_bonus_still_sends_welcome_but_no_money(): void
    {
        Setting::set(WelcomeBonusService::ENABLED_KEY, '0');

        $user = $this->register();

        $this->assertSame(0, $user->commissions()->count());
        $this->assertSame(0.0, $user->availableBalance());
        $this->assertSame([WelcomeNotification::class], $user->notifications()->pluck('type')->all());
    }

    public function test_grant_is_idempotent(): void
    {
        $user = $this->createUser();
        $service = app(WelcomeBonusService::class);

        $this->assertNotNull($service->grant($user));
        $this->assertNull($service->grant($user));

        $this->assertSame(1, $user->commissions()->count());
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_artisan_command_grants_retroactively_and_skips_admins(): void
    {
        $old = $this->createUser();
        $admin = $this->createAdmin();

        $this->artisan('welcome-bonus:grant', ['--all' => true])->assertSuccessful();
        $this->artisan('welcome-bonus:grant', ['--all' => true])->assertSuccessful();

        $this->assertSame(5000.0, $old->availableBalance());
        $this->assertSame(1, $old->commissions()->count());
        $this->assertSame(0, $admin->commissions()->count());
    }

    public function test_register_page_advertises_bonus_only_when_enabled(): void
    {
        $this->setUpRoles();

        $this->get('/register')->assertInertia(fn ($page) => $page->where('welcomeBonus', 5000));

        Setting::set(WelcomeBonusService::ENABLED_KEY, '0');

        $this->get('/register')->assertInertia(fn ($page) => $page->where('welcomeBonus', 0));
    }

    // ── Điều kiện rút ───────────────────────────────────────────────────────

    private function userWithBonusAndWallet(float $bonus): User
    {
        $user = $this->createUser();
        Commission::create([
            'user_id' => $user->id,
            'type' => Commission::TYPE_WELCOME_BONUS,
            'amount' => $bonus,
            'status' => 'approved',
            'order_id' => 'WELCOME-'.$user->id,
            'confirmed_at' => now(),
        ]);
        PayoutAccount::create([
            'user_id' => $user->id,
            'provider' => 'momo',
            'account_number' => '0901234567',
            'account_name' => 'KHACH MOI',
        ]);

        return $user;
    }

    public function test_bonus_alone_cannot_be_withdrawn_even_above_minimum(): void
    {
        $user = $this->userWithBonusAndWallet(20000);

        $this->actingAs($user)
            ->post('/withdrawals', ['provider' => 'momo', 'amount' => 10000])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Withdrawal::count());
    }

    public function test_bonus_can_be_withdrawn_together_with_real_cashback(): void
    {
        $user = $this->userWithBonusAndWallet(5000);
        Commission::create([
            'user_id' => $user->id,
            'amount' => 6000,
            'status' => 'approved',
            'order_id' => 'SHOPEE-1',
            'confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->post('/withdrawals', ['provider' => 'momo', 'amount' => 11000])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Withdrawal::count());
    }

    public function test_profile_exposes_has_real_cashback_flag(): void
    {
        $user = $this->userWithBonusAndWallet(5000);

        $this->actingAs($user)->get('/profile')
            ->assertInertia(fn ($page) => $page
                ->where('balance.available', 5000)
                ->where('balance.hasRealCashback', false));
    }

    // ── Thông báo ───────────────────────────────────────────────────────────

    public function test_notifications_are_shared_with_customers_only(): void
    {
        $user = $this->register();

        $this->actingAs($user)->get('/')
            ->assertInertia(fn ($page) => $page
                ->where('notifications.unread', 2)
                ->has('notifications.latest', 2)
                ->has('notifications.latest.0', fn ($n) => $n
                    ->where('read', false)
                    ->hasAll(['id', 'title', 'body', 'url', 'icon', 'ago', 'created_at'])));

        $admin = $this->createAdmin();
        $this->actingAs($admin)->get('/admin/dashboard')
            ->assertInertia(fn ($page) => $page->where('notifications', null));
    }

    public function test_read_all_and_read_one(): void
    {
        $user = $this->register();

        $first = $user->notifications()->first();

        $this->actingAs($user)->post("/thong-bao/{$first->id}/doc")
            ->assertRedirect($first->data['url']);
        $this->assertSame(1, $user->unreadNotifications()->count());

        $this->actingAs($user)->post('/thong-bao/doc-het')->assertRedirect();
        $this->assertSame(0, $user->unreadNotifications()->count());
    }

    public function test_cannot_read_someone_elses_notification(): void
    {
        $owner = $this->register('owner@example.com');
        $other = $this->createUser();

        $id = $owner->notifications()->first()->id;

        $this->actingAs($other)->post("/thong-bao/{$id}/doc")->assertNotFound();
    }

    public function test_notifications_page_lists_them(): void
    {
        $user = $this->register();

        $this->actingAs($user)->get('/thong-bao')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Notifications')
                ->has('notifications.data', 2));
    }

    public function test_withdrawal_status_change_notifies_customer(): void
    {
        $user = $this->createUser();
        $withdrawal = Withdrawal::create([
            'user_id' => $user->id,
            'provider' => 'momo',
            'account_number' => '0901234567',
            'account_name' => 'KHACH',
            'amount' => 10000,
            'status' => 'pending',
        ]);

        $this->actingAs($this->createAdmin())
            ->patch("/admin/withdrawals/{$withdrawal->id}", ['status' => 'rejected', 'admin_note' => 'Sai số ví'])
            ->assertRedirect();

        $notification = $user->notifications()->where('type', WithdrawalStatusNotification::class)->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('Sai số ví', $notification->data['body']);
    }

    // ── Lịch sử ví ──────────────────────────────────────────────────────────

    public function test_wallet_history_shows_running_balance(): void
    {
        $user = $this->userWithBonusAndWallet(5000);
        Commission::create([
            'user_id' => $user->id,
            'amount' => 6000,
            'status' => 'approved',
            'order_id' => 'SHOPEE-1',
            'confirmed_at' => now()->addMinute(),
        ]);
        $withdrawal = Withdrawal::create([
            'user_id' => $user->id,
            'provider' => 'momo',
            'account_number' => '0901234567',
            'account_name' => 'KHACH',
            'amount' => 10000,
            'status' => 'pending',
        ]);
        // created_at không nằm trong fillable — gán thẳng để lệnh rút đứng sau hai khoản cộng.
        $withdrawal->created_at = now()->addMinutes(2);
        $withdrawal->save();

        $this->actingAs($user)->get('/vi/lich-su')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('WalletHistory')
                ->where('available', 1000)
                ->has('events', 3)
                // Mới nhất ở trên: lệnh rút → hoàn tiền → thưởng
                ->where('events.0.kind', 'withdrawal')
                ->where('events.0.before', 11000)
                ->where('events.0.after', 1000)
                ->where('events.1.kind', 'cashback')
                ->where('events.2.kind', 'bonus')
                ->where('events.2.before', 0)
                ->where('events.2.after', 5000));
    }

    public function test_bonus_is_excluded_from_leaderboard(): void
    {
        Setting::set(CashbackLeaderboardService::DEMO_KEY, '0');
        $user = $this->userWithBonusAndWallet(5000);

        $board = app(CashbackLeaderboardService::class)->forMonth($user);

        $this->assertSame([], $board['entries']);
        $this->assertNull($board['me']);
    }
}
