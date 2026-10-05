<?php

namespace Tests\Feature;

use App\Models\FacebookGroup;
use App\Models\FacebookGroupDeal;
use App\Models\FacebookGroupPost;
use App\Services\FacebookGroupReviewChecker;
use App\Services\FacebookGroupRunnerSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FacebookGroupReviewTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'claim-key-000000000001';

    private const NEW_BOT = '1.2.0-node';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-10-03 09:00:00'));
        $this->token = app(FacebookGroupRunnerSettings::class)->regenerateToken();
    }

    private function poll(string $version = self::NEW_BOT)
    {
        return $this->postJson('/runner/fb/poll', [
            'claim_key' => self::KEY,
            'state' => 'ok',
            'version' => $version,
        ], ['X-Runner-Token' => $this->token]);
    }

    private function review(FacebookGroup $group, array $tabs)
    {
        return $this->postJson("/runner/fb/groups/{$group->id}/review", ['tabs' => $tabs], ['X-Runner-Token' => $this->token]);
    }

    /** Lượt kiểm tra kế tiếp không phải chờ khoảng nghỉ giữa hai lượt. */
    private function skipReviewGap(): void
    {
        app(FacebookGroupRunnerSettings::class)->setNextReviewAt(null);
    }

    private function group(array $attributes = []): FacebookGroup
    {
        static $n = 0;
        $n++;

        return FacebookGroup::create(array_merge([
            'fb_group_key' => "nhom{$n}",
            'name' => "Nhóm {$n}",
            'url' => FacebookGroup::urlFor("nhom{$n}"),
            'enabled' => true,
            'source' => 'sync',
        ], $attributes));
    }

    private function posted(FacebookGroup $group, string $caption, int $minutesAgo = 90, array $attributes = []): FacebookGroupPost
    {
        $deal = FacebookGroupDeal::create(['shopee_url' => null, 'caption' => $caption]);

        return FacebookGroupPost::create(array_merge([
            'facebook_group_deal_id' => $deal->id,
            'facebook_group_id' => $group->id,
            'status' => FacebookGroupPost::POSTED,
            'queued_at' => now()->subMinutes($minutesAgo + 5),
            'claimed_at' => now()->subMinutes($minutesAgo + 1),
            'finished_at' => now()->subMinutes($minutesAgo),
            'caption' => $caption,
        ], $attributes));
    }

    private static function tab(array $texts = [], bool $ok = true, ?string $url = null): array
    {
        return ['ok' => $ok, 'items' => array_map(fn ($text) => ['text' => $text, 'url' => $url], $texts)];
    }

    private static function failed(): array
    {
        return ['ok' => false, 'error' => 'Facebook chuyển sang trang khác', 'items' => []];
    }

    public function test_review_job_goes_only_to_new_bots_an_hour_after_posting(): void
    {
        $group = $this->group();
        $this->posted($group, 'Deal hời: Áo thun cotton giảm 50% chỉ hôm nay thôi cả nhà ơi', minutesAgo: 30);

        $this->poll()->assertJson(['type' => 'idle', 'reason' => 'empty']);

        $this->travel(31)->minutes();
        $this->poll('1.1.0-node')->assertJson(['type' => 'idle']);
        $this->poll()->assertOk()->assertJson([
            'type' => 'review_group',
            'group_id' => $group->id,
            'group_url' => $group->url,
            'tabs' => [
                'pending' => $group->url.'my_pending_content/',
                'published' => $group->url.'my_posted_content/',
                'declined' => $group->url.'my_declined_content/',
                'removed' => $group->url.'my_removed_content/',
            ],
        ]);
        $this->assertNotNull($group->fresh()->last_checked_at);

        // Không giao liền lượt nữa — các lượt kiểm tra cách nhau vài phút, mỗi nhóm vài giờ.
        $this->poll()->assertJson(['type' => 'idle']);
        $this->skipReviewGap();
        $this->poll()->assertJson(['type' => 'idle']);
    }

    public function test_report_places_each_post_by_the_tab_it_shows_up_in(): void
    {
        $group = $this->group();
        $waiting = $this->posted($group, "🔥 {Deal hời} Nồi chiên không dầu 5L giảm sâu còn 590k\n\nhttps://tietkiemvi.com/go/aaa");
        $live = $this->posted($group, "⚡ Tai nghe bluetooth chống ồn chính hãng chỉ 199k hôm nay\nhttps://tietkiemvi.com/go/bbb");
        $gone = $this->posted($group, 'Mẹo săn mã freeship Shopee ngày đôi mà ít ai biết, lưu lại nhé');

        $this->review($group, [
            // Facebook bỏ emoji, gộp dấu cách, cắt bớt kèm "Xem thêm".
            'pending' => self::tab(["Nick Test\n· 2 giờ\nDeal hời Nồi chiên không dầu  5L giảm sâu còn 590k… Xem thêm"]),
            'published' => self::tab(['Tai nghe bluetooth chống ồn chính hãng chỉ 199k hôm nay https://tietkiemvi.com/go/bbb'], url: 'https://www.facebook.com/groups/nhom1/posts/123456789/'),
            'declined' => self::tab(),
            'removed' => self::failed(),
        ])->assertOk()->assertJson(['checked' => 3, 'found' => 2]);

        $this->assertSame('pending', $waiting->fresh()->review_state);
        $this->assertNull($waiting->fresh()->post_url);
        $this->assertSame('published', $live->fresh()->review_state);
        $this->assertSame('https://www.facebook.com/groups/nhom1/posts/123456789/', $live->fresh()->post_url);
        $this->assertSame('missing', $gone->fresh()->review_state);
        $this->assertNotNull($gone->fresh()->reviewed_at);
        // status giữ nguyên điều bot thấy lúc bấm Đăng.
        $this->assertSame(FacebookGroupPost::POSTED, $waiting->fresh()->status);
    }

    public function test_cannot_say_missing_unless_both_pending_and_published_tabs_opened(): void
    {
        $group = $this->group();
        $post = $this->posted($group, 'Bài này không có trong tab nào mà mình mở được hết cả');

        $this->review($group, [
            'pending' => self::tab(),
            'published' => self::failed(),
            'declined' => self::failed(),
            'removed' => self::failed(),
        ])->assertOk();

        $post->refresh();
        $this->assertNull($post->review_state);
        // Vẫn ghi lần kiểm tra để không giao lại liền — Facebook đổi đường dẫn thì cũng không lặp mãi.
        $this->assertNotNull($post->reviewed_at);
    }

    public function test_declined_or_removed_posts_are_settled(): void
    {
        $group = $this->group();
        $post = $this->posted($group, 'Bài bị admin nhóm từ chối vì có link mua hàng Shopee nhé');

        $this->review($group, [
            'pending' => self::tab(),
            'published' => self::tab(),
            'declined' => self::tab(['Bài bị admin nhóm từ chối vì có link mua hàng Shopee nhé']),
            'removed' => self::tab(),
        ])->assertOk();
        $this->assertSame('declined', $post->fresh()->review_state);

        $this->travel(7)->hours();
        $this->skipReviewGap();
        $this->assertNull(app(FacebookGroupReviewChecker::class)->nextJob(self::NEW_BOT));
    }

    public function test_published_post_is_checked_again_once_after_a_day(): void
    {
        $group = $this->group();
        $post = $this->posted($group, 'Bài đã lên nhóm, sau một ngày xem có bị gỡ không');
        $post->forceFill(['review_state' => 'published', 'reviewed_at' => now()])->save();
        $checker = app(FacebookGroupReviewChecker::class);

        $this->travel(7)->hours();
        $this->skipReviewGap();
        $this->assertNull($checker->nextJob(self::NEW_BOT));

        $this->travel(17)->hours();
        $this->skipReviewGap();
        $this->assertSame($group->id, $checker->nextJob(self::NEW_BOT)['group_id']);

        $post->forceFill(['reviewed_at' => now()])->save();
        $this->travel(4)->hours();
        $this->skipReviewGap();
        $this->assertNull($checker->nextJob(self::NEW_BOT));
    }

    public function test_ambiguous_post_seen_on_the_group_is_settled_as_posted(): void
    {
        $group = $this->group(['last_posted_at' => now()->subDays(2)]);
        $post = $this->posted($group, 'Bot không báo kết quả bài này nhưng thật ra đã lên nhóm rồi', attributes: [
            'status' => FacebookGroupPost::AMBIGUOUS,
            'error' => 'Bot không báo kết quả sau 15 phút',
        ]);

        $this->review($group, [
            'pending' => self::tab(),
            'published' => self::tab(['Bot không báo kết quả bài này nhưng thật ra đã lên nhóm rồi']),
            'declined' => self::tab(),
            'removed' => self::tab(),
        ])->assertOk();

        $post->refresh();
        $this->assertSame(FacebookGroupPost::POSTED, $post->status);
        $this->assertNull($post->error);
        $this->assertTrue($group->fresh()->last_posted_at->eq($post->finished_at));
    }

    public function test_only_facebook_group_post_links_are_kept_and_unknown_tabs_rejected(): void
    {
        $group = $this->group();
        $post = $this->posted($group, 'Link bài phải là link bài viết trong nhóm trên facebook.com');

        $this->review($group, [
            'pending' => self::tab(),
            'published' => self::tab(['Link bài phải là link bài viết trong nhóm trên facebook.com'], url: 'https://evil.example/groups/x/posts/1'),
        ])->assertOk();
        $this->assertSame('published', $post->fresh()->review_state);
        $this->assertNull($post->fresh()->post_url);

        // Đúng dạng bot gửi: thẻ bài có link + khối chữ cả trang (page) không link.
        $other = $this->posted($group, 'Bài chỉ thấy trong khối chữ của cả trang, không có thẻ riêng');
        $this->review($group, [
            'pending' => ['ok' => true, 'error' => null, 'items' => [
                ['text' => 'Bài khác', 'url' => 'https://www.facebook.com/groups/nhom1/posts/1/'],
                ['text' => "Nội dung của bạn\nBài chỉ thấy trong khối chữ của cả trang, không có thẻ riêng", 'url' => null, 'page' => true],
            ]],
            'published' => self::tab(),
        ])->assertOk();
        $this->assertSame('pending', $other->fresh()->review_state);
        $this->assertNull($other->fresh()->post_url);

        $this->review($group, ['spam' => self::tab()])->assertStatus(422);
        $this->postJson("/runner/fb/groups/{$group->id}/review", ['tabs' => ['pending' => self::tab()]])->assertForbidden();
    }

    public function test_posting_comes_first_and_no_review_outside_the_window_or_while_paused(): void
    {
        $group = $this->group();
        $this->posted($group, 'Bài cũ đang chờ bot kiểm tra xem đã được duyệt hay chưa');
        $deal = FacebookGroupDeal::create(['shopee_url' => null, 'caption' => 'Bài mới tự soạn']);
        $deal->posts()->create(['facebook_group_id' => $this->group()->id, 'status' => 'pending', 'queued_at' => now()]);

        $this->poll()->assertJson(['type' => 'post']);

        $this->travelTo(Carbon::parse('2026-10-03 22:30:00'));
        $this->postJson("/runner/fb/posts/{$deal->posts()->first()->id}/result", ['claim_key' => self::KEY, 'status' => 'posted'], ['X-Runner-Token' => $this->token]);
        $this->poll()->assertJson(['type' => 'idle', 'reason' => 'outside_window']);

        $this->travelTo(Carbon::parse('2026-10-04 09:00:00'));
        app(FacebookGroupRunnerSettings::class)->pause('Admin tạm dừng');
        $this->poll()->assertJson(['type' => 'idle', 'reason' => 'paused']);
        app(FacebookGroupRunnerSettings::class)->resume();
        $this->poll()->assertJson(['type' => 'review_group']);
    }

    public function test_admin_can_ask_for_a_review_right_away_and_sees_results(): void
    {
        $group = $this->group(['name' => 'Săn sale']);
        $post = $this->posted($group, 'Bài vừa đăng năm phút trước, admin muốn biết ngay đã lên chưa', minutesAgo: 5);
        app(FacebookGroupRunnerSettings::class)->recordRunner(['version' => '1.1.0-node']);

        $admin = $this->createAdmin();

        $this->actingAs($admin)->get('/admin/fb-groups')->assertInertia(fn (Assert $page) => $page
            ->where('review.supported', false)
            ->where('review.min_version', '1.2.0'));

        $this->poll()->assertJson(['type' => 'idle']);
        $this->actingAs($admin)->post('/admin/fb-groups/review')->assertSessionHasNoErrors();
        $this->poll()->assertJson(['type' => 'review_group', 'group_id' => $group->id]);

        $this->review($group, [
            'pending' => self::tab(['Bài vừa đăng năm phút trước, admin muốn biết ngay đã lên chưa']),
            'published' => self::tab(),
            'declined' => self::tab(),
            'removed' => self::tab(),
        ])->assertOk();

        $this->actingAs($admin)->get('/admin/fb-groups')->assertInertia(fn (Assert $page) => $page
            ->where('review.supported', true)
            ->where('groups.0.review_pending_count', 1)
            ->where('groups.0.review_published_count', 0));
        $this->actingAs($admin)->get('/admin/fb-posts')->assertInertia(fn (Assert $page) => $page
            ->where('deals.0.posts.0.review_state', 'pending'));

        $this->assertSame('pending', $post->fresh()->review_state);
    }

    public function test_fingerprint_ignores_case_spacing_emoji_and_unicode_form(): void
    {
        $nfd = \Normalizer::normalize('Giảm giá Sập Sàn', \Normalizer::FORM_D);

        $this->assertSame('giảmgiásậpsàn', FacebookGroupReviewChecker::fingerprint("🔥 GIẢM giá\n  sập-sàn!!"));
        $this->assertSame('giảmgiásậpsàn', FacebookGroupReviewChecker::fingerprint($nfd));
    }
}
