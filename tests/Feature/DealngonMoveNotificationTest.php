<?php

namespace Tests\Feature;

use App\Notifications\DealngonMoveNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lệnh users:notify-dealngon: chuông "Tietkiemvi đã chuyển sang dealngon" cho khách đã từng vào web.
 */
class DealngonMoveNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_but_sends_nothing(): void
    {
        $this->createUser(['email' => 'a@example.com', 'last_seen_at' => now()]);

        $this->artisan('users:notify-dealngon')
            ->expectsOutputToContain('a@example.com')
            ->expectsOutputToContain('Sẽ gửi 1 thông báo')
            ->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_sends_once_to_customers_who_have_used_the_site(): void
    {
        $seen = $this->createUser(['last_seen_at' => now()->subDays(3)]);
        $neverSeen = $this->createUser(['last_seen_at' => null]);
        $admin = $this->createAdmin(['last_seen_at' => now()]);

        $this->artisan('users:notify-dealngon', ['--send' => true])
            ->expectsOutputToContain('Đã gửi 1 thông báo')
            ->assertSuccessful();

        $this->assertSame(1, $seen->notifications()->where('type', DealngonMoveNotification::class)->count());
        $this->assertSame(0, $neverSeen->notifications()->count());
        $this->assertSame(0, $admin->notifications()->count());

        $data = $seen->notifications()->first()->data;
        $this->assertSame('https://dealngon.top', $data['url']);
        $this->assertStringContainsString('dealngon.top', $data['title']);

        // Chạy lại không gửi trùng.
        $this->artisan('users:notify-dealngon', ['--send' => true])
            ->expectsOutputToContain('Đã gửi 0 thông báo')
            ->assertSuccessful();
        $this->assertSame(1, $seen->notifications()->count());
    }

    public function test_clicking_it_leaves_the_site_with_a_full_page_visit(): void
    {
        $user = $this->createUser(['last_seen_at' => now()]);
        $user->notify(new DealngonMoveNotification);
        $id = $user->notifications()->first()->id;

        // Chuông bấm bằng router.post của Inertia: phải là 409 + X-Inertia-Location để trình duyệt
        // tự chuyển trang, 302 sang tên miền khác thì XHR chết vì CORS.
        $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post("/thong-bao/{$id}/doc")
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://dealngon.top');

        $this->assertNotNull($user->notifications()->first()->read_at);
    }

    public function test_internal_links_still_redirect_normally(): void
    {
        $user = $this->createUser();
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\WelcomeNotification',
            'data' => ['title' => 'x', 'body' => 'y', 'url' => '/', 'icon' => '👋'],
        ]);
        $id = $user->notifications()->first()->id;

        $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post("/thong-bao/{$id}/doc")
            ->assertRedirect('/');
    }
}
