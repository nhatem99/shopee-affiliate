<?php

namespace Tests\Feature;

use App\Jobs\AssembleZaloMirrorPost;
use App\Jobs\PublishZaloMirrorPost;
use App\Jobs\ReplyZaloGroupLink;
use App\Services\ZaloMirrorService;
use App\Services\ZaloMirrorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tính năng mirror: thu thập bài từ nhóm nguồn Zalo, chuyển link affiliate, đăng lại nhóm đích.
 */
class ZaloMirrorTest extends TestCase
{
    use RefreshDatabase;

    private const BRIDGE = 'http://127.0.0.1:8787';

    private const SOURCE_GROUP = '1111111111111111111';

    private const TARGET_GROUP = '2222222222222222222';

    private const SHOPEE_SHORT = 'https://s.shopee.vn/AbCdXyZ';

    private const SHOPEE_LONG = 'https://shopee.vn/product-i.1.2?mmp_pid=an_17344080577&utm_source=an_17344080577&utm_content=Test-22---';

    private const OWN_MMP_PID = 'an_17332410386';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'services.zalo_personal.bridge_url' => self::BRIDGE,
            'services.zalo_personal.token' => '',
            'services.zalo_personal.group_ids' => [],
            'services.zalo_personal.replies_per_10_minutes' => 20,
            'services.zalo_personal.mirror.buffer_seconds' => 20,
            'services.zalo_personal.mirror.max_images' => 10,
            'services.zalo_personal.mirror.dedupe_hours' => 24,
            'services.shopee_affiliate.mmp_pid' => self::OWN_MMP_PID,
        ]);

        // Bật mirror với một nhóm nguồn và một nhóm đích mặc định.
        $settings = app(ZaloMirrorSettings::class);
        $settings->save([
            'enabled' => true,
            'sourceGroupIds' => [self::SOURCE_GROUP],
            'targetGroupId' => self::TARGET_GROUP,
            'postsPerHour' => 10,
            'adminsOnly' => false,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function message(string $id, string $text, array $overrides = []): array
    {
        return array_replace([
            'messageId' => $id,
            'cliMsgId' => "cli-{$id}",
            'threadId' => self::SOURCE_GROUP,
            'threadType' => 'group',
            'senderId' => 'sender-99',
            'senderName' => 'Admin Nhóm',
            'msgType' => 'webchat',
            'text' => $text,
            'media' => null,
            'attachment' => null,
            'isSelf' => false,
            'mentions' => [],
            'quote' => null,
        ], $overrides);
    }

    private function photoMessage(string $id, string $imageUrl, string $caption = ''): array
    {
        return $this->message($id, $caption, [
            'msgType' => 'chat.photo',
            'media' => ['url' => $imageUrl],
        ]);
    }

    /** @param  list<array>  $messages */
    private function sse(array $messages): string
    {
        $frames = ["retry: 3000\n\n", ": ping\n\n"];
        foreach (array_values($messages) as $i => $msg) {
            $frames[] = 'id: '.($i + 1)."\nevent: message\ndata: ".json_encode($msg)."\n\n";
        }

        return implode('', $frames);
    }

    /** @param  list<array>  $messages */
    private function fakeBridgeWithMessages(array $messages, array $extraFakes = []): void
    {
        Http::fake(array_merge([
            self::BRIDGE.'/health' => Http::response(['ok' => true, 'loggedIn' => true, 'sessionDead' => false]),
            self::BRIDGE.'/events' => Http::response($this->sse($messages), 200, ['Content-Type' => 'text/event-stream']),
            self::BRIDGE.'/send' => Http::response(['success' => true]),
            self::BRIDGE.'/send-attachment' => Http::response(['success' => true]),
            self::BRIDGE.'/chat-info*' => Http::response($this->chatInfoResponse(self::SOURCE_GROUP, 'sender-99')),
            self::BRIDGE.'/contacts' => Http::response(['success' => true, 'groups' => [['id' => self::TARGET_GROUP, 'name' => 'Nhóm Của Mình']]]),
        ], $extraFakes));
    }

    private function chatInfoResponse(string $groupId, string $adminId): array
    {
        return [
            'threadId' => $groupId,
            'type' => 'group',
            'info' => [
                'gridInfoMap' => [
                    $groupId => [
                        'name' => 'Nhóm Nguồn',
                        'creatorId' => $adminId,
                        'adminIds' => [$adminId],
                        'totalMember' => 100,
                    ],
                ],
            ],
        ];
    }

    /** Fake Shopee redirect: s.shopee.vn → shopee.vn với mmp_pid của đối thủ. */
    private function fakeShopeeRedirect(string $from = self::SHOPEE_SHORT, string $to = self::SHOPEE_LONG): void
    {
        Http::fake([
            $from => Http::response('', 301, ['Location' => $to]),
        ]);
    }

    /** Fake Shopee redirect trả về shopee.vn với mmp_pid của mình. */
    private function fakeShopeeConvertible(string $from = self::SHOPEE_SHORT): void
    {
        $ownUrl = 'https://shopee.vn/product-i.1.2?mmp_pid='.self::OWN_MMP_PID.'&utm_source='.self::OWN_MMP_PID.'&utm_content=fb';
        Http::fake([$from => Http::response('', 301, ['Location' => $ownUrl])]);
    }

    // ── 1. Listener: nhóm nguồn → collector, không chạy bot reply ────────────

    public function test_listener_routes_source_group_to_collector_not_reply(): void
    {
        Queue::fake();
        $text = "Deal hot!\n".self::SHOPEE_SHORT;
        $this->fakeBridgeWithMessages([$this->message('m1', $text)]);

        $this->artisan('zalo:group-listen', ['--once' => true])->assertSuccessful();

        // AssembleZaloMirrorPost phải được xếp với delay — KHÔNG được xếp ReplyZaloGroupLink.
        Queue::assertPushed(AssembleZaloMirrorPost::class, fn ($j) => $j->sourceGroupId === self::SOURCE_GROUP);
        Queue::assertNotPushed(ReplyZaloGroupLink::class);
    }

    // ── 2. Nhóm không phải nguồn: bot reply vẫn chạy bình thường ─────────────

    public function test_non_source_groups_unaffected(): void
    {
        Queue::fake();
        $otherGroup = '9999999999999999999';
        $this->fakeBridgeWithMessages([
            $this->message('m1', self::SHOPEE_SHORT, ['threadId' => $otherGroup]),
        ]);

        $this->artisan('zalo:group-listen', ['--once' => true])->assertSuccessful();

        Queue::assertPushed(ReplyZaloGroupLink::class, fn ($j) => $j->threadId === $otherGroup);
        Queue::assertNotPushed(AssembleZaloMirrorPost::class);
    }

    // ── 3. Debounce: seq cũ → thoát; seq mới nhất → xếp PublishZaloMirrorPost ─

    public function test_assemble_job_exits_on_stale_seq(): void
    {
        Queue::fake();

        // Giả seq hiện tại = 5, job này mang seq = 3 → cũ hơn, phải thoát.
        $seqKey = 'zalo_mirror:seq:'.self::SOURCE_GROUP.':sender-99';
        Cache::put($seqKey, 5, now()->addMinutes(10));
        Cache::put('zalo_mirror:buf:'.self::SOURCE_GROUP.':sender-99', json_encode([
            ['type' => 'text', 'text' => 'hello', 'imageUrl' => null, 'ts' => time(), 'senderId' => 'sender-99', 'senderName' => 'Admin'],
        ]), now()->addMinutes(10));

        (new AssembleZaloMirrorPost(self::SOURCE_GROUP, 'sender-99', 3, self::TARGET_GROUP, self::SOURCE_GROUP, 'Admin'))->handle();

        Queue::assertNotPushed(PublishZaloMirrorPost::class);
    }

    public function test_assemble_job_dispatches_publish_when_seq_matches(): void
    {
        Queue::fake();

        $seqKey = 'zalo_mirror:seq:'.self::SOURCE_GROUP.':sender-99';
        $bufferKey = 'zalo_mirror:buf:'.self::SOURCE_GROUP.':sender-99';
        $pieces = [
            ['type' => 'text', 'text' => "Deal hot!\n".self::SHOPEE_SHORT, 'imageUrl' => null, 'ts' => time(), 'senderId' => 'sender-99', 'senderName' => 'Admin'],
        ];
        Cache::put($seqKey, 2, now()->addMinutes(10));
        Cache::put($bufferKey, json_encode($pieces), now()->addMinutes(10));

        (new AssembleZaloMirrorPost(self::SOURCE_GROUP, 'sender-99', 2, self::TARGET_GROUP, self::SOURCE_GROUP, 'Admin'))->handle();

        Queue::assertPushed(PublishZaloMirrorPost::class, fn ($j) => $j->targetGroupId === self::TARGET_GROUP
            && $j->sourceGroupId === self::SOURCE_GROUP
            && count($j->pieces) === 1);
    }

    // ── 4. Gom ảnh + text → một bài ─────────────────────────────────────────

    public function test_photo_and_text_from_same_sender_become_one_post(): void
    {
        Queue::fake();

        $ts = time();
        $pieces = [
            ['type' => 'photo', 'text' => '', 'imageUrl' => 'https://img.example.com/a.jpg', 'ts' => $ts, 'senderId' => 's1', 'senderName' => 'Admin'],
            ['type' => 'text', 'text' => "Quần Calem rẻ\n".self::SHOPEE_SHORT, 'imageUrl' => null, 'ts' => $ts + 1, 'senderId' => 's1', 'senderName' => 'Admin'],
        ];

        $seqKey = 'zalo_mirror:seq:'.self::SOURCE_GROUP.':s1';
        $bufferKey = 'zalo_mirror:buf:'.self::SOURCE_GROUP.':s1';
        Cache::put($seqKey, 1, now()->addMinutes(10));
        Cache::put($bufferKey, json_encode($pieces), now()->addMinutes(10));

        (new AssembleZaloMirrorPost(self::SOURCE_GROUP, 's1', 1, self::TARGET_GROUP, self::SOURCE_GROUP, 'Admin'))->handle();

        Queue::assertPushed(PublishZaloMirrorPost::class, fn ($j) => count($j->pieces) === 2);
    }

    public function test_buffer_with_two_posts_far_apart_is_split_by_send_time(): void
    {
        Queue::fake();

        // Worker kẹt / cầu nối phát lại: buffer chứa 2 bài gửi cách nhau 3 phút (ts mili giây).
        $ts = 1_790_000_000_000;
        $pieces = [
            ['type' => 'text', 'text' => "Bài sau\n".self::SHOPEE_SHORT, 'imageUrl' => null, 'ts' => $ts + 180_000, 'senderId' => 's1', 'senderName' => 'Admin'],
            ['type' => 'photo', 'text' => '', 'imageUrl' => 'https://img.example.com/a.jpg', 'ts' => $ts, 'senderId' => 's1', 'senderName' => 'Admin'],
            ['type' => 'text', 'text' => "Bài trước\n".self::SHOPEE_SHORT, 'imageUrl' => null, 'ts' => $ts + 800, 'senderId' => 's1', 'senderName' => 'Admin'],
        ];
        Cache::put('zalo_mirror:seq:'.self::SOURCE_GROUP.':s1', 3, now()->addMinutes(10));
        Cache::put('zalo_mirror:buf:'.self::SOURCE_GROUP.':s1', json_encode($pieces), now()->addMinutes(10));

        (new AssembleZaloMirrorPost(self::SOURCE_GROUP, 's1', 3, self::TARGET_GROUP, self::SOURCE_GROUP, 'Admin'))->handle();

        Queue::assertPushed(PublishZaloMirrorPost::class, 2);
        Queue::assertPushed(PublishZaloMirrorPost::class, fn ($j) => count($j->pieces) === 2
            && $j->pieces[0]['type'] === 'photo' && str_starts_with($j->pieces[1]['text'], 'Bài trước'));
        Queue::assertPushed(PublishZaloMirrorPost::class, fn ($j) => count($j->pieces) === 1
            && str_starts_with($j->pieces[0]['text'], 'Bài sau'));
    }

    // ── 5. Chuyển link Shopee → /go/{code} ───────────────────────────────────

    public function test_shopee_links_replaced_with_go_links(): void
    {
        $this->fakeShopeeConvertible();
        Http::fake([
            self::BRIDGE.'/send' => Http::response(['success' => true]),
            self::BRIDGE.'/chat-info*' => Http::response($this->chatInfoResponse(self::SOURCE_GROUP, 'sender-1')),
        ]);

        $pieces = [
            ['type' => 'text', 'text' => "Mua ngay\n".self::SHOPEE_SHORT, 'imageUrl' => null, 'ts' => time(), 'senderId' => 'sender-1', 'senderName' => 'Admin'],
        ];

        app(PublishZaloMirrorPost::class, [
            'pieces' => $pieces,
            'targetGroupId' => self::TARGET_GROUP,
            'sourceGroupId' => self::SOURCE_GROUP,
            'senderId' => 'sender-1',
            'senderName' => 'Admin',
        ])->handle(app(ZaloMirrorService::class));

        Http::assertSent(fn (Request $r) => $r->url() === self::BRIDGE.'/send'
            && str_contains($r['text'], '/go/')
            && ! str_contains($r['text'], self::SHOPEE_SHORT));
    }

    // ── 6. Dòng có link không phải Shopee bị xoá ─────────────────────────────

    public function test_non_shopee_url_lines_stripped(): void
    {
        $service = app(ZaloMirrorService::class);
        $text = "Deal ngon đây!\nhttps://zalo.me/g/abcdef\n".self::SHOPEE_SHORT."\nhttps://t.me/channel\nMua nhanh!";

        $filtered = $service->filterNonShopeeLines($text);

        $this->assertStringNotContainsString('zalo.me', $filtered);
        $this->assertStringNotContainsString('t.me', $filtered);
        $this->assertStringContainsString('s.shopee.vn', $filtered);
        $this->assertStringContainsString('Deal ngon đây!', $filtered);
        $this->assertStringContainsString('Mua nhanh!', $filtered);
    }

    // ── 7. Có link không đổi được → bỏ cả bài ────────────────────────────────

    public function test_unconvertible_link_skips_whole_post(): void
    {
        // Shopee redirect trả về không phải shopee.vn — link không đổi được.
        Http::fake([
            self::SHOPEE_SHORT => Http::response('', 301, ['Location' => 'https://some-other-site.com/abc']),
            self::BRIDGE.'/send' => Http::response(['success' => true]),
            self::BRIDGE.'/chat-info*' => Http::response($this->chatInfoResponse(self::SOURCE_GROUP, 'sender-1')),
        ]);

        $pieces = [
            ['type' => 'text', 'text' => "Deal!\n".self::SHOPEE_SHORT, 'imageUrl' => null, 'ts' => time(), 'senderId' => 'sender-1', 'senderName' => 'Admin'],
        ];

        app(PublishZaloMirrorPost::class, [
            'pieces' => $pieces,
            'targetGroupId' => self::TARGET_GROUP,
            'sourceGroupId' => self::SOURCE_GROUP,
            'senderId' => 'sender-1',
            'senderName' => 'Admin',
        ])->handle(app(ZaloMirrorService::class));

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/send'));

        $activity = app(ZaloMirrorService::class)->recentActivity();
        $this->assertSame('skipped_unconvertible', $activity[0]['status']);
    }

    // ── 8. Chống trùng bài từ nhóm chị em cross-post ─────────────────────────

    public function test_dedupe_across_sister_groups(): void
    {
        $this->fakeShopeeConvertible();
        Http::fake([
            self::BRIDGE.'/send' => Http::response(['success' => true]),
            self::BRIDGE.'/chat-info*' => Http::response($this->chatInfoResponse(self::SOURCE_GROUP, 'sender-1')),
        ]);

        $pieces = [
            ['type' => 'text', 'text' => "Quần Calem deal\n".self::SHOPEE_SHORT, 'imageUrl' => null, 'ts' => time(), 'senderId' => 'sender-1', 'senderName' => 'Admin'],
        ];

        $job = app(PublishZaloMirrorPost::class, [
            'pieces' => $pieces,
            'targetGroupId' => self::TARGET_GROUP,
            'sourceGroupId' => self::SOURCE_GROUP,
            'senderId' => 'sender-1',
            'senderName' => 'Admin',
        ]);
        $mirror = app(ZaloMirrorService::class);

        $job->handle($mirror); // Lần 1 — đăng được
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/send'));

        Http::fake([
            self::BRIDGE.'/send' => Http::response(['success' => true]),
            self::BRIDGE.'/chat-info*' => Http::response($this->chatInfoResponse(self::SOURCE_GROUP, 'sender-1')),
        ]);

        // Lần 2 từ nhóm chị em — cùng nội dung, phải bị chặn dedupe.
        $job->handle($mirror);

        Http::assertNothingSent();
        $activity = $mirror->recentActivity();
        $this->assertSame('skipped_duplicate', $activity[0]['status']);
    }

    // ── 9. Giới hạn tốc độ ────────────────────────────────────────────────────

    public function test_rate_cap_drops_excess_posts(): void
    {
        // postsPerHour = 1, gửi 2 bài khác nhau
        $settings = app(ZaloMirrorSettings::class);
        $settings->save([
            'enabled' => true,
            'sourceGroupIds' => [self::SOURCE_GROUP],
            'targetGroupId' => self::TARGET_GROUP,
            'postsPerHour' => 1,
            'adminsOnly' => false,
        ]);

        $this->fakeShopeeConvertible();

        $shopee2 = 'https://s.shopee.vn/OTHER123';
        $ownUrl2 = 'https://shopee.vn/product-i.3.4?mmp_pid='.self::OWN_MMP_PID.'&utm_source='.self::OWN_MMP_PID.'&utm_content=fb';
        Http::fake([
            self::SHOPEE_SHORT => Http::response('', 301, ['Location' => 'https://shopee.vn/product-i.1.2?mmp_pid='.self::OWN_MMP_PID.'&utm_source='.self::OWN_MMP_PID.'&utm_content=fb']),
            $shopee2 => Http::response('', 301, ['Location' => $ownUrl2]),
            self::BRIDGE.'/send' => Http::response(['success' => true]),
            self::BRIDGE.'/chat-info*' => Http::response($this->chatInfoResponse(self::SOURCE_GROUP, 'sender-1')),
        ]);

        $mirror = app(ZaloMirrorService::class);

        $job1 = app(PublishZaloMirrorPost::class, [
            'pieces' => [['type' => 'text', 'text' => "Bài 1\n".self::SHOPEE_SHORT, 'imageUrl' => null, 'ts' => time(), 'senderId' => 's1', 'senderName' => 'A']],
            'targetGroupId' => self::TARGET_GROUP, 'sourceGroupId' => self::SOURCE_GROUP, 'senderId' => 's1', 'senderName' => 'A',
        ]);
        $job2 = app(PublishZaloMirrorPost::class, [
            'pieces' => [['type' => 'text', 'text' => "Bài 2\n".$shopee2, 'imageUrl' => null, 'ts' => time() + 1, 'senderId' => 's2', 'senderName' => 'B']],
            'targetGroupId' => self::TARGET_GROUP, 'sourceGroupId' => self::SOURCE_GROUP, 'senderId' => 's2', 'senderName' => 'B',
        ]);

        $job1->handle($mirror); // Bài 1 đăng được
        $job2->handle($mirror); // Bài 2 bị rate limit

        $activity = $mirror->recentActivity();
        $statuses = array_column($activity, 'status');
        $this->assertContains('posted', $statuses);
        $this->assertContains('skipped_rate_limit', $statuses);
    }

    // ── 10. adminsOnly: bỏ qua người gửi không phải admin ────────────────────

    public function test_admins_only_skips_non_admin_sender(): void
    {
        $settings = app(ZaloMirrorSettings::class);
        $settings->save([
            'enabled' => true,
            'sourceGroupIds' => [self::SOURCE_GROUP],
            'targetGroupId' => self::TARGET_GROUP,
            'postsPerHour' => 10,
            'adminsOnly' => true, // BẬT adminsOnly
        ]);

        $this->fakeShopeeConvertible();
        Http::fake([
            self::BRIDGE.'/send' => Http::response(['success' => true]),
            // admin nhóm là 'admin-real', người gửi là 'member-xyz' → không phải admin
            self::BRIDGE.'/chat-info*' => Http::response($this->chatInfoResponse(self::SOURCE_GROUP, 'admin-real')),
        ]);

        $pieces = [
            ['type' => 'text', 'text' => "Deal\n".self::SHOPEE_SHORT, 'imageUrl' => null, 'ts' => time(), 'senderId' => 'member-xyz', 'senderName' => 'Thành Viên'],
        ];

        app(PublishZaloMirrorPost::class, [
            'pieces' => $pieces,
            'targetGroupId' => self::TARGET_GROUP,
            'sourceGroupId' => self::SOURCE_GROUP,
            'senderId' => 'member-xyz',
            'senderName' => 'Thành Viên',
        ])->handle(app(ZaloMirrorService::class));

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/send'));
        $activity = app(ZaloMirrorService::class)->recentActivity();
        $this->assertSame('skipped_not_admin', $activity[0]['status']);
    }

    // ── 11. Một ảnh → /send-attachment với urls + caption ─────────────────────

    public function test_single_image_sent_with_caption(): void
    {
        $this->fakeShopeeConvertible();
        Http::fake([
            self::BRIDGE.'/send-attachment' => Http::response(['success' => true]),
            self::BRIDGE.'/send' => Http::response(['success' => true]),
            self::BRIDGE.'/chat-info*' => Http::response($this->chatInfoResponse(self::SOURCE_GROUP, 'sender-1')),
        ]);

        $pieces = [
            ['type' => 'photo', 'text' => '', 'imageUrl' => 'https://img.example.com/a.jpg', 'ts' => time(), 'senderId' => 'sender-1', 'senderName' => 'Admin'],
            ['type' => 'text', 'text' => "Deal ngon\n".self::SHOPEE_SHORT, 'imageUrl' => null, 'ts' => time() + 1, 'senderId' => 'sender-1', 'senderName' => 'Admin'],
        ];

        app(PublishZaloMirrorPost::class, [
            'pieces' => $pieces,
            'targetGroupId' => self::TARGET_GROUP,
            'sourceGroupId' => self::SOURCE_GROUP,
            'senderId' => 'sender-1',
            'senderName' => 'Admin',
        ])->handle(app(ZaloMirrorService::class));

        // Một ảnh: /send-attachment với urls + caption trong cùng một lần gọi.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/send-attachment')
            && isset($r['urls'])
            && count($r['urls']) === 1
            && str_contains((string) ($r['caption'] ?? ''), '/go/'));

        // Một ảnh đã kèm caption → KHÔNG gửi thêm /send riêng.
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/send'));
    }

    // ── 12. Nhiều ảnh → album trước, /send text sau ────────────────────────────

    public function test_multi_images_album_then_text(): void
    {
        $this->fakeShopeeConvertible();
        Http::fake([
            self::BRIDGE.'/send-attachment' => Http::response(['success' => true]),
            self::BRIDGE.'/send' => Http::response(['success' => true]),
            self::BRIDGE.'/chat-info*' => Http::response($this->chatInfoResponse(self::SOURCE_GROUP, 'sender-1')),
        ]);

        $ts = time();
        $pieces = [
            ['type' => 'photo', 'text' => '', 'imageUrl' => 'https://img.example.com/a.jpg', 'ts' => $ts, 'senderId' => 'sender-1', 'senderName' => 'Admin'],
            ['type' => 'photo', 'text' => '', 'imageUrl' => 'https://img.example.com/b.jpg', 'ts' => $ts + 1, 'senderId' => 'sender-1', 'senderName' => 'Admin'],
            ['type' => 'text', 'text' => "Deal lớn\n".self::SHOPEE_SHORT, 'imageUrl' => null, 'ts' => $ts + 2, 'senderId' => 'sender-1', 'senderName' => 'Admin'],
        ];

        app(PublishZaloMirrorPost::class, [
            'pieces' => $pieces,
            'targetGroupId' => self::TARGET_GROUP,
            'sourceGroupId' => self::SOURCE_GROUP,
            'senderId' => 'sender-1',
            'senderName' => 'Admin',
        ])->handle(app(ZaloMirrorService::class));

        // Album nhiều ảnh: /send-attachment KHÔNG có caption, rồi /send text riêng.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/send-attachment')
            && count($r['urls'] ?? []) === 2
            && ! isset($r['caption']));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/send')
            && str_contains($r['text'], '/go/'));
    }

    // ── 13a. Cầu nối mới trả 422 (ảnh không tải được) → fallback text-only ──────

    public function test_bridge_422_download_failure_falls_back_to_text_only(): void
    {
        $this->fakeShopeeConvertible();
        Http::fake([
            self::BRIDGE.'/send-attachment' => Http::response(['error' => 'download failed for urls[0]: HTTP 403'], 422),
            self::BRIDGE.'/send' => Http::response(['success' => true]),
            self::BRIDGE.'/chat-info*' => Http::response($this->chatInfoResponse(self::SOURCE_GROUP, 'sender-1')),
        ]);

        $pieces = [
            ['type' => 'photo', 'text' => '', 'imageUrl' => 'https://img.example.com/gone.jpg', 'ts' => time(), 'senderId' => 'sender-1', 'senderName' => 'Admin'],
            ['type' => 'text', 'text' => "Deal\n".self::SHOPEE_SHORT, 'imageUrl' => null, 'ts' => time() + 1, 'senderId' => 'sender-1', 'senderName' => 'Admin'],
        ];

        // Không được ném exception — phải rơi về text-only.
        app(PublishZaloMirrorPost::class, [
            'pieces' => $pieces,
            'targetGroupId' => self::TARGET_GROUP,
            'sourceGroupId' => self::SOURCE_GROUP,
            'senderId' => 'sender-1',
            'senderName' => 'Admin',
        ])->handle(app(ZaloMirrorService::class));

        // Text được gửi thành công dù không có ảnh.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/send')
            && str_contains($r['text'], '/go/'));

        $activity = app(ZaloMirrorService::class)->recentActivity();
        $this->assertSame('posted', $activity[0]['status']);
        // Activity note phải ghi rõ ảnh bị bỏ.
        $this->assertStringContainsString('ảnh tải lỗi', $activity[0]['note']);
    }

    // ── 13. Cầu nối cũ trả 400 "paths" → fallback text-only ─────────────────

    public function test_old_bridge_falls_back_to_text_only(): void
    {
        $this->fakeShopeeConvertible();
        Http::fake([
            self::BRIDGE.'/send-attachment' => Http::response(['error' => 'threadId and paths required'], 400),
            self::BRIDGE.'/send' => Http::response(['success' => true]),
            self::BRIDGE.'/chat-info*' => Http::response($this->chatInfoResponse(self::SOURCE_GROUP, 'sender-1')),
        ]);

        $pieces = [
            ['type' => 'photo', 'text' => '', 'imageUrl' => 'https://img.example.com/a.jpg', 'ts' => time(), 'senderId' => 'sender-1', 'senderName' => 'Admin'],
            ['type' => 'text', 'text' => "Deal\n".self::SHOPEE_SHORT, 'imageUrl' => null, 'ts' => time() + 1, 'senderId' => 'sender-1', 'senderName' => 'Admin'],
        ];

        // Không được ném exception — phải rơi về text-only.
        app(PublishZaloMirrorPost::class, [
            'pieces' => $pieces,
            'targetGroupId' => self::TARGET_GROUP,
            'sourceGroupId' => self::SOURCE_GROUP,
            'senderId' => 'sender-1',
            'senderName' => 'Admin',
        ])->handle(app(ZaloMirrorService::class));

        // Text được gửi thành công dù không có ảnh.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/send')
            && str_contains($r['text'], '/go/'));

        $activity = app(ZaloMirrorService::class)->recentActivity();
        $this->assertSame('posted', $activity[0]['status']);
        // Activity note phải ghi rõ bridge chưa hỗ trợ ảnh.
        $this->assertStringContainsString('bridge chưa hỗ trợ ảnh', $activity[0]['note']);
    }

    // ── 13b. Link redirect tới shopee.vn nhưng KHÔNG có mmp_pid → bỏ cả bài ───

    public function test_shopee_url_without_mmp_pid_skips_whole_post(): void
    {
        // Kịch bản: rewriteToOwnAffiliate follow redirect tới shopee.vn nhưng URL đích không có
        // tham số mmp_pid (ví dụ link sản phẩm thường, không phải affiliate link). swapMmpPid
        // trả null → rewriteToOwnAffiliate trả URL gốc → check mmp_pid !== ours → bỏ bài.
        // Đây là nhánh duy nhất trong convertLinks mà host IS shopee.vn nhưng mmp_pid check fails.
        Http::fake([
            self::SHOPEE_SHORT => Http::response('', 301, ['Location' => 'https://shopee.vn/product-i.1.2']),
            self::BRIDGE.'/send' => Http::response(['success' => true]),
            self::BRIDGE.'/chat-info*' => Http::response($this->chatInfoResponse(self::SOURCE_GROUP, 'sender-1')),
        ]);

        $pieces = [
            ['type' => 'text', 'text' => "Deal!\n".self::SHOPEE_SHORT, 'imageUrl' => null, 'ts' => time(), 'senderId' => 'sender-1', 'senderName' => 'Admin'],
        ];

        app(PublishZaloMirrorPost::class, [
            'pieces' => $pieces,
            'targetGroupId' => self::TARGET_GROUP,
            'sourceGroupId' => self::SOURCE_GROUP,
            'senderId' => 'sender-1',
            'senderName' => 'Admin',
        ])->handle(app(ZaloMirrorService::class));

        // Không được gửi tin — link không có mmp_pid của mình không được đăng.
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/send'));

        $activity = app(ZaloMirrorService::class)->recentActivity();
        $this->assertSame('skipped_unconvertible', $activity[0]['status']);
    }

    // ── 13c. adminsOnly: /chat-info trả info null → skip, không cache empty ───

    public function test_admins_only_skips_when_chat_info_returns_null_info(): void
    {
        $settings = app(ZaloMirrorSettings::class);
        $settings->save([
            'enabled' => true,
            'sourceGroupIds' => [self::SOURCE_GROUP],
            'targetGroupId' => self::TARGET_GROUP,
            'postsPerHour' => 10,
            'adminsOnly' => true,
        ]);

        $this->fakeShopeeConvertible();
        Http::fake([
            self::BRIDGE.'/send' => Http::response(['success' => true]),
            // Bridge swallow error: info: null (rate-limit backoff, getGroupInfo lỗi nội bộ)
            self::BRIDGE.'/chat-info*' => Http::response([
                'threadId' => self::SOURCE_GROUP,
                'type' => 'group',
                'info' => null,
            ]),
        ]);

        $pieces = [
            ['type' => 'text', 'text' => "Deal\n".self::SHOPEE_SHORT, 'imageUrl' => null, 'ts' => time(), 'senderId' => 'sender-99', 'senderName' => 'Admin'],
        ];

        app(PublishZaloMirrorPost::class, [
            'pieces' => $pieces,
            'targetGroupId' => self::TARGET_GROUP,
            'sourceGroupId' => self::SOURCE_GROUP,
            'senderId' => 'sender-99',
            'senderName' => 'Admin',
        ])->handle(app(ZaloMirrorService::class));

        // Không được gửi vì không xác định được admin — bỏ qua an toàn.
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/send'));
        $activity = app(ZaloMirrorService::class)->recentActivity();
        $this->assertSame('skipped_not_admin', $activity[0]['status']);

        // Không cache empty list — lần gọi tiếp theo phải gọi bridge lại.
        $cacheKey = 'zalo_mirror:admins:'.self::SOURCE_GROUP;
        $this->assertNull(Cache::get($cacheKey), 'Không được cache danh sách admin rỗng');
    }

    // ── 13d. Rate cap: bài không đổi được link không tiêu quota ─────────────

    public function test_unconvertible_post_does_not_consume_rate_limit_slot(): void
    {
        $settings = app(ZaloMirrorSettings::class);
        $settings->save([
            'enabled' => true,
            'sourceGroupIds' => [self::SOURCE_GROUP],
            'targetGroupId' => self::TARGET_GROUP,
            'postsPerHour' => 1,
            'adminsOnly' => false,
        ]);

        // Bài 1: link không đổi được (redirect về site khác)
        Http::fake([
            self::SHOPEE_SHORT => Http::response('', 301, ['Location' => 'https://some-other-site.com/abc']),
            self::BRIDGE.'/send' => Http::response(['success' => true]),
        ]);

        $goodUrl = 'https://s.shopee.vn/GOOD123';
        $ownUrl = 'https://shopee.vn/product-i.5.6?mmp_pid='.self::OWN_MMP_PID.'&utm_source='.self::OWN_MMP_PID.'&utm_content=fb';

        $mirror = app(ZaloMirrorService::class);

        $job1 = app(PublishZaloMirrorPost::class, [
            'pieces' => [['type' => 'text', 'text' => "Bad\n".self::SHOPEE_SHORT, 'imageUrl' => null, 'ts' => time(), 'senderId' => 's1', 'senderName' => 'A']],
            'targetGroupId' => self::TARGET_GROUP, 'sourceGroupId' => self::SOURCE_GROUP, 'senderId' => 's1', 'senderName' => 'A',
        ]);
        $job1->handle($mirror); // skipped_unconvertible — không tiêu quota

        // Bài 2: link tốt — phải đăng được dù postsPerHour = 1 (quota chưa bị tiêu)
        Http::fake([
            $goodUrl => Http::response('', 301, ['Location' => $ownUrl]),
            self::BRIDGE.'/send' => Http::response(['success' => true]),
        ]);

        $job2 = app(PublishZaloMirrorPost::class, [
            'pieces' => [['type' => 'text', 'text' => "Good\n".$goodUrl, 'imageUrl' => null, 'ts' => time() + 1, 'senderId' => 's2', 'senderName' => 'B']],
            'targetGroupId' => self::TARGET_GROUP, 'sourceGroupId' => self::SOURCE_GROUP, 'senderId' => 's2', 'senderName' => 'B',
        ]);
        $job2->handle($mirror);

        $activity = $mirror->recentActivity();
        $statuses = array_column($activity, 'status');
        $this->assertContains('posted', $statuses, 'Bài tốt phải đăng được dù postsPerHour=1 và bài trước thất bại');
        $this->assertContains('skipped_unconvertible', $statuses);
        $this->assertNotContains('skipped_rate_limit', $statuses, 'Quota không được tiêu cho bài unconvertible');
    }

    // ── 14. Validate cài đặt mirror ở admin controller ───────────────────────

    public function test_settings_save_requires_digit_string_group_ids(): void
    {
        $this->actingAs($this->createAdmin())
            ->post('/admin/zalo-nick/mirror', [
                'enabled' => true,
                'sourceGroupIds' => ['NOT_A_NUMBER'],
                'targetGroupId' => self::TARGET_GROUP,
                'postsPerHour' => 5,
                'adminsOnly' => false,
            ])
            ->assertSessionHasErrors('sourceGroupIds.0');
    }

    public function test_settings_save_rejects_target_in_sources(): void
    {
        $this->actingAs($this->createAdmin())
            ->post('/admin/zalo-nick/mirror', [
                'enabled' => true,
                'sourceGroupIds' => [self::SOURCE_GROUP, self::TARGET_GROUP],
                'targetGroupId' => self::TARGET_GROUP, // đích cũng nằm trong nguồn
                'postsPerHour' => 5,
                'adminsOnly' => false,
            ])
            ->assertSessionHasErrors('targetGroupId');
    }

    public function test_settings_save_rejects_posts_per_hour_out_of_range(): void
    {
        $this->actingAs($this->createAdmin())
            ->post('/admin/zalo-nick/mirror', [
                'enabled' => true,
                'sourceGroupIds' => [self::SOURCE_GROUP],
                'targetGroupId' => self::TARGET_GROUP,
                'postsPerHour' => 0, // dưới giới hạn tối thiểu
                'adminsOnly' => false,
            ])
            ->assertSessionHasErrors('postsPerHour');
    }

    public function test_settings_save_stores_mirror_settings(): void
    {
        $this->actingAs($this->createAdmin())
            ->post('/admin/zalo-nick/mirror', [
                'enabled' => true,
                'sourceGroupIds' => [self::SOURCE_GROUP],
                'targetGroupId' => self::TARGET_GROUP,
                'postsPerHour' => 5,
                'adminsOnly' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $settings = app(ZaloMirrorSettings::class);
        $this->assertTrue($settings->enabled());
        $this->assertSame([self::SOURCE_GROUP], $settings->sourceGroupIds());
        $this->assertSame(self::TARGET_GROUP, $settings->targetGroupId());
        $this->assertSame(5, $settings->postsPerHour());
        $this->assertTrue($settings->adminsOnly());
    }

    public function test_settings_save_forbidden_for_non_admin(): void
    {
        $this->actingAs($this->createUser())
            ->post('/admin/zalo-nick/mirror', ['enabled' => true])
            ->assertForbidden();
    }

    // ── 15. GET /admin/zalo-nick/groups ───────────────────────────────────────

    public function test_groups_endpoint_returns_groups_from_bridge(): void
    {
        Http::fake([
            self::BRIDGE.'/contacts' => Http::response([
                'success' => true,
                'groups' => [['id' => self::TARGET_GROUP, 'name' => 'Nhóm Của Mình']],
                'friends' => [],
            ]),
        ]);

        $this->actingAs($this->createAdmin())
            ->getJson('/admin/zalo-nick/groups')
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('groups.0.id', self::TARGET_GROUP);
    }

    public function test_groups_endpoint_returns_error_when_bridge_down(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));

        $this->actingAs($this->createAdmin())
            ->getJson('/admin/zalo-nick/groups')
            ->assertOk()
            ->assertJson(['success' => false]);
    }

    // ── 16. index trả mirror props ───────────────────────────────────────────

    public function test_index_includes_mirror_settings_and_recent_activity(): void
    {
        Http::fake([
            self::BRIDGE.'/health' => Http::response(['ok' => true, 'loggedIn' => true, 'sessionDead' => false, 'sseClients' => 1]),
            self::BRIDGE.'/qr' => Http::response(['status' => 'logged_in']),
        ]);

        $this->actingAs($this->createAdmin())
            ->get('/admin/zalo-nick')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/ZaloNick')
                ->has('mirror')
                ->has('recentActivity')
                ->where('mirror.enabled', true)
                ->where('mirror.targetGroupId', self::TARGET_GROUP));
    }
}
