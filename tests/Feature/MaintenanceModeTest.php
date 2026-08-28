<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
