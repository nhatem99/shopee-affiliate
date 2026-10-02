<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * /admin/zalo-nick — quét QR đăng nhập nick Zalo cá nhân ngay trên web. Cầu nối giả bằng Http::fake.
 */
class ZaloNickAdminTest extends TestCase
{
    use RefreshDatabase;

    private const BRIDGE = 'http://127.0.0.1:8787';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zalo_personal.bridge_url' => self::BRIDGE,
            'services.zalo_personal.token' => 'bridge-secret',
        ]);
    }

    private function fakeBridge(array $health, array $qr): void
    {
        Http::fake([
            self::BRIDGE.'/health' => Http::response(array_replace(['ok' => true, 'sseClients' => 1], $health)),
            self::BRIDGE.'/qr' => Http::response($qr),
            self::BRIDGE.'/relogin' => Http::response(['success' => true]),
        ]);
    }

    public function test_page_shows_a_logged_in_nick(): void
    {
        $this->fakeBridge(['loggedIn' => true, 'sessionDead' => false, 'ownId' => '6430'], ['status' => 'logged_in', 'image' => null]);

        $this->actingAs($this->createAdmin())->get('/admin/zalo-nick')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/ZaloNick')
                ->where('status.reachable', true)
                ->where('status.loggedIn', true)
                ->where('status.listening', true)
                ->where('status.ownId', '6430')
                ->where('status.qr.image', null));
    }

    public function test_status_hands_out_the_qr_while_waiting_for_a_scan(): void
    {
        $this->fakeBridge(['loggedIn' => false, 'sessionDead' => false, 'ownId' => null, 'sseClients' => 0], ['status' => 'waiting_scan', 'image' => 'iVBORw0KGgo=']);

        $this->actingAs($this->createAdmin())->getJson('/admin/zalo-nick/status')
            ->assertOk()
            ->assertJson([
                'reachable' => true,
                'loggedIn' => false,
                'listening' => false,
                'qr' => ['status' => 'waiting_scan', 'image' => 'data:image/png;base64,iVBORw0KGgo='],
            ]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/qr') && $r->hasHeader('x-bridge-token', 'bridge-secret'));
    }

    /** Phiên bị Zalo đá: cầu nối vẫn báo loggedIn cũ, nhưng không được coi là đang đăng nhập. */
    public function test_dead_session_is_not_logged_in(): void
    {
        $this->fakeBridge(['loggedIn' => true, 'sessionDead' => true, 'sessionDeadReason' => 'DuplicateConnection'], ['status' => 'logged_in']);

        $this->actingAs($this->createAdmin())->getJson('/admin/zalo-nick/status')
            ->assertJson(['loggedIn' => false, 'sessionDead' => true, 'sessionDeadReason' => 'DuplicateConnection']);
    }

    public function test_status_says_so_when_the_bridge_is_down(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->actingAs($this->createAdmin())->getJson('/admin/zalo-nick/status')
            ->assertOk()
            ->assertJson(['reachable' => false])
            ->assertJsonPath('error', fn ($error) => str_contains($error, 'Connection refused'));
    }

    public function test_relogin_asks_the_bridge_for_a_fresh_qr(): void
    {
        $this->fakeBridge([], []);

        $this->actingAs($this->createAdmin())->post('/admin/zalo-nick/relogin')
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => $r->url() === self::BRIDGE.'/relogin' && $r['forceQR'] === true);
    }

    public function test_relogin_reports_a_dead_bridge(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->actingAs($this->createAdmin())->post('/admin/zalo-nick/relogin')
            ->assertRedirect()
            ->assertSessionHasErrors('relogin');
    }

    /** Mã QR là chìa khoá vào nick — chỉ admin được thấy. */
    public function test_customers_cannot_see_the_qr(): void
    {
        Http::fake();

        $this->actingAs($this->createUser())->getJson('/admin/zalo-nick/status')->assertForbidden();
        $this->actingAs($this->createUser())->post('/admin/zalo-nick/relogin')->assertForbidden();
        Http::assertNothingSent();
    }
}
