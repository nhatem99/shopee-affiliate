<?php

namespace Tests\Feature;

use App\Models\AffiliateLink;
use App\Models\Commission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private function makeCommission(int $userId, string $status = 'pending'): Commission
    {
        $link = AffiliateLink::create([
            'user_id'      => $userId,
            'original_url' => 'https://shopee.vn/product',
            'short_url'    => 'https://at.link/test',
            'platform'     => 'shopee',
            'product_name' => 'Test Product',
        ]);

        return Commission::create([
            'user_id'          => $userId,
            'affiliate_link_id' => $link->id,
            'amount'           => '50000.00',
            'status'           => $status,
        ]);
    }

    // ── Middleware: guests & regular users blocked ────────────────────────────

    public function test_guest_cannot_access_admin_dashboard(): void
    {
        $this->get('/admin/dashboard')->assertRedirect('/login');
    }

    public function test_regular_user_cannot_access_admin_dashboard(): void
    {
        $user = $this->createUser();
        $this->actingAs($user)->get('/admin/dashboard')->assertForbidden();
    }

    public function test_regular_user_cannot_access_admin_orders(): void
    {
        $user = $this->createUser();
        $this->actingAs($user)->get('/admin/orders')->assertForbidden();
    }

    public function test_regular_user_cannot_access_admin_api_config(): void
    {
        $user = $this->createUser();
        $this->actingAs($user)->get('/admin/api-config')->assertForbidden();
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function test_admin_can_access_dashboard(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboard')
                ->has('stats')
                ->has('recent_orders')
            );
    }

    public function test_dashboard_stats_reflect_database_state(): void
    {
        $admin = $this->createAdmin();
        $user  = $this->createUser();

        $this->makeCommission($user->id, 'pending');

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboard')
                ->where('stats.pending_orders', 1)
                ->where('stats.total_codes', 1)
                ->where('stats.total_users', 1)
            );
    }

    // ── Orders ────────────────────────────────────────────────────────────────

    public function test_admin_can_view_orders_list(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get('/admin/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Orders')
                ->has('orders')
            );
    }

    public function test_admin_can_filter_orders_by_status(): void
    {
        $admin = $this->createAdmin();
        $user  = $this->createUser();

        $this->makeCommission($user->id, 'pending');
        $this->makeCommission($user->id, 'approved');

        $this->actingAs($admin)
            ->get('/admin/orders?status=pending')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Orders')
                ->where('orders.total', 1)
                ->where('filters.status', 'pending')
            );
    }

    public function test_admin_can_approve_a_pending_commission(): void
    {
        $admin      = $this->createAdmin();
        $user       = $this->createUser();
        $commission = $this->makeCommission($user->id, 'pending');

        $this->actingAs($admin)
            ->patch("/admin/orders/{$commission->id}", ['status' => 'approved'])
            ->assertRedirect();

        $this->assertDatabaseHas('commissions', [
            'id'     => $commission->id,
            'status' => 'approved',
        ]);
    }

    public function test_admin_can_mark_commission_as_paid(): void
    {
        $admin      = $this->createAdmin();
        $user       = $this->createUser();
        $commission = $this->makeCommission($user->id, 'approved');

        $this->actingAs($admin)
            ->patch("/admin/orders/{$commission->id}", ['status' => 'paid'])
            ->assertRedirect();

        $this->assertDatabaseHas('commissions', [
            'id'     => $commission->id,
            'status' => 'paid',
        ]);
    }

    public function test_order_update_rejects_invalid_status(): void
    {
        $admin      = $this->createAdmin();
        $user       = $this->createUser();
        $commission = $this->makeCommission($user->id);

        $this->actingAs($admin)
            ->patch("/admin/orders/{$commission->id}", ['status' => 'invalid'])
            ->assertSessionHasErrors('status');
    }

    public function test_regular_user_cannot_update_order_status(): void
    {
        $user       = $this->createUser();
        $commission = $this->makeCommission($user->id);

        $this->actingAs($user)
            ->patch("/admin/orders/{$commission->id}", ['status' => 'approved'])
            ->assertForbidden();
    }

    // ── API Config ────────────────────────────────────────────────────────────

    public function test_admin_can_view_api_config_page(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get('/admin/api-config')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/ApiConfig'));
    }
}
