<?php

namespace Tests\Feature;

use App\Jobs\ReplyZaloGroupLink;
use App\Models\ShortLink;
use App\Services\KieuShopeeService;
use App\Services\ZaloGroupLinkReplyService;
use App\Services\ZaloPersonalBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Nick Zalo cá nhân (qua cầu nối hermes-zalo-plugin) trả link có mã khi khách dán link Shopee
 * trong nhóm. Cầu nối được giả bằng Http::fake — kể cả luồng SSE /events.
 */
class ZaloGroupBotTest extends TestCase
{
    use RefreshDatabase;

    private const BRIDGE = 'http://127.0.0.1:8787';

    private const GROUP = 'group-111';

    private const SHOPEE_URL = 'https://s.shopee.vn/AbCd1234';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.zalo_personal.bridge_url' => self::BRIDGE,
            'services.zalo_personal.token' => 'bridge-secret',
            'services.zalo_personal.group_ids' => [],
            'services.zalo_personal.replies_per_10_minutes' => 20,
        ]);
    }

    /** Một tin đến đúng dạng zaloClient.js::_normaliseMessage của cầu nối. */
    private function message(string $id, string $text, array $overrides = []): array
    {
        return array_replace([
            'messageId' => $id,
            'cliMsgId' => "cli-{$id}",
            'threadId' => self::GROUP,
            'threadType' => 'group',
            'senderId' => 'user-1',
            'senderName' => 'Khách A',
            'text' => $text,
            'attachment' => null,
            'isSelf' => false,
            'mentions' => [],
            'quote' => ['msgId' => $id, 'cliMsgId' => "cli-{$id}", 'uidFrom' => 'user-1', 'content' => $text],
        ], $overrides);
    }

    /** @param  list<array>  $messages */
    private function sse(array $messages): string
    {
        $frames = ["retry: 3000\n\n", ": ping\n\n"];
        foreach (array_values($messages) as $i => $message) {
            $frames[] = 'id: '.($i + 1)."\nevent: message\ndata: ".json_encode($message)."\n\n";
        }

        return implode('', $frames);
    }

    /** @param  list<array>  $messages */
    private function fakeBridge(array $messages, bool $loggedIn = true): void
    {
        Http::fake([
            self::BRIDGE.'/health' => Http::response(['ok' => true, 'loggedIn' => $loggedIn, 'sessionDead' => false, 'ownId' => 'bot-uid']),
            self::BRIDGE.'/events' => Http::response($this->sse($messages), 200, ['Content-Type' => 'text/event-stream']),
            self::BRIDGE.'/send' => Http::response(['success' => true]),
        ]);
    }

    private function fakeVoucherSource(?string $voucherLink = 'https://shopee.vn/product-i.1.2?mmp_pid=kieushopee'): void
    {
        $mock = Mockery::mock(KieuShopeeService::class);
        $mock->shouldReceive('fetchProductAndVoucherLink')->andReturn([
            'voucher_link' => $voucherLink,
            'shop_id' => '1',
            'item_id' => '2',
            'product' => ['product_name' => 'Áo *Hoodie* nam', 'product_image' => 'https://cf.shopee.vn/file/a.jpg'],
        ]);
        $this->app->instance(KieuShopeeService::class, $mock);
    }

    // ── Nhận tin ─────────────────────────────────────────────────────────────

    public function test_queues_a_reply_for_a_shopee_link_in_a_group(): void
    {
        Queue::fake();
        $this->fakeBridge([$this->message('m1', 'Ai có mã cho món này không '.self::SHOPEE_URL)]);

        $this->artisan('zalo:group-listen', ['--once' => true])->assertSuccessful();

        Queue::assertPushed(ReplyZaloGroupLink::class, function (ReplyZaloGroupLink $job) {
            return $job->threadId === self::GROUP
                && $job->threadType === 'group'
                && $job->url === self::SHOPEE_URL
                && $job->quote['msgId'] === 'm1';
        });
        $this->assertSame(1, Cache::get('zalo_personal:last_event_id'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/events') && $r->hasHeader('x-bridge-token', 'bridge-secret'));
    }

    /** Zalo biến link dán vào thành thẻ xem trước — URL nằm ở attachment.href, text chỉ là tiêu đề. */
    public function test_finds_the_link_inside_a_link_preview(): void
    {
        Queue::fake();
        $this->fakeBridge([$this->message('m1', 'Áo hoodie nam form rộng', [
            'msgType' => 'chat.link',
            'attachment' => ['title' => 'Áo hoodie nam form rộng', 'href' => self::SHOPEE_URL],
        ])]);

        $this->artisan('zalo:group-listen', ['--once' => true])->assertSuccessful();

        Queue::assertPushed(ReplyZaloGroupLink::class, fn ($job) => $job->url === self::SHOPEE_URL);
    }

    public function test_ignores_own_messages_other_links_and_plain_chat(): void
    {
        Queue::fake();
        $this->fakeBridge([
            $this->message('m1', 'Chào cả nhà'),
            $this->message('m2', 'xem video https://youtube.com/watch?v=abc'),
            $this->message('m3', '👉 '.self::SHOPEE_URL, ['isSelf' => true]),
        ]);

        $this->artisan('zalo:group-listen', ['--once' => true])->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** Nối lại với Last-Event-ID thì cầu nối phát lại tin cũ — không được trả lời hai lần. */
    public function test_replies_once_per_message_id(): void
    {
        Queue::fake();
        $this->fakeBridge([
            $this->message('m1', self::SHOPEE_URL),
            $this->message('m1', self::SHOPEE_URL),
        ]);

        $this->artisan('zalo:group-listen', ['--once' => true])->assertSuccessful();

        Queue::assertPushed(ReplyZaloGroupLink::class, 1);
    }

    public function test_only_answers_listed_groups_when_a_list_is_set(): void
    {
        Queue::fake();
        config(['services.zalo_personal.group_ids' => ['group-allowed']]);
        $this->fakeBridge([
            $this->message('m1', self::SHOPEE_URL),
            $this->message('m2', self::SHOPEE_URL, ['threadId' => 'group-allowed']),
            // Nhắn riêng cho nick thì không bị danh sách nhóm chặn.
            $this->message('m3', self::SHOPEE_URL, ['threadId' => 'user-9', 'threadType' => 'user']),
        ]);

        $this->artisan('zalo:group-listen', ['--once' => true])
            ->expectsOutputToContain('Bỏ qua nhóm '.self::GROUP)
            ->assertSuccessful();

        Queue::assertPushed(ReplyZaloGroupLink::class, 2);
        Queue::assertNotPushed(ReplyZaloGroupLink::class, fn ($job) => $job->threadId === self::GROUP);
    }

    public function test_stops_replying_in_a_group_past_the_rate_limit(): void
    {
        Queue::fake();
        config(['services.zalo_personal.replies_per_10_minutes' => 2]);
        $this->fakeBridge([
            $this->message('m1', self::SHOPEE_URL),
            $this->message('m2', self::SHOPEE_URL),
            $this->message('m3', self::SHOPEE_URL),
        ]);

        $this->artisan('zalo:group-listen', ['--once' => true])->assertSuccessful();

        Queue::assertPushed(ReplyZaloGroupLink::class, 2);
    }

    public function test_refuses_to_start_when_the_bridge_is_not_logged_in(): void
    {
        $this->fakeBridge([], loggedIn: false);

        $this->artisan('zalo:group-listen', ['--once' => true])
            ->expectsOutputToContain('chưa đăng nhập Zalo')
            ->assertFailed();
    }

    // ── Trả lời ──────────────────────────────────────────────────────────────

    public function test_reply_is_a_go_link_to_our_own_affiliate_link(): void
    {
        $this->fakeVoucherSource();

        $reply = app(ZaloGroupLinkReplyService::class)->replyFor('https://shopee.vn/Ao-Hoodie-i.1.2');

        $link = ShortLink::sole();
        $this->assertStringContainsString(url('/go/'.$link->code), $reply['text']);
        $this->assertStringNotContainsString('mmp_pid=kieushopee', $link->target_url);
        $this->assertSame('Áo *Hoodie* nam', $link->product_name);

        // Dòng đầu in đậm; độ dài đếm theo UTF-16 như Zalo: emoji 🛍️ = 3 đơn vị, dấu cách = 1.
        $this->assertStringStartsWith("🛍️ Áo *Hoodie* nam\n", $reply['text']);
        $this->assertSame([['start' => 0, 'len' => 3 + 1 + mb_strlen('Áo *Hoodie* nam'), 'st' => 'b']], $reply['styles']);
    }

    public function test_reply_says_so_when_there_is_no_voucher(): void
    {
        $this->fakeVoucherSource(voucherLink: null);

        $reply = app(ZaloGroupLinkReplyService::class)->replyFor('https://shopee.vn/Ao-Hoodie-i.1.2');

        $this->assertStringContainsString('chưa lấy được mã', $reply['text']);
        $this->assertSame(0, ShortLink::count());
    }

    public function test_job_sends_the_reply_quoting_the_original_message(): void
    {
        $this->fakeVoucherSource();
        $this->fakeBridge([]);

        (new ReplyZaloGroupLink(self::GROUP, 'group', 'https://shopee.vn/Ao-Hoodie-i.1.2', ['msgId' => 'm1']))
            ->handle(app(ZaloGroupLinkReplyService::class), app(ZaloPersonalBridge::class));

        Http::assertSent(function (Request $r) {
            return $r->url() === self::BRIDGE.'/send'
                && $r['threadId'] === self::GROUP
                && $r['threadType'] === 'group'
                && $r['quote'] === ['msgId' => 'm1']
                && str_contains($r['text'], '/go/')
                && $r['styles'][0]['st'] === 'b'
                && $r->hasHeader('x-bridge-token', 'bridge-secret');
        });
    }
}
