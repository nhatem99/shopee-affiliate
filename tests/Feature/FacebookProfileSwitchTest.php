<?php

namespace Tests\Feature;

use App\Models\FacebookProfile;
use App\Services\FacebookGroupRunnerSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Nút "Chuyển sang" ở /admin/fb-groups: bot đang chạy chuyển Chromium sang page admin chọn.
 */
class FacebookProfileSwitchTest extends TestCase
{
    use RefreshDatabase;

    private const BOT = '1.6.0-node';

    private string $token;

    private FacebookProfile $page;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->token = app(FacebookGroupRunnerSettings::class)->regenerateToken();
        $this->page = FacebookProfile::create(['url' => 'https://www.facebook.com/shopxinh', 'name' => 'Shop Xinh', 'position' => 1]);
    }

    private function poll(string $version = self::BOT)
    {
        return $this->postJson('/runner/fb/poll', [
            'claim_key' => 'claim-key-000000000001',
            'state' => 'ok',
            'version' => $version,
        ], ['X-Runner-Token' => $this->token]);
    }

    public function test_admin_switch_request_is_given_to_bot_once(): void
    {
        $this->actingAs($this->createAdmin())->post("/admin/fb-groups/profiles/{$this->page->id}/switch")->assertRedirect();

        $job = $this->poll()->assertOk()->json();
        $this->assertSame('switch_profile', $job['type']);
        $this->assertSame($this->page->id, $job['profile']['id']);
        $this->assertSame('https://www.facebook.com/shopxinh', $job['profile']['url']);

        $this->assertNotSame('switch_profile', $this->poll()->json('type'));
    }

    public function test_switch_works_while_paused_but_waits_for_new_bot(): void
    {
        $settings = app(FacebookGroupRunnerSettings::class);
        $settings->pause('Admin tạm dừng');
        $settings->requestSwitch($this->page->id);

        $this->assertSame('idle', $this->poll('1.5.0-node')->json('type'));
        $this->assertSame($this->page->id, $settings->switchRequest());

        $this->assertSame('switch_profile', $this->poll()->json('type'));
    }

    public function test_bot_reports_result_and_admin_sees_it(): void
    {
        $this->postJson('/runner/fb/switch-result', ['profile_id' => $this->page->id, 'ok' => true, 'actor_id' => '61550000000009'], ['X-Runner-Token' => $this->token])
            ->assertOk();

        // Page thêm bằng tên rút gọn — lấy uid bot thấy sau khi chuyển.
        $this->assertSame('61550000000009', $this->page->fresh()->fb_id);

        app(FacebookGroupRunnerSettings::class)->recordRunner(['state' => 'ok', 'version' => self::BOT]);
        $this->actingAs($this->createAdmin())->get('/admin/fb-groups')->assertInertia(fn (Assert $page) => $page
            ->where('switching.supported', true)
            ->where('switching.requested_id', null)
            ->where('switching.result.profile_id', $this->page->id)
            ->where('switching.result.ok', true));

        $this->postJson('/runner/fb/switch-result', ['profile_id' => $this->page->id, 'ok' => false, 'error' => 'Không thấy nút "Chuyển ngay"'], ['X-Runner-Token' => $this->token])
            ->assertOk();
        $this->assertSame('Không thấy nút "Chuyển ngay"', app(FacebookGroupRunnerSettings::class)->switchResult()['error']);
    }

    public function test_switch_result_requires_token(): void
    {
        $this->postJson('/runner/fb/switch-result', ['profile_id' => $this->page->id, 'ok' => true])->assertForbidden();
    }
}
