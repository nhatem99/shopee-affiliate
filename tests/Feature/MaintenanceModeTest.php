<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_works_normally_when_maintenance_mode_is_off(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Home'));
    }

    public function test_home_page_shows_maintenance_page_when_maintenance_mode_is_on(): void
    {
        Setting::set('maintenance_mode', '1');

        $this->get('/')
            ->assertStatus(503)
            ->assertInertia(fn ($page) => $page->component('Maintenance'));
    }

    public function test_blog_is_blocked_during_maintenance(): void
    {
        Setting::set('maintenance_mode', '1');

        $this->get('/blog')->assertStatus(503);
    }

    public function test_login_page_is_blocked_during_maintenance(): void
    {
        Setting::set('maintenance_mode', '1');

        $this->get('/login')
            ->assertStatus(503)
            ->assertInertia(fn ($page) => $page->component('Maintenance'));
    }

    public function test_admin_login_page_stays_accessible_during_maintenance(): void
    {
        Setting::set('maintenance_mode', '1');

        $this->get('/admin/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/AdminLogin'));
    }

    public function test_admin_dashboard_stays_accessible_during_maintenance(): void
    {
        Setting::set('maintenance_mode', '1');
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Dashboard'));
    }

    public function test_regular_user_pages_are_blocked_during_maintenance(): void
    {
        Setting::set('maintenance_mode', '1');
        $user = $this->createUser();

        $this->actingAs($user)
            ->get('/history')
            ->assertStatus(503)
            ->assertInertia(fn ($page) => $page->component('Maintenance'));
    }

    // ── Admin vẫn dùng được trang khách để kiểm tra chức năng ─────────────────

    public function test_admin_can_browse_customer_pages_during_maintenance(): void
    {
        Setting::set('maintenance_mode', '1');

        $this->actingAs($this->createAdmin())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Home'));
    }

    public function test_admin_can_use_voucher_tool_during_maintenance(): void
    {
        Setting::set('maintenance_mode', '1');
        // Không gọi ra salesoc.vn/Shopee thật trong test — chỉ cần biết request đi hết được
        // controller thay vì bị MaintenanceMode chặn ở giữa.
        Http::fake();

        $this->actingAs($this->createAdmin())
            ->post('/voucher/resolve', ['url' => 'https://shopee.vn/Ao-Hoodie-i.123456.7890123'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Home'));
    }

    public function test_voucher_tool_stays_blocked_for_customers_during_maintenance(): void
    {
        Setting::set('maintenance_mode', '1');
        Http::fake();

        $this->post('/voucher/resolve', ['url' => 'https://shopee.vn/Ao-Hoodie-i.123456.7890123'])
            ->assertStatus(503);
    }

    public function test_admin_gets_the_maintenance_banner_flag(): void
    {
        Setting::set('maintenance_mode', '1');

        $this->actingAs($this->createAdmin())
            ->get('/')
            ->assertInertia(fn ($page) => $page->where('settings.maintenanceMode', true));
    }

    public function test_maintenance_flag_is_not_shared_with_non_admins(): void
    {
        Setting::set('maintenance_mode', '1');

        $this->actingAs($this->createUser())
            ->get('/')
            ->assertInertia(fn ($page) => $page->where('settings.maintenanceMode', false));
    }
}
