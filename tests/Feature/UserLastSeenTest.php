<?php

namespace Tests\Feature;

use App\Http\Middleware\TouchLastSeen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserLastSeenTest extends TestCase
{
    use RefreshDatabase;

    public function test_logged_in_request_records_last_seen(): void
    {
        $user = $this->createUser();
        $this->assertNull($user->last_seen_at);

        $this->actingAs($user)->get('/')->assertOk();

        $this->assertNotNull($user->fresh()->last_seen_at);
    }

    public function test_last_seen_is_only_refreshed_after_the_interval(): void
    {
        $user = $this->createUser();

        Carbon::setTestNow('2026-09-16 10:00:00');
        $this->actingAs($user)->get('/');
        $this->assertSame('2026-09-16 10:00:00', $user->fresh()->last_seen_at->toDateTimeString());

        Carbon::setTestNow('2026-09-16 10:02:00');
        $this->actingAs($user)->get('/');
        $this->assertSame('2026-09-16 10:00:00', $user->fresh()->last_seen_at->toDateTimeString());

        Carbon::setTestNow('2026-09-16 10:0'.(TouchLastSeen::INTERVAL_MINUTES + 1).':00');
        $this->actingAs($user)->get('/');
        $this->assertSame('2026-09-16 10:06:00', $user->fresh()->last_seen_at->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_guest_requests_record_nothing(): void
    {
        $this->get('/')->assertOk();
        // Không có user nào để ghi — chỉ cần không nổ.
        $this->assertTrue(true);
    }

    public function test_admin_impersonating_does_not_touch_the_customer(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createUser();

        $this->actingAs($admin)->post("/admin/users/{$customer->id}/impersonate");
        $this->get('/')->assertOk();

        $this->assertNull($customer->fresh()->last_seen_at);
    }

    public function test_admin_user_list_shows_last_seen(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createUser();
        $customer->forceFill(['last_seen_at' => now()->subHour()])->save();

        $this->actingAs($admin)
            ->get('/admin/users?q='.$customer->email)
            ->assertInertia(fn ($page) => $page
                ->where('users.data.0.last_seen_at', $customer->fresh()->last_seen_at->toDateTimeString())
                ->where('users.data.0.last_seen_human', '1 giờ trước'));
    }
}
