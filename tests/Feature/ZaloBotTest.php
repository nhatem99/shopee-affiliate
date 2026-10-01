<?php

namespace Tests\Feature;

use App\Jobs\SendZaloMessage;
use App\Models\Commission;
use App\Models\PayoutAccount;
use App\Models\Setting;
use App\Models\User;
use App\Models\ZaloChat;
use App\Services\ZaloBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ZaloBotTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zalo_bot.token' => '123:abc',
            'services.zalo_bot.webhook_secret' => self::SECRET,
            'services.zalo_bot.admin_chat_ids' => ['admin-chat-1', 'admin-chat-2'],
            'services.zalo_bot.api_base' => 'https://bot-api.zaloplatforms.com',
        ]);
    }

    private function update(string $messageId = 'msg-1', string $text = 'Xin chào'): array
    {
        return [
            'ok' => true,
            'result' => [
                'event_name' => 'message.text.received',
                'message' => [
                    'from' => ['id' => 'chat-abc', 'display_name' => 'Ted', 'is_bot' => false],
                    'chat' => ['id' => 'chat-abc', 'chat_type' => 'PRIVATE'],
                    'text' => $text,
                    'message_id' => $messageId,
                    'date' => 1750316131602,
                ],
            ],
        ];
    }

    private function postWebhook(array $payload, ?string $secret = self::SECRET)
    {
        $headers = $secret === null ? [] : ['X-Bot-Api-Secret-Token' => $secret];

        return $this->postJson('/webhooks/zalo', $payload, $headers);
    }

    // ── Webhook ──────────────────────────────────────────────────────────────

    public function test_webhook_rejects_missing_or_wrong_secret(): void
    {
        $this->postWebhook($this->update(), null)->assertForbidden();
        $this->postWebhook($this->update(), 'wrong')->assertForbidden();

        $this->assertSame(0, ZaloChat::count());
    }

    public function test_webhook_rejects_everything_when_secret_not_configured(): void
    {
        config(['services.zalo_bot.webhook_secret' => null]);

        $this->postWebhook($this->update(), '')->assertForbidden();
    }

    public function test_webhook_records_chat(): void
    {
        $this->postWebhook($this->update())->assertOk();

        $chat = ZaloChat::firstOrFail();
        $this->assertSame('chat-abc', $chat->chat_id);
        $this->assertSame('Ted', $chat->display_name);
        $this->assertSame('PRIVATE', $chat->chat_type);
        $this->assertNotNull($chat->last_message_at);
    }

    public function test_webhook_ignores_duplicate_message(): void
    {
        Queue::fake();

        $this->postWebhook($this->update('dup', '/id'))->assertOk();
        $this->postWebhook($this->update('dup', '/id'))->assertOk();

        $this->assertSame(1, ZaloChat::count());
        Queue::assertPushed(SendZaloMessage::class, 1);
    }

    public function test_id_command_replies_with_chat_id(): void
    {
        Queue::fake();

        $this->postWebhook($this->update('m1', '/id'))->assertOk();

        Queue::assertPushed(SendZaloMessage::class, fn ($job) => $job->chatId === 'chat-abc'
            && str_contains($job->text, 'chat-abc'));
    }

    public function test_webhook_bypasses_csrf_and_maintenance_mode(): void
    {
        Setting::set('maintenance_mode', '1');

        // postJson không gửi token CSRF; route nằm ngoài nhóm web nên vẫn phải qua.
        $this->postWebhook($this->update())->assertOk();
        $this->assertSame(1, ZaloChat::count());
    }

    // ── Báo admin khi có lệnh rút ────────────────────────────────────────────

    private function userReadyToWithdraw(): User
    {
        $user = $this->createUser(['name' => 'Khách A', 'email' => 'a@example.com']);
        Commission::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'status' => 'approved',
            'order_id' => 'SHOPEE-1',
            'confirmed_at' => now(),
        ]);
        PayoutAccount::create([
            'user_id' => $user->id,
            'provider' => 'momo',
            'account_number' => '0901234567',
            'account_name' => 'KHACH A',
        ]);

        return $user;
    }

    public function test_new_withdrawal_notifies_every_admin_chat(): void
    {
        Queue::fake();
        $user = $this->userReadyToWithdraw();

        $this->actingAs($user)
            ->post('/withdrawals', ['provider' => 'momo', 'amount' => 20000])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(SendZaloMessage::class, 2);
        Queue::assertPushed(SendZaloMessage::class, fn ($job) => $job->chatId === 'admin-chat-1'
            && str_contains($job->text, '20.000đ')
            && str_contains($job->text, 'Khách A')
            && str_contains($job->text, 'MoMo 0901234567'));
    }

    public function test_failed_withdrawal_does_not_notify(): void
    {
        Queue::fake();
        $user = $this->userReadyToWithdraw();

        $this->actingAs($user)
            ->post('/withdrawals', ['provider' => 'momo', 'amount' => 999999])
            ->assertSessionHasErrors('amount');

        Queue::assertNothingPushed();
    }

    public function test_withdrawal_works_without_zalo_configured(): void
    {
        Queue::fake();
        config(['services.zalo_bot.token' => null]);
        $user = $this->userReadyToWithdraw();

        $this->actingAs($user)
            ->post('/withdrawals', ['provider' => 'momo', 'amount' => 20000])
            ->assertSessionHasNoErrors();

        Queue::assertNothingPushed();
    }

    // ── Service ──────────────────────────────────────────────────────────────

    public function test_send_message_splits_long_text(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 'x']])]);

        $line = str_repeat('a', 1500);
        app(ZaloBotService::class)->sendMessage('chat-abc', $line."\n".$line);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/bot123:abc/sendMessage')
            && $request['chat_id'] === 'chat-abc'
            && mb_strlen($request['text']) <= ZaloBotService::MAX_TEXT_LENGTH);
    }

    public function test_api_error_does_not_leak_token(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'bad token 123:abc'], 401)]);

        try {
            app(ZaloBotService::class)->sendMessage('chat-abc', 'hi');
            $this->fail('Phải ném lỗi');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('[401]', $e->getMessage());
            $this->assertStringNotContainsString('123:abc', $e->getMessage());
        }
    }

    public function test_set_webhook_command_registers_route_url_with_secret(): void
    {
        config(['app.url' => 'https://tietkiemvi.com']);
        url()->forceRootUrl('https://tietkiemvi.com');
        url()->forceScheme('https');
        Http::fake(['*' => Http::response(['ok' => true, 'result' => [
            'url' => 'https://tietkiemvi.com/webhooks/zalo',
            'verification' => ['ok' => true, 'outcome' => 'webhook.ok', 'hint' => 'ok'],
        ]])]);

        $this->artisan('zalo:set-webhook')->assertSuccessful();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === 'https://tietkiemvi.com/webhooks/zalo'
            && $request['secret_token'] === self::SECRET);
    }

    public function test_set_webhook_refuses_plain_http(): void
    {
        Http::fake();

        $this->artisan('zalo:set-webhook', ['--url' => 'http://localhost:8000/webhooks/zalo'])->assertFailed();

        Http::assertNothingSent();
    }
}
