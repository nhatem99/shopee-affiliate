<?php

namespace Tests\Feature;

use App\Models\FacebookEngageComment;
use App\Models\FacebookGroup;
use App\Models\FacebookGroupDeal;
use App\Models\FacebookProfile;
use App\Services\FacebookEngageSettings;
use App\Services\FacebookGroupRunnerSettings;
use App\Services\ZaloAdminNotifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

/**
 * Bình luận dạo trong nhóm follow chéo: lúc rảnh bot bình luận một câu mẫu vào bài mới nhất chưa
 * bình luận, bằng page admin chọn, theo nhịp riêng.
 */
class FacebookEngageTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'claim-key-000000000001';

    private const BOT = '1.5.0-node';

    private string $token;

    private FacebookProfile $page;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));
        $this->token = app(FacebookGroupRunnerSettings::class)->regenerateToken();

        FacebookProfile::primary()->forceFill(['fb_id' => '100000000000001'])->save();
        $this->page = FacebookProfile::create([
            'fb_id' => '61550000000001',
            'name' => 'Shop Xinh',
            'url' => FacebookProfile::profileUrl('61550000000001'),
            'position' => 1,
        ]);

        $settings = app(FacebookEngageSettings::class);
        $settings->saveConfig(['enabled' => true, 'profile_id' => $this->page->id, 'gap_min' => 5, 'gap_max' => 5, 'group_gap_minutes' => 60]);
        $settings->saveTemplates("Follow chéo nha {ten}\n\n{Ghé|Thăm} rồi nè");
    }

    private function poll(string $version = self::BOT)
    {
        return $this->postJson('/runner/fb/poll', [
            'claim_key' => self::KEY,
            'state' => 'ok',
            'version' => $version,
        ], ['X-Runner-Token' => $this->token]);
    }

    private function report(int $id, string $status, array $extra = [])
    {
        return $this->postJson("/runner/fb/engage/{$id}/result", ['status' => $status] + $extra, ['X-Runner-Token' => $this->token]);
    }

    private function group(?FacebookProfile $profile = null, bool $engage = true): FacebookGroup
    {
        static $n = 0;
        $n++;

        $group = FacebookGroup::create([
            'fb_group_key' => "follow{$n}",
            'name' => "Follow chéo {$n}",
            'url' => FacebookGroup::urlFor("follow{$n}"),
            'enabled' => false,
            'engage_enabled' => $engage,
            'source' => 'sync',
        ]);
        $group->profiles()->attach($profile ?? $this->page);

        return $group;
    }

    public function test_idle_bot_gets_engage_job_with_rendered_comment_and_page(): void
    {
        $group = $this->group();

        $job = $this->poll()->assertOk()->json();

        $this->assertSame('engage', $job['type']);
        $this->assertSame($group->url, $job['group_url']);
        $this->assertSame($this->page->id, $job['profile']['id']);
        $this->assertContains($job['comment'], ['Follow chéo nha {ten}', 'Ghé rồi nè', 'Thăm rồi nè']);
        $this->assertSame([], $job['skip_posts']);
        $this->assertEqualsCanonicalizing(['100000000000001', '61550000000001'], $job['own_ids']);

        $row = FacebookEngageComment::findOrFail($job['engage_id']);
        $this->assertSame(FacebookEngageComment::CLAIMED, $row->status);
        $this->assertNotNull($group->fresh()->last_engaged_at);
    }

    public function test_old_bot_disabled_or_unconfigured_gets_no_engage_job(): void
    {
        $this->group();
        $settings = app(FacebookEngageSettings::class);

        $this->assertSame('idle', $this->poll('1.4.0-node')->json('type'));

        $settings->saveTemplates('');
        $this->assertSame('idle', $this->poll()->json('type'));
        $settings->saveTemplates('Follow chéo nha');

        $settings->saveConfig(['profile_id' => null]);
        $this->assertSame('idle', $this->poll()->json('type'));
        $settings->saveConfig(['profile_id' => $this->page->id]);

        $settings->setEnabled(false);
        $this->assertSame('idle', $this->poll()->json('type'));

        $this->page->forceFill(['blocked_at' => now()])->save();
        $settings->setEnabled(true);
        $this->assertSame('idle', $this->poll()->json('type'));

        $this->assertSame(0, FacebookEngageComment::count());
    }

    public function test_only_groups_the_page_joined_and_ticked_are_used(): void
    {
        $this->group(FacebookProfile::primary());
        $this->group(engage: false);

        $this->assertSame('idle', $this->poll()->json('type'));

        $mine = $this->group();
        $this->assertSame($mine->url, $this->poll()->json('group_url'));
    }

    public function test_waits_gap_between_comments_and_rotates_groups(): void
    {
        $first = $this->group();
        $second = $this->group();

        $this->assertSame($first->url, $this->poll()->json('group_url'));

        // Nghỉ 5 phút giữa hai bình luận.
        $this->travel(4)->minutes();
        $this->assertSame('idle', $this->poll()->json('type'));

        // Nhóm lâu chưa ghé đi trước.
        $this->travel(2)->minutes();
        $this->assertSame($second->url, $this->poll()->json('group_url'));

        // Hai nhóm đều vừa ghé — chờ đủ 60 phút từ lần ghé nhóm đầu.
        $this->travel(6)->minutes();
        $this->assertSame('idle', $this->poll()->json('type'));
        $this->travel(50)->minutes();
        $this->assertSame($first->url, $this->poll()->json('group_url'));
    }

    public function test_respects_window_and_daily_cap(): void
    {
        $this->group();
        $settings = app(FacebookEngageSettings::class);
        $settings->saveConfig(['window_start' => '10:00', 'window_end' => '22:00', 'max_per_day' => 1, 'group_gap_minutes' => 0, 'gap_min' => 1, 'gap_max' => 1]);

        $this->assertSame('idle', $this->poll()->json('type'));

        $this->travelTo(Carbon::parse('2026-10-15 10:30:00'));
        $this->assertSame('engage', $this->poll()->json('type'));

        $this->travel(5)->minutes();
        $this->assertSame('idle', $this->poll()->json('type'));

        $this->travelTo(Carbon::parse('2026-10-16 10:30:00'));
        $this->assertSame('engage', $this->poll()->json('type'));
    }

    public function test_deal_post_goes_before_engage(): void
    {
        $dealGroup = $this->group(FacebookProfile::primary(), engage: false);
        $dealGroup->forceFill(['enabled' => true])->save();
        $deal = FacebookGroupDeal::create(['shopee_url' => null, 'caption' => 'Bài tự soạn']);
        $deal->posts()->create(['facebook_group_id' => $dealGroup->id, 'status' => 'pending', 'queued_at' => now()]);
        $this->group();

        $this->assertSame('post', $this->poll()->json('type'));
    }

    public function test_engage_runs_outside_deal_window(): void
    {
        app(FacebookGroupRunnerSettings::class)->saveCadence(['window_start' => '20:00', 'window_end' => '22:00'] + app(FacebookGroupRunnerSettings::class)->cadence());
        $this->group();

        $this->assertSame('engage', $this->poll()->json('type'));
    }

    public function test_commented_post_is_skipped_next_time(): void
    {
        $this->group();
        $job = $this->poll()->json();

        $this->report($job['engage_id'], 'commented', [
            'comment' => 'Follow chéo nha Lan Anh',
            'post_url' => 'https://www.facebook.com/groups/follow1/posts/1234567890/',
            'author' => 'Lan Anh',
        ])->assertOk();

        $row = FacebookEngageComment::findOrFail($job['engage_id']);
        $this->assertSame(FacebookEngageComment::COMMENTED, $row->status);
        $this->assertSame('1234567890', $row->post_key);
        $this->assertSame('Follow chéo nha Lan Anh', $row->comment);
        $this->assertSame('Lan Anh', $row->author);

        $this->travel(61)->minutes();
        $this->assertSame(['1234567890'], $this->poll()->json('skip_posts'));

        // Báo lần hai — đã chốt.
        $this->report($job['engage_id'], 'commented')->assertStatus(409);
    }

    public function test_nothing_commented_shortens_wait(): void
    {
        $this->group();
        $this->group();
        $job = $this->poll()->json();

        $this->report($job['engage_id'], 'no_post')->assertOk();

        $this->travel(3)->minutes();
        $this->assertSame('engage', $this->poll()->json('type'));
        // no_post không tính vào trần mỗi ngày.
        $this->assertSame(1, FacebookEngageComment::whereIn('status', FacebookEngageComment::COUNTS_TOWARD_CAP)->count());
    }

    public function test_not_allowed_turns_group_off(): void
    {
        $group = $this->group();
        $job = $this->poll()->json();

        $this->report($job['engage_id'], 'not_allowed', ['error' => 'Page chưa là thành viên nhóm'])->assertOk();

        $group->refresh();
        $this->assertFalse($group->engage_enabled);
        $this->assertSame('Page chưa là thành viên nhóm', $group->engage_disabled_reason);
    }

    public function test_blocked_stops_engage_and_notifies_admin_once(): void
    {
        $notifier = Mockery::mock(ZaloAdminNotifier::class);
        $notifier->shouldReceive('notify')->once()->withArgs(fn (string $text) => str_contains($text, 'tạm chặn bình luận'));
        $this->app->instance(ZaloAdminNotifier::class, $notifier);
        $this->group();
        $job = $this->poll()->json();

        $this->report($job['engage_id'], 'blocked', ['error' => 'Bạn tạm thời bị chặn'])->assertOk();

        $settings = app(FacebookEngageSettings::class);
        $this->assertFalse($settings->enabled());
        $this->assertStringContainsString('tạm chặn bình luận', $settings->stoppedReason());
        // Page vẫn đăng deal được — Facebook chặn theo từng tính năng.
        $this->assertNull($this->page->fresh()->blocked_at);

        $this->travel(2)->hours();
        $this->assertSame('idle', $this->poll()->json('type'));
    }

    public function test_silent_claim_becomes_unknown_and_late_report_is_accepted(): void
    {
        $this->group();
        $job = $this->poll()->json();

        $this->travel(16)->minutes();
        $this->poll();
        $this->assertSame(FacebookEngageComment::UNKNOWN, FacebookEngageComment::findOrFail($job['engage_id'])->status);

        $this->report($job['engage_id'], 'commented', ['post_url' => 'https://www.facebook.com/groups/follow1/posts/55/'])->assertOk();
        $this->assertSame(FacebookEngageComment::COMMENTED, FacebookEngageComment::findOrFail($job['engage_id'])->status);
    }

    public function test_report_requires_token_and_valid_status(): void
    {
        $this->group();
        $job = $this->poll()->json();

        $this->postJson("/runner/fb/engage/{$job['engage_id']}/result", ['status' => 'commented'])->assertForbidden();
        $this->report($job['engage_id'], 'dry_run')->assertUnprocessable();
    }

    public function test_admin_page_shows_status_and_saves_settings(): void
    {
        $group = $this->group();
        $other = $this->group(FacebookProfile::primary(), engage: false);
        app(FacebookGroupRunnerSettings::class)->recordRunner(['state' => 'ok', 'version' => self::BOT]);

        $admin = $this->actingAs($this->createAdmin());
        $admin->get('/admin/fb-engage')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/FacebookEngage')
            ->where('config.enabled', true)
            ->where('blocking', null)
            ->where('runner.supported', true)
            ->where('groups.0.id', $group->id)
            ->where('groups.0.in_profile', true)
            ->where('groups.1.in_profile', false));

        $admin->post('/admin/fb-engage/settings', [
            'profile_id' => $this->page->id,
            'max_per_day' => 20,
            'gap_min' => 3,
            'gap_max' => 8,
            'group_gap_minutes' => 30,
            'window_start' => '07:00',
            'window_end' => '23:00',
            'templates' => "Câu một\r\nCâu hai\r\n",
        ])->assertRedirect()->assertSessionHasNoErrors();

        $settings = app(FacebookEngageSettings::class);
        $this->assertSame(20, $settings->config()['max_per_day']);
        $this->assertSame(['Câu một', 'Câu hai'], $settings->templates());

        $admin->post('/admin/fb-engage/settings', [
            'profile_id' => $this->page->id, 'max_per_day' => 20, 'gap_min' => 9, 'gap_max' => 8,
            'group_gap_minutes' => 30, 'window_start' => '07:00', 'window_end' => '23:00',
        ])->assertSessionHasErrors('gap_max');

        $admin->post('/admin/fb-engage/groups', ['ids' => [$other->id], 'engage_enabled' => true])->assertRedirect();
        $this->assertTrue($other->fresh()->engage_enabled);

        $admin->post('/admin/fb-engage/toggle', ['enabled' => false])->assertRedirect();
        $this->assertFalse($settings->enabled());
    }

    public function test_non_admin_cannot_open_page(): void
    {
        $this->get('/admin/fb-engage')->assertRedirect();
    }
}
