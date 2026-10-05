<?php

namespace Tests\Feature;

use App\Models\FacebookGroup;
use App\Models\FacebookGroupDeal;
use App\Models\FacebookGroupPost;
use App\Models\FacebookProfile;
use App\Services\FacebookGroupRunnerSettings;
use App\Services\ZaloAdminNotifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Đăng nhóm bằng nhiều page: nick chính + các Trang, lần lượt theo thứ tự, mỗi page một trần bài/ngày.
 */
class FacebookProfileRotationTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_BOT = '1.3.0-node';

    private const OLD_BOT = '1.2.0-node';

    private const ACCOUNT = '100001111111111';

    private string $token;

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00'));
        $this->token = app(FacebookGroupRunnerSettings::class)->regenerateToken();
    }

    private function key(): string
    {
        return 'claim-key-'.str_pad((string) ++$this->keys, 12, '0', STR_PAD_LEFT);
    }

    private function poll(string $version = self::NEW_BOT, array $extra = [], ?string $key = null)
    {
        return $this->postJson('/runner/fb/poll', array_merge([
            'claim_key' => $key ?? $this->key(),
            'state' => 'ok',
            'version' => $version,
            'account_id' => self::ACCOUNT,
            'actor_id' => self::ACCOUNT,
        ], $extra), ['X-Runner-Token' => $this->token]);
    }

    private function report(array $job, string $status, ?string $error = null, ?string $actorId = null)
    {
        return $this->postJson("/runner/fb/posts/{$job['post_id']}/result", [
            'claim_key' => $job['claim_key'],
            'status' => $status,
            'error' => $error,
            'actor_id' => $actorId,
        ], ['X-Runner-Token' => $this->token]);
    }

    /** Một bài trọn vòng: nhận → báo đã đăng → bỏ khoảng nghỉ. Trả lượt nhận bài. */
    private function postOne(string $version = self::NEW_BOT): array
    {
        $job = $this->poll($version)->assertJson(['type' => 'post'])->json();
        $this->report($job, FacebookGroupPost::POSTED)->assertOk();
        app(FacebookGroupRunnerSettings::class)->setNextAllowedAt(null);

        return $job;
    }

    private function page(array $attributes = []): FacebookProfile
    {
        static $n = 0;
        $n++;

        return FacebookProfile::create(array_merge([
            'fb_id' => (string) (61550000000000 + $n),
            'name' => "Page {$n}",
            'url' => FacebookProfile::profileUrl((string) (61550000000000 + $n)),
            'position' => $n,
            'max_per_day' => 20,
        ], $attributes));
    }

    /** @param  list<FacebookProfile>  $profiles */
    private function group(array $profiles): FacebookGroup
    {
        static $n = 0;
        $n++;

        $group = FacebookGroup::create([
            'fb_group_key' => "nhom{$n}",
            'name' => "Nhóm {$n}",
            'url' => FacebookGroup::urlFor("nhom{$n}"),
            'enabled' => true,
            'source' => 'sync',
        ]);
        $group->profiles()->attach(collect($profiles)->pluck('id'));

        return $group;
    }

    private function queue(FacebookGroup $group): FacebookGroupPost
    {
        $deal = FacebookGroupDeal::create(['shopee_url' => null, 'caption' => 'Bài tự soạn số '.$group->id]);

        return $deal->posts()->create(['facebook_group_id' => $group->id, 'status' => FacebookGroupPost::PENDING, 'queued_at' => now()]);
    }

    private function primary(array $attributes = []): FacebookProfile
    {
        return tap(FacebookProfile::primary())->update($attributes);
    }

    // ── Lần lượt theo page ──────────────────────────────────────────────────

    public function test_first_profile_posts_up_to_its_cap_then_the_next_one_takes_over(): void
    {
        $primary = $this->primary(['max_per_day' => 2]);
        $page = $this->page();
        foreach (range(1, 3) as $i) {
            $this->queue($this->group([$primary, $page]));
        }

        $this->assertSame($primary->id, $this->postOne()['profile']['id']);
        $this->assertTrue($this->postOne()['profile']['primary']);

        $third = $this->postOne();
        $this->assertSame($page->id, $third['profile']['id']);
        $this->assertSame($page->url, $third['profile']['url']);
        $this->assertSame([$primary->id => 2, $page->id => 1], FacebookGroupPost::whereNotNull('facebook_profile_id')->get()->countBy('facebook_profile_id')->all());
    }

    public function test_profile_with_quota_but_no_post_in_its_groups_lets_the_next_one_post(): void
    {
        $primary = $this->primary();
        $page = $this->page();
        $post = $this->queue($this->group([$page]));

        $job = $this->poll()->assertJson(['type' => 'post', 'post_id' => $post->id])->json();

        $this->assertSame($page->id, $job['profile']['id']);
        $this->assertSame($page->id, $post->fresh()->facebook_profile_id);
        $this->assertNotSame($primary->id, $job['profile']['id']);
    }

    public function test_old_bot_only_posts_as_primary(): void
    {
        $primary = $this->primary(['max_per_day' => 1]);
        $page = $this->page();
        $this->queue($this->group([$primary, $page]));
        $onlyPage = $this->queue($this->group([$page]));

        $this->postOne(self::OLD_BOT);

        $this->poll(self::OLD_BOT)->assertJson(['type' => 'idle', 'reason' => 'daily_cap']);
        $this->assertSame(FacebookGroupPost::PENDING, $onlyPage->fresh()->status);
    }

    public function test_disabled_or_all_blocked_profiles_keep_the_bot_idle(): void
    {
        $primary = $this->primary();
        $this->queue($this->group([$primary]));

        $primary->update(['blocked_at' => now()]);
        $this->poll()->assertJson(['type' => 'idle', 'reason' => 'all_blocked']);

        $primary->update(['enabled' => false]);
        $this->poll()->assertJson(['type' => 'idle', 'reason' => 'no_profile']);
    }

    // ── Page bị chặn / chuyển page lỗi ──────────────────────────────────────

    public function test_blocked_profile_rests_alone_and_the_next_profile_continues(): void
    {
        $notifier = Mockery::mock(ZaloAdminNotifier::class);
        $notifier->shouldReceive('notify')->once()->withArgs(fn (string $text) => str_contains($text, 'Nick chính') && str_contains($text, 'Bot chuyển sang Page'));
        $this->app->instance(ZaloAdminNotifier::class, $notifier);

        $primary = $this->primary();
        $page = $this->page();
        $first = $this->queue($this->group([$primary, $page]));
        $second = $this->queue($this->group([$primary, $page]));

        $job = $this->poll()->json();
        $this->report($job, FacebookGroupPost::BLOCKED, 'Facebook báo: Bạn tạm thời bị chặn')->assertOk();

        $this->assertSame(FacebookGroupPost::BLOCKED, $first->fresh()->status);
        $this->assertNotNull($primary->fresh()->blocked_at);
        $this->assertStringContainsString('tạm thời bị chặn', $primary->fresh()->blocked_reason);
        $this->assertFalse(app(FacebookGroupRunnerSettings::class)->paused());

        // Nghỉ như sau một bài thường rồi mới tới page kế tiếp.
        $this->poll()->assertJson(['type' => 'idle', 'reason' => 'gap']);
        app(FacebookGroupRunnerSettings::class)->setNextAllowedAt(null);

        $next = $this->poll()->assertJson(['type' => 'post', 'post_id' => $second->id])->json();
        $this->assertSame($page->id, $next['profile']['id']);
    }

    public function test_unblocking_puts_the_profile_back_first_in_line(): void
    {
        $primary = $this->primary(['blocked_at' => now(), 'blocked_reason' => 'Facebook báo tạm chặn đăng bài']);
        $this->page();
        $this->queue($this->group([$primary]));

        $this->actingAs($this->createAdmin())->post("/admin/fb-groups/profiles/{$primary->id}/unblock")->assertRedirect();

        $this->assertNull($primary->fresh()->blocked_at);
        $this->assertSame($primary->id, $this->poll()->json('profile.id'));
    }

    public function test_switch_failure_requeues_the_post_for_another_profile(): void
    {
        $primary = $this->primary();
        $page = $this->page(['position' => -1]);
        $post = $this->queue($this->group([$primary, $page]));

        $job = $this->poll()->json();
        $this->assertSame($page->id, $job['profile']['id']);
        $this->report($job, FacebookGroupPost::SWITCH_FAILED, 'Không thấy nút "Chuyển ngay"')->assertOk();

        $fresh = $post->fresh();
        $this->assertSame(FacebookGroupPost::PENDING, $fresh->status);
        $this->assertNull($fresh->facebook_profile_id);
        $this->assertNull($fresh->caption);
        $this->assertStringContainsString('Page', $fresh->error);
        $this->assertStringContainsString('Chuyển ngay', $page->fresh()->blocked_reason);
        $this->assertNull($fresh->group->last_attempt_at);

        app(FacebookGroupRunnerSettings::class)->setNextAllowedAt(null);
        $this->assertSame($primary->id, $this->poll()->assertJson(['post_id' => $post->id])->json('profile.id'));
    }

    public function test_not_allowed_hands_the_group_to_another_profile(): void
    {
        $primary = $this->primary();
        $page = $this->page(['position' => -1]);
        $group = $this->group([$primary, $page]);
        $post = $this->queue($group);

        $job = $this->poll()->json();
        $this->report($job, FacebookGroupPost::NOT_ALLOWED, 'Nick chưa là thành viên nhóm')->assertOk();

        $this->assertTrue($group->fresh()->enabled);
        $this->assertSame([$primary->id], $group->profiles()->pluck('facebook_profiles.id')->all());
        $this->assertSame(FacebookGroupPost::PENDING, $post->fresh()->status);

        app(FacebookGroupRunnerSettings::class)->setNextAllowedAt(null);
        $this->assertSame($primary->id, $this->poll()->json('profile.id'));
    }

    public function test_not_allowed_for_the_last_profile_disables_the_group_and_keeps_membership(): void
    {
        $primary = $this->primary();
        $group = $this->group([$primary]);
        $this->queue($group);

        $this->report($this->poll()->json(), FacebookGroupPost::NOT_ALLOWED, 'Nhóm chỉ cho quản trị viên đăng bài')->assertOk();

        $this->assertFalse($group->fresh()->enabled);
        $this->assertSame(1, $group->profiles()->count());
    }

    public function test_blocked_state_while_reviewing_blocks_the_open_page(): void
    {
        $page = $this->page();

        $this->poll(self::NEW_BOT, ['state' => 'blocked', 'actor_id' => $page->fb_id])->assertOk();

        $this->assertNotNull($page->fresh()->blocked_at);
        $this->assertNull($this->primary()->blocked_at);

        // Page lạ (chưa thêm ở admin): không biết cho ai nghỉ — dừng cả bot.
        $this->poll(self::NEW_BOT, ['state' => 'blocked', 'actor_id' => '61559999999999'])->assertJson(['reason' => 'paused']);
    }

    // ── uid ─────────────────────────────────────────────────────────────────

    public function test_bot_teaches_server_the_uids(): void
    {
        $vanity = $this->page(['fb_id' => null, 'url' => 'https://www.facebook.com/tietkiemvi.deal', 'position' => -1]);
        $this->queue($this->group([$vanity]));

        $job = $this->poll()->json();
        $this->assertSame(self::ACCOUNT, $this->primary()->fb_id);
        $this->assertNull($job['profile']['fb_id']);

        $this->report($job, FacebookGroupPost::POSTED, null, '61551234567890')->assertOk();
        $this->assertSame('61551234567890', $vanity->fresh()->fb_id);
    }

    // ── Lấy nhóm ────────────────────────────────────────────────────────────

    public function test_sync_lists_profiles_and_attaches_groups_per_profile(): void
    {
        $page = $this->page();
        $this->page(['enabled' => false]);
        app(FacebookGroupRunnerSettings::class)->requestSync();

        $job = $this->poll()->assertJson(['type' => 'sync_groups'])->json();
        $this->assertSame([$this->primary()->id, $page->id], array_column($job['profiles'], 'id'));
        // Giao một lần — bot lấy hỏng thì admin bấm lại, không giao lại mỗi lượt hỏi.
        $this->assertFalse(app(FacebookGroupRunnerSettings::class)->syncRequested());
        $this->poll()->assertJson(['type' => 'idle']);

        app(FacebookGroupRunnerSettings::class)->requestSync();
        $this->assertArrayNotHasKey('profiles', $this->poll(self::OLD_BOT)->assertJson(['type' => 'sync_groups'])->json());

        $this->postJson('/runner/fb/groups', [
            'profile_id' => $page->id,
            'groups' => [['url' => 'https://www.facebook.com/groups/sansale/', 'name' => 'Săn sale']],
        ], ['X-Runner-Token' => $this->token])->assertOk();
        // Bot cũ (không profile_id, không uid) — nhóm của nick chính.
        $this->postJson('/runner/fb/groups', [
            'groups' => [['url' => 'https://www.facebook.com/groups/sansale/'], ['url' => 'https://www.facebook.com/groups/deal/']],
        ], ['X-Runner-Token' => $this->token])->assertOk()->assertJson(['created' => 1, 'updated' => 1]);

        $this->assertEqualsCanonicalizing(['sansale'], $page->groups()->pluck('fb_group_key')->all());
        $this->assertEqualsCanonicalizing(['sansale', 'deal'], $this->primary()->groups()->pluck('fb_group_key')->all());
        $this->assertNotNull($page->fresh()->last_synced_at);
    }

    public function test_manual_sync_from_an_unknown_page_is_rejected(): void
    {
        $this->postJson('/runner/fb/groups', [
            'account_id' => self::ACCOUNT,
            'actor_id' => '61559999999999',
            'groups' => [['url' => 'https://www.facebook.com/groups/sansale/']],
        ], ['X-Runner-Token' => $this->token])->assertUnprocessable();

        $this->assertSame(0, FacebookGroup::count());
    }

    // ── Kiểm tra duyệt bài ──────────────────────────────────────────────────

    public function test_review_is_done_as_the_profile_that_posted(): void
    {
        $page = $this->page();
        $group = $this->group([$this->primary(), $page]);
        $mine = $this->queue($group);
        $mine->forceFill([
            'status' => FacebookGroupPost::POSTED, 'facebook_profile_id' => $page->id, 'caption' => 'Bài của page về áo thun giá rẻ',
            'claimed_at' => now()->subHours(2), 'finished_at' => now()->subHours(2),
        ])->save();
        $theirs = FacebookGroupPost::create([
            'facebook_group_deal_id' => FacebookGroupDeal::create(['caption' => 'Bài của nick chính về giày'])->id,
            'facebook_group_id' => $group->id, 'facebook_profile_id' => $this->primary()->id, 'status' => FacebookGroupPost::POSTED,
            'caption' => 'Bài của nick chính về giày thể thao', 'claimed_at' => now()->subHours(2), 'finished_at' => now()->subHours(2),
        ]);
        $this->primary(['enabled' => false]);
        $page->update(['enabled' => false]);

        $job = $this->poll()->assertJson(['type' => 'review_group'])->json();
        $this->assertSame($page->id, $job['profile']['id']);

        $empty = ['ok' => true, 'items' => []];
        $this->postJson("/runner/fb/groups/{$group->id}/review", [
            'profile_id' => $page->id,
            'tabs' => ['pending' => $empty, 'published' => ['ok' => true, 'items' => [['text' => 'Bài của page về áo thun giá rẻ']]], 'declined' => $empty, 'removed' => $empty],
        ], ['X-Runner-Token' => $this->token])->assertOk()->assertJson(['checked' => 1, 'found' => 1]);

        $this->assertSame(FacebookGroupPost::REVIEW_PUBLISHED, $mine->fresh()->review_state);
        $this->assertNull($theirs->fresh()->review_state);
    }

    // ── Admin ───────────────────────────────────────────────────────────────

    public function test_admin_adds_a_page_and_bot_is_asked_to_fetch_its_groups(): void
    {
        $admin = $this->actingAs($this->createAdmin());

        $admin->post('/admin/fb-groups/profiles', ['url' => 'https://m.facebook.com/profile.php?id=61551112223334&ref=x', 'name' => 'Tiết kiệm ví', 'max_per_day' => 20])
            ->assertSessionHasNoErrors();
        $admin->post('/admin/fb-groups/profiles', ['url' => '61551112223334', 'max_per_day' => 20])
            ->assertSessionHasErrors('profile_url');
        $admin->post('/admin/fb-groups/profiles', ['url' => 'https://www.facebook.com/groups/abc', 'max_per_day' => 20])
            ->assertSessionHasErrors('profile_url');

        $page = FacebookProfile::where('fb_id', '61551112223334')->firstOrFail();
        $this->assertSame('https://www.facebook.com/profile.php?id=61551112223334', $page->url);
        $this->assertSame(1, $page->position);
        $this->assertTrue(app(FacebookGroupRunnerSettings::class)->syncRequested());

        $admin->get('/admin/fb-groups')->assertInertia(fn ($inertia) => $inertia
            ->where('profiles.0.is_primary', true)
            ->where('profiles.1.label', 'Tiết kiệm ví')
            ->where('profiles.1.groups_count', 0));
    }

    public function test_admin_reorders_edits_and_cannot_delete_primary(): void
    {
        $admin = $this->actingAs($this->createAdmin());
        $primary = $this->primary();
        $page = $this->page();

        $admin->post("/admin/fb-groups/profiles/{$page->id}/move", ['direction' => 'up'])->assertRedirect();
        $this->assertSame([$page->id, $primary->id], FacebookProfile::ordered()->pluck('id')->all());

        $admin->patch("/admin/fb-groups/profiles/{$page->id}", ['max_per_day' => 15, 'enabled' => false])->assertRedirect();
        $this->assertSame(15, $page->fresh()->max_per_day);
        $this->assertFalse($page->fresh()->enabled);

        $admin->delete("/admin/fb-groups/profiles/{$primary->id}")->assertSessionHasErrors('profile_url');
        $admin->delete("/admin/fb-groups/profiles/{$page->id}")->assertSessionHasNoErrors();
        $this->assertSame(1, FacebookProfile::count());
    }

    public function test_group_without_any_enabled_profile_cannot_be_picked(): void
    {
        $page = $this->page(['enabled' => false]);
        $this->group([$page]);

        $this->actingAs($this->createAdmin())->get('/admin/fb-posts')->assertInertia(fn ($inertia) => $inertia
            ->where('groups.0.ready', false)
            ->where('groups.0.no_profile', true));
    }

    public function test_parse_page_links(): void
    {
        $this->assertSame(['url' => 'https://www.facebook.com/profile.php?id=61551112223334', 'fb_id' => '61551112223334'], FacebookProfile::parseUrl('facebook.com/profile.php?id=61551112223334'));
        $this->assertSame(['url' => 'https://www.facebook.com/profile.php?id=61551112223334', 'fb_id' => '61551112223334'], FacebookProfile::parseUrl('https://www.facebook.com/people/Tiet-Kiem-Vi/61551112223334/'));
        $this->assertSame(['url' => 'https://www.facebook.com/tietkiemvi.deal', 'fb_id' => null], FacebookProfile::parseUrl('https://web.facebook.com/tietkiemvi.deal/?ref=page'));
        $this->assertNull(FacebookProfile::parseUrl('https://www.facebook.com/groups/12345'));
        $this->assertNull(FacebookProfile::parseUrl('https://evil.com/profile.php?id=61551112223334'));
        $this->assertNull(FacebookProfile::parseUrl('https://www.facebook.com/profile.php?id=abc'));
    }
}
