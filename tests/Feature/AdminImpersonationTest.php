<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_enter_a_customer_account_and_sees_exit_banner_data(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createUser(['name' => 'Khach A']);

        $this->actingAs($admin)
            ->post("/admin/users/{$customer->id}/impersonate")
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($customer);
        $this->assertEquals($admin->id, session(ImpersonationService::KEY));

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.id', $customer->id)
                ->where('auth.impersonator.id', $admin->id)
                ->where('auth.impersonator.name', $admin->name));
    }

    public function test_leaving_returns_to_admin_account(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createUser();

        $this->actingAs($admin)->post("/admin/users/{$customer->id}/impersonate");

        $this->post('/impersonate/leave')->assertRedirect('/admin/users');

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session(ImpersonationService::KEY));

        $this->get('/')->assertInertia(fn ($page) => $page->where('auth.impersonator', null));
    }

    public function test_logout_while_impersonating_returns_to_admin_instead_of_ending_session(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createUser();

        $this->actingAs($admin)->post("/admin/users/{$customer->id}/impersonate");

        $this->post('/logout')->assertRedirect('/admin/users');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_admin_routes_are_blocked_while_impersonating(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createUser();

        $this->actingAs($admin)->post("/admin/users/{$customer->id}/impersonate");

        $this->get('/admin/users')->assertForbidden();
    }

    public function test_cannot_impersonate_self_admin_or_banned_user(): void
    {
        $admin = $this->createAdmin();
        $otherAdmin = $this->createAdmin();
        $banned = $this->createUser();
        $banned->forceFill(['banned_at' => now()])->save();

        foreach ([$admin, $otherAdmin, $banned] as $target) {
            $this->actingAs($admin)
                ->post("/admin/users/{$target->id}/impersonate")
                ->assertSessionHasErrors('impersonate');

            $this->assertAuthenticatedAs($admin);
        }
    }

    public function test_regular_user_cannot_impersonate(): void
    {
        $user = $this->createUser();
        $victim = $this->createUser();

        $this->actingAs($user)->post("/admin/users/{$victim->id}/impersonate")->assertForbidden();
        $this->assertAuthenticatedAs($user);
    }

    public function test_leave_without_impersonating_is_a_noop(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->post('/impersonate/leave')->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_leave_logs_out_if_original_admin_was_demoted_meanwhile(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createUser();

        $this->actingAs($admin)->post("/admin/users/{$customer->id}/impersonate");

        $admin->forceFill(['role' => 'user'])->save();

        $this->post('/impersonate/leave')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_impersonating_session_passes_maintenance_mode(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createUser();

        $this->actingAs($admin)->post("/admin/users/{$customer->id}/impersonate");

        Setting::set('maintenance_mode', '1');

        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->component('Home'));
        $this->post('/impersonate/leave')->assertRedirect('/admin/users');
        $this->assertAuthenticatedAs($admin);
    }
}
