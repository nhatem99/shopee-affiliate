<?php

namespace Tests\Feature;

use App\Exceptions\AffiliateScanException;
use App\Models\FacebookGroup;
use App\Models\FacebookGroupDeal;
use App\Models\FacebookGroupPost;
use App\Models\Setting;
use App\Models\ShortLink;
use App\Services\FacebookGroupPostScheduler;
use App\Services\FacebookGroupRunnerSettings;
use App\Services\FacebookPostImages;
use App\Services\VoucherFetchResult;
use App\Services\VoucherFetchService;
use App\Services\ZaloAdminNotifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class FacebookGroupRunnerApiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'claim-key-000000000001';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.shopee_affiliate.mmp_pid' => 'an_ours']);
        $this->travelTo(Carbon::parse('2026-10-03 09:00:00'));
        $this->token = app(FacebookGroupRunnerSettings::class)->regenerateToken();
    }

    private function poll(string $key = self::KEY, string $state = 'ok', string $version = '1.0')
    {
        return $this->postJson('/runner/fb/poll', [
            'claim_key' => $key,
            'state' => $state,
            'version' => $version,
            'account' => 'Nick test',
        ], ['X-Runner-Token' => $this->token]);
    }

    private function report(FacebookGroupPost $post, string $status, string $key = self::KEY, ?string $error = null)
    {
        return $this->postJson("/runner/fb/posts/{$post->id}/result", [
            'claim_key' => $key,
            'status' => $status,
            'error' => $error,
        ], ['X-Runner-Token' => $this->token]);
    }

    private function group(array $attributes = []): FacebookGroup
    {
        static $n = 0;
        $n++;

        return FacebookGroup::create(array_merge([
            'fb_group_key' => "group{$n}",
            'name' => "Nhóm {$n}",
            'url' => FacebookGroup::urlFor("group{$n}"),
            'enabled' => true,
            'source' => 'sync',
        ], $attributes));
    }

    private function deal(array $attributes = []): FacebookGroupDeal
    {
        return FacebookGroupDeal::create(array_merge([
            'shopee_url' => 'https://shopee.vn/ao-thun-i.11.22',
            'source' => 'kieushopee',
            'product' => ['product_name' => 'Áo thun', 'product_image' => 'https://down-vn.img.susercontent.com/file/abc.webp'],
            'caption' => "{Deal hời|Giá tốt}: Áo thun\n\n{link}",
            'fallback_buy_url' => null,
        ], $attributes));
    }

    private function queue(?FacebookGroupDeal $deal = null, ?FacebookGroup $group = null, array $attributes = []): FacebookGroupPost
    {
        return FacebookGroupPost::create(array_merge([
            'facebook_group_deal_id' => ($deal ?? $this->deal())->id,
            'facebook_group_id' => ($group ?? $this->group())->id,
            'status' => FacebookGroupPost::PENDING,
            'queued_at' => now(),
        ], $attributes));
    }

    private function fetchReturns(?string $ytbUrl = null): void
    {
        $fetcher = Mockery::mock(VoucherFetchService::class);
        $fetcher->shouldReceive('fetch')->andReturn(new VoucherFetchResult(
            'kieushopee',
            'https://shopee.vn/product/11/22',
            'https://shopee.vn/product/11/22',
            [
                'voucher_link' => 'https://shopee.vn/product/11/22?mmp_pid=an_source&credential_token=abc',
                'shop_id' => '11',
                'item_id' => '22',
                'product' => ['product_name' => 'Áo thun', 'product_image' => 'https://down-vn.img.susercontent.com/file/abc.webp'],
            ],
            $ytbUrl,
        ));
        $this->app->instance(VoucherFetchService::class, $fetcher);
    }

    private function fetchThrows(): void
    {
        $fetcher = Mockery::mock(VoucherFetchService::class);
        $fetcher->shouldReceive('fetch')->andThrow(new AffiliateScanException('Nguồn mã đang lỗi'));
        $this->app->instance(VoucherFetchService::class, $fetcher);
    }

    // ── Xác thực ─────────────────────────────────────────────────────────────

    public function test_rejects_missing_or_wrong_token(): void
    {
        $this->postJson('/runner/fb/poll', ['claim_key' => self::KEY])->assertForbidden();
        $this->postJson('/runner/fb/poll', ['claim_key' => self::KEY], ['X-Runner-Token' => 'wrong'])->assertForbidden();
        $this->postJson('/runner/fb/groups', ['groups' => []], ['X-Runner-Token' => 'wrong'])->assertForbidden();
    }

    public function test_poll_without_claim_key_checks_token_without_claiming(): void
    {
        // Bot điện thoại (tools/fb-group-poster, lệnh check) dựa vào 422 này để thử token.
        $post = $this->queue();

        $this->postJson('/runner/fb/poll', [], ['X-Runner-Token' => $this->token])->assertUnprocessable();

        $this->assertSame(FacebookGroupPost::PENDING, $post->fresh()->status);
    }

    public function test_rejects_everything_when_no_token_created(): void
    {
        Setting::where('key', 'fb_runner_token')->delete();

        $this->postJson('/runner/fb/poll', ['claim_key' => self::KEY], ['X-Runner-Token' => ''])->assertForbidden();
    }

    // ── Nhận bài ─────────────────────────────────────────────────────────────

    public function test_idle_when_nothing_queued_and_records_runner(): void
    {
        $this->poll()->assertOk()->assertJson(['type' => 'idle', 'reason' => 'empty']);

        $status = app(FacebookGroupRunnerSettings::class)->runnerStatus();
        $this->assertSame('Nick test', $status['account']);
        $this->assertNotNull($status['last_seen_at']);
    }

    public function test_claims_post_with_fresh_link_carrying_our_affiliate_id(): void
    {
        $this->fetchReturns();
        $post = $this->queue();

        $response = $this->poll()->assertOk()->assertJson([
            'type' => 'post',
            'post_id' => $post->id,
            'group_url' => $post->group->url,
            'image_url' => 'https://down-vn.img.susercontent.com/file/abc.webp',
        ]);

        $post->refresh();
        $link = ShortLink::findOrFail($post->short_link_id);
        $this->assertStringContainsString('mmp_pid=an_ours', $link->target_url);
        $this->assertStringNotContainsString('an_source', $link->target_url);
        $this->assertStringContainsString(url('/go/'.$link->code), $response->json('caption'));
        $this->assertStringNotContainsString('{', $response->json('caption'));
        $this->assertSame(FacebookGroupPost::CLAIMED, $post->status);
        $this->assertSame('fresh', $post->link_kind);
        $this->assertNotNull($post->group->last_attempt_at);
    }

    public function test_links_use_main_domain_whatever_host_the_bot_calls(): void
    {
        config(['app.url' => 'https://tietkiemvi.test']);
        $this->fetchReturns();
        $this->queue();

        $caption = $this->postJson('https://103.74.100.15/runner/fb/poll', ['claim_key' => self::KEY], ['X-Runner-Token' => $this->token])
            ->assertOk()->json('caption');

        $this->assertStringContainsString('https://tietkiemvi.test/go/', $caption);
        $this->assertStringNotContainsString('103.74.100.15', $caption);
    }

    public function test_ytb_mode_caption_has_activation_link_first(): void
    {
        $this->fetchReturns('https://ganma.vn/yt/abc');
        $this->queue();

        $caption = $this->poll()->assertOk()->json('caption');

        $this->assertStringContainsString('1️⃣', $caption);
        $this->assertStringContainsString(url('/ytb/'), $caption);
        $this->assertLessThan(strpos($caption, url('/go/')), strpos($caption, url('/ytb/')));
    }

    public function test_uses_compose_time_link_when_fresh_link_fails(): void
    {
        $this->fetchThrows();
        $link = ShortLink::create(['code' => 'old123', 'target_url' => 'https://shopee.vn/x?mmp_pid=an_ours', 'target_hash' => sha1('x'), 'clicks' => 0]);
        $post = $this->queue($this->deal(['fallback_buy_url' => url('/go/old123')]));

        $caption = $this->poll()->assertOk()->assertJson(['type' => 'post'])->json('caption');

        $this->assertStringContainsString(url('/go/old123'), $caption);
        $post->refresh();
        $this->assertSame('fallback', $post->link_kind);
        $this->assertSame($link->id, $post->short_link_id);
    }

    public function test_fails_post_when_no_link_at_all(): void
    {
        $this->fetchThrows();
        $post = $this->queue();

        $this->poll()->assertOk()->assertJson(['type' => 'idle', 'reason' => 'link_failed']);

        $post->refresh();
        $this->assertSame(FacebookGroupPost::FAILED, $post->status);
        $this->assertStringContainsString('Nguồn mã đang lỗi', $post->error);
        $this->assertNull($post->group->last_attempt_at);
    }

    // ── Bài tự soạn, ảnh tự tải lên ──────────────────────────────────────────

    private function uploadedImage(): string
    {
        $name = bin2hex(random_bytes(16)).'.jpg';
        Storage::disk('local')->put(FacebookPostImages::DIR.'/'.$name, 'jpeg-bytes');

        return $name;
    }

    public function test_custom_post_has_no_link_and_carries_uploaded_images_in_order(): void
    {
        Storage::fake('local');
        $fetcher = Mockery::mock(VoucherFetchService::class);
        $fetcher->shouldNotReceive('fetch');
        $this->app->instance(VoucherFetchService::class, $fetcher);
        [$a, $b] = [$this->uploadedImage(), $this->uploadedImage()];
        $post = $this->queue($this->deal([
            'shopee_url' => null,
            'source' => null,
            'product' => null,
            'caption' => "{Chào|Hello} cả nhà\nMẹo săn sale hôm nay",
            'images' => [$a, $b],
        ]));

        $response = $this->poll(version: '1.1.0-node')->assertOk()->assertJson(['type' => 'post', 'post_id' => $post->id, 'image_url' => null]);

        $this->assertSame(["/runner/fb/images/{$a}", "/runner/fb/images/{$b}"], $response->json('images'));
        $this->assertStringEndsWith("cả nhà\nMẹo săn sale hôm nay", $response->json('caption'));
        $this->assertStringNotContainsString('{', $response->json('caption'));
        $post->refresh();
        $this->assertSame(FacebookGroupPost::CLAIMED, $post->status);
        $this->assertNull($post->buy_url);
        $this->assertSame(0, ShortLink::count());
    }

    public function test_product_image_goes_first_and_admin_can_drop_it(): void
    {
        Storage::fake('local');
        $this->fetchReturns();
        $name = $this->uploadedImage();
        $this->queue($this->deal(['images' => [$name]]));

        $this->assertSame(
            ['https://down-vn.img.susercontent.com/file/abc.webp', "/runner/fb/images/{$name}"],
            $this->poll(version: '1.1.0')->assertOk()->json('images'),
        );

        $this->travel(20)->minutes(); // bài trước thành "không rõ", hết khoá "bot đang bận"
        $this->queue($this->deal(['with_product_image' => false]));
        $response = $this->poll('claim-key-000000000002', version: '1.1.0')->assertOk()->assertJson(['type' => 'post', 'image_url' => null]);
        $this->assertSame([], $response->json('images'));
    }

    public function test_old_runner_leaves_posts_with_uploaded_images_waiting(): void
    {
        Storage::fake('local');
        $this->fetchReturns();
        $withUpload = $this->queue($this->deal(['images' => [$this->uploadedImage()]]));
        $plain = $this->queue();

        $this->poll(version: '1.0.0-node')->assertOk()->assertJson(['type' => 'post', 'post_id' => $plain->id]);
        $this->report($plain, 'posted')->assertOk();
        app(FacebookGroupRunnerSettings::class)->setNextAllowedAt(null);

        $this->poll('claim-key-000000000002', version: '1.0.0-node')->assertOk()->assertJson(['type' => 'idle', 'reason' => 'empty']);
        $this->assertSame(FacebookGroupPost::PENDING, $withUpload->fresh()->status);

        $this->poll('claim-key-000000000002', version: '1.1.0-node')->assertOk()->assertJson(['type' => 'post', 'post_id' => $withUpload->id]);
    }

    public function test_runner_version_check(): void
    {
        $this->assertTrue(FacebookGroupPostScheduler::runnerSupportsUploads('1.1.0-node'));
        $this->assertTrue(FacebookGroupPostScheduler::runnerSupportsUploads('1.1.0'));
        $this->assertTrue(FacebookGroupPostScheduler::runnerSupportsUploads('1.10'));
        $this->assertTrue(FacebookGroupPostScheduler::runnerSupportsUploads('2.0.0'));
        $this->assertFalse(FacebookGroupPostScheduler::runnerSupportsUploads('1.0.0-node'));
        $this->assertFalse(FacebookGroupPostScheduler::runnerSupportsUploads('1.0'));
        $this->assertFalse(FacebookGroupPostScheduler::runnerSupportsUploads('node'));
        $this->assertFalse(FacebookGroupPostScheduler::runnerSupportsUploads(null));
    }

    public function test_runner_downloads_uploaded_images_with_token_only(): void
    {
        Storage::fake('local');
        $name = $this->uploadedImage();

        $this->get("/runner/fb/images/{$name}")->assertForbidden();
        $this->get("/runner/fb/images/{$name}", ['X-Runner-Token' => 'sai'])->assertForbidden();
        $response = $this->get("/runner/fb/images/{$name}", ['X-Runner-Token' => $this->token])->assertOk();
        $this->assertSame('jpeg-bytes', $response->baseResponse->getFile()->getContent());

        $this->get('/runner/fb/images/'.str_repeat('0', 32).'.jpg', ['X-Runner-Token' => $this->token])->assertNotFound();
        $this->get('/runner/fb/images/..%2F..%2F.env', ['X-Runner-Token' => $this->token])->assertNotFound();
    }

    // ── Luật nhịp đăng ───────────────────────────────────────────────────────

    public function test_only_posts_inside_window(): void
    {
        $this->fetchReturns();
        $this->queue();

        $this->travelTo(Carbon::parse('2026-10-03 07:59:00'));
        $this->poll()->assertJson(['type' => 'idle', 'reason' => 'outside_window']);

        $this->travelTo(Carbon::parse('2026-10-03 22:00:00'));
        $this->poll()->assertJson(['type' => 'idle', 'reason' => 'outside_window']);

        $this->travelTo(Carbon::parse('2026-10-03 21:59:00'));
        $this->poll()->assertJson(['type' => 'post']);
    }

    public function test_daily_cap_counts_maybe_published_posts_but_not_failed(): void
    {
        $this->fetchReturns();
        $done = collect(range(1, 10))->map(fn () => $this->queue(null, null, [
            'status' => FacebookGroupPost::POSTED,
            'claimed_at' => now()->subHour(),
        ]));
        $this->queue();

        $this->poll()->assertJson(['type' => 'idle', 'reason' => 'daily_cap']);

        $done->first()->update(['status' => FacebookGroupPost::FAILED]);
        $this->poll()->assertJson(['type' => 'post']);
    }

    public function test_waits_random_gap_after_a_post(): void
    {
        $this->fetchReturns();
        $first = $this->queue();
        $this->queue();

        $this->poll()->assertJson(['post_id' => $first->id]);
        $this->report($first->fresh(), 'posted')->assertOk();

        $next = app(FacebookGroupRunnerSettings::class)->nextAllowedAt();
        $this->assertTrue($next->between(now()->addMinutes(5), now()->addMinutes(10)));
        $this->assertNotNull($first->group->fresh()->last_posted_at);

        $this->poll('claim-key-000000000002')->assertJson(['type' => 'idle', 'reason' => 'gap']);

        $this->travel(11)->minutes();
        $this->poll('claim-key-000000000002')->assertJson(['type' => 'post']);
    }

    public function test_each_group_waits_cooldown_between_posts(): void
    {
        $this->fetchReturns();
        $group = $this->group(['last_attempt_at' => now()->subHours(23)]);
        $this->queue(null, $group);

        $this->poll()->assertJson(['type' => 'idle', 'reason' => 'empty']);

        $group->update(['last_attempt_at' => now()->subHours(25)]);
        $this->poll()->assertJson(['type' => 'post']);
    }

    public function test_skips_disabled_groups_and_paused_bot(): void
    {
        $this->fetchReturns();
        $this->queue(null, $this->group(['enabled' => false]));
        $this->poll()->assertJson(['type' => 'idle', 'reason' => 'empty']);

        $this->queue();
        app(FacebookGroupRunnerSettings::class)->pause('Admin tạm dừng');
        $this->poll()->assertJson(['type' => 'idle', 'reason' => 'paused']);
    }

    // ── Chống đăng trùng ────────────────────────────────────────────────────

    public function test_same_claim_key_gets_same_post_other_key_is_busy(): void
    {
        $this->fetchReturns();
        $post = $this->queue();
        $this->queue();

        $this->poll()->assertJson(['post_id' => $post->id]);
        $this->poll()->assertJson(['post_id' => $post->id]);
        $this->poll('claim-key-000000000099')->assertJson(['type' => 'idle', 'reason' => 'busy']);
    }

    public function test_stale_claim_turns_ambiguous_and_late_report_still_counts(): void
    {
        $this->fetchReturns();
        $post = $this->queue();
        $this->queue();

        $this->poll()->assertJson(['post_id' => $post->id]);
        $this->travel(16)->minutes();
        $this->poll('claim-key-000000000002')->assertJson(['type' => 'post']);

        $post->refresh();
        $this->assertSame(FacebookGroupPost::AMBIGUOUS, $post->status);
        $this->assertSame(FacebookGroupPost::AMBIGUOUS, $post->fresh()->status, 'không được tự đăng lại');

        $this->report($post, 'posted')->assertOk();
        $this->assertSame(FacebookGroupPost::POSTED, $post->fresh()->status);
        $this->assertNotNull($post->group->fresh()->last_posted_at);
    }

    public function test_report_with_wrong_key_is_rejected_and_repeat_is_idempotent(): void
    {
        $this->fetchReturns();
        $post = $this->queue();
        $this->poll();

        $this->report($post, 'posted', 'claim-key-somebody-else')->assertStatus(409);
        $this->report($post, 'posted')->assertOk();
        $this->report($post, 'posted')->assertOk();
        $this->report($post, 'failed')->assertStatus(409);

        $this->assertSame(FacebookGroupPost::POSTED, $post->fresh()->status);
    }

    // ── Kết quả xấu ─────────────────────────────────────────────────────────

    public function test_not_allowed_disables_group(): void
    {
        $this->fetchReturns();
        $post = $this->queue();
        $this->poll();

        $this->report($post, 'not_allowed', self::KEY, 'Chỉ quản trị viên được đăng')->assertOk();

        $group = $post->group->fresh();
        $this->assertFalse($group->enabled);
        $this->assertSame('Chỉ quản trị viên được đăng', $group->disabled_reason);
        $this->assertNull($group->last_attempt_at);
    }

    public function test_failed_before_submit_frees_group_for_retry(): void
    {
        $this->fetchReturns();
        $post = $this->queue();
        $this->poll();

        $this->report($post, 'failed', self::KEY, 'Không thấy ô soạn bài')->assertOk();

        $this->assertNull($post->group->fresh()->last_attempt_at);
        $this->assertSame(FacebookGroupPost::FAILED, $post->fresh()->status);
    }

    public function test_checkpoint_pauses_and_alerts_admin_once(): void
    {
        $notifier = Mockery::mock(ZaloAdminNotifier::class);
        $notifier->shouldReceive('notify')->once()->withArgs(fn (string $text) => str_contains($text, 'TẠM DỪNG'));
        $this->app->instance(ZaloAdminNotifier::class, $notifier);

        $this->fetchReturns();
        $post = $this->queue();
        $this->poll();

        $this->report($post, 'checkpoint', self::KEY, 'URL /checkpoint/')->assertOk();
        $this->poll('claim-key-000000000002', 'checkpoint')->assertJson(['type' => 'idle', 'reason' => 'paused']);

        $this->assertTrue(app(FacebookGroupRunnerSettings::class)->paused());
    }

    public function test_runner_reporting_blocked_state_pauses(): void
    {
        $this->poll(self::KEY, 'blocked')->assertJson(['type' => 'idle', 'reason' => 'paused']);

        $this->assertSame('Facebook báo tạm chặn đăng bài', app(FacebookGroupRunnerSettings::class)->pausedReason());
    }

    public function test_old_pending_posts_expire(): void
    {
        $post = $this->queue(null, null, ['queued_at' => now()->subHours(49)]);

        $this->poll()->assertJson(['type' => 'idle']);

        $this->assertSame(FacebookGroupPost::EXPIRED, $post->fresh()->status);
    }

    // ── Đồng bộ nhóm ────────────────────────────────────────────────────────

    public function test_sync_groups_round_trip(): void
    {
        $existing = $this->group(['fb_group_key' => 'sansale', 'enabled' => true]);
        app(FacebookGroupRunnerSettings::class)->requestSync();

        $this->poll()->assertJson(['type' => 'sync_groups']);

        $this->postJson('/runner/fb/groups', [
            'account' => 'Nick test',
            'groups' => [
                ['url' => 'https://www.facebook.com/groups/123456789012345/', 'name' => 'Nhóm test'],
                ['url' => 'https://www.facebook.com/groups/SanSale/?ref=bookmarks', 'name' => 'Săn sale'],
                ['url' => 'https://www.facebook.com/groups/joins/', 'name' => 'Nhóm của bạn'],
                ['url' => 'https://www.facebook.com/groups/123456789012345/posts/9', 'name' => null],
            ],
        ], ['X-Runner-Token' => $this->token])->assertOk()->assertJson(['created' => 1, 'updated' => 1]);

        $new = FacebookGroup::where('fb_group_key', '123456789012345')->firstOrFail();
        $this->assertFalse($new->enabled);
        $this->assertSame('Nhóm test', $new->name);
        $this->assertTrue($existing->fresh()->enabled);
        $this->assertSame(2, FacebookGroup::count());
        $this->assertFalse(app(FacebookGroupRunnerSettings::class)->syncRequested());

        $this->poll()->assertJson(['type' => 'idle']);
    }
}
