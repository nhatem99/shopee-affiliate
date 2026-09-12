<?php

namespace Tests\Feature;

use App\Models\AffiliateLink;
use App\Models\Commission;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    // ── Quyền truy cập ────────────────────────────────────────────────────────

    public function test_guest_cannot_access_user_management(): void
    {
        $this->get('/admin/users')->assertRedirect('/login');
    }

    public function test_regular_user_cannot_access_user_management(): void
    {
        $this->actingAs($this->createUser())->get('/admin/users')->assertForbidden();
    }

    public function test_admin_sees_user_list(): void
    {
        $admin = $this->createAdmin();
        $this->createUser(['name' => 'Khach Hang A']);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Users')
                ->where('stats.total', 2)
                ->has('users.data', 2));
    }

    public function test_search_narrows_list_to_matching_account(): void
    {
        $admin = $this->createAdmin();
        $this->createUser(['email' => 'can-tim@example.com']);
        $this->createUser(['email' => 'khong-lien-quan@example.com']);

        $this->actingAs($admin)
            ->get('/admin/users?q=can-tim')
            ->assertInertia(fn ($page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.email', 'can-tim@example.com'));
    }

    public function test_status_filter_returns_only_banned_accounts(): void
    {
        $admin = $this->createAdmin();
        $banned = $this->createUser();
        $banned->forceFill(['banned_at' => now()])->save();
        $this->createUser();

        $this->actingAs($admin)
            ->get('/admin/users?status=banned')
            ->assertInertia(fn ($page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.id', $banned->id));
    }

    public function test_list_shows_approved_commission_and_available_balance(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createUser();

        $link = AffiliateLink::create([
            'user_id' => $user->id,
            'original_url' => 'https://shopee.vn/product',
            'short_url' => 'https://at.link/test',
            'platform' => 'shopee',
        ]);

        Commission::create(['user_id' => $user->id, 'affiliate_link_id' => $link->id, 'amount' => '50000.00', 'status' => 'approved']);
        // Don cho duyet khong duoc cong vao so du - lot vao la hien thi tien chua co that.
        Commission::create(['user_id' => $user->id, 'affiliate_link_id' => $link->id, 'amount' => '20000.00', 'status' => 'pending']);

        Withdrawal::create([
            'user_id' => $user->id,
            'provider' => 'momo',
            'account_number' => '0900000000',
            'account_name' => 'Nguoi Dung',
            'amount' => '10000.00',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get('/admin/users?q='.$user->email)
            ->assertInertia(fn ($page) => $page
                ->where('users.data.0.approved_commission', fn ($v) => (float) $v === 50000.0)
                ->where('users.data.0.available_balance', fn ($v) => (float) $v === 40000.0)
                ->where('users.data.0.links_count', 1));
    }

    // ── Sửa thông tin ─────────────────────────────────────────────────────────

    public function test_admin_can_update_user_profile(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createUser();

        $this->actingAs($admin)
            ->patch("/admin/users/{$user->id}", [
                'name' => 'Ten Moi',
                'email' => 'ten-moi@example.com',
                'phone' => '0900000001',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Ten Moi',
            'email' => 'ten-moi@example.com',
            'phone' => '0900000001',
        ]);
    }

    public function test_update_rejects_email_taken_by_another_account(): void
    {
        $admin = $this->createAdmin();
        $other = $this->createUser(['email' => 'da-co@example.com']);
        $user = $this->createUser();

        $this->actingAs($admin)
            ->patch("/admin/users/{$user->id}", [
                'name' => $user->name,
                'email' => $other->email,
            ])
            ->assertSessionHasErrors('email');
    }

    // ── Mật khẩu ──────────────────────────────────────────────────────────────

    public function test_admin_can_reset_user_password(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createUser();

        $this->actingAs($admin)
            ->post("/admin/users/{$user->id}/password", ['password' => 'MatKhauMoi123'])
            ->assertRedirect();

        $this->assertTrue(Hash::check('MatKhauMoi123', $user->fresh()->password));
    }

    public function test_weak_password_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createUser();

        $this->actingAs($admin)
            ->post("/admin/users/{$user->id}/password", ['password' => '123'])
            ->assertSessionHasErrors('password');
    }

    // ── Quyền ─────────────────────────────────────────────────────────────────

    public function test_admin_can_promote_user_and_spatie_role_follows(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createUser();

        $this->actingAs($admin)
            ->patch("/admin/users/{$user->id}/role", ['role' => 'admin'])
            ->assertRedirect();

        $user->refresh();
        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('user'));
    }

    public function test_admin_cannot_change_own_role(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->patch("/admin/users/{$admin->id}/role", ['role' => 'user'])
            ->assertSessionHasErrors('role');

        $this->assertSame('admin', $admin->fresh()->role);
    }

    // ── Khoá / mở khoá ────────────────────────────────────────────────────────

    public function test_admin_can_ban_and_unban_user(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createUser();

        $this->actingAs($admin)
            ->post("/admin/users/{$user->id}/ban", ['banned_reason' => 'Gian lan'])
            ->assertRedirect();

        $user->refresh();
        $this->assertNotNull($user->banned_at);
        $this->assertSame('Gian lan', $user->banned_reason);

        $this->actingAs($admin)->delete("/admin/users/{$user->id}/ban")->assertRedirect();

        $user->refresh();
        $this->assertNull($user->banned_at);
        $this->assertNull($user->banned_reason);
    }

    public function test_admin_cannot_ban_self_or_another_admin(): void
    {
        $admin = $this->createAdmin();
        $otherAdmin = $this->createAdmin();

        $this->actingAs($admin)->post("/admin/users/{$admin->id}/ban")->assertSessionHasErrors('banned_reason');
        $this->actingAs($admin)->post("/admin/users/{$otherAdmin->id}/ban")->assertSessionHasErrors('banned_reason');

        $this->assertNull($admin->fresh()->banned_at);
        $this->assertNull($otherAdmin->fresh()->banned_at);
    }

    // ── Hệ quả của việc khoá ──────────────────────────────────────────────────

    public function test_banned_user_cannot_log_in(): void
    {
        $this->setUpRoles();
        $user = User::factory()->create(['password' => 'MatKhau123']);
        // banned_at cố tình không nằm trong $fillable nên factory bỏ qua — phải gán thẳng.
        $user->forceFill(['banned_at' => now()])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'MatKhau123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_banned_user_is_logged_out_of_an_active_session(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->get('/')->assertOk();

        $user->forceFill(['banned_at' => now()])->save();

        $this->actingAs($user)->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }
}
