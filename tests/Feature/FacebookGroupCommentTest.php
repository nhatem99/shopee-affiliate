<?php

namespace Tests\Feature;

use App\Models\FacebookGroup;
use App\Models\FacebookGroupDeal;
use App\Models\FacebookGroupPost;
use App\Models\FacebookProfile;
use App\Services\FacebookGroupCommentQueue;
use App\Services\FacebookGroupRunnerSettings;
use App\Services\VoucherFetchResult;
use App\Services\VoucherFetchService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

/**
 * Bài "link ở bình luận": bài lên nhóm không có link, đăng xong bot vào bài bình luận link mua.
 */
class FacebookGroupCommentTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'claim-key-000000000001';

    private const KEY2 = 'claim-key-000000000002';

    private const BOT = '1.4.0-node';

    private const POST_URL = 'https://www.facebook.com/groups/group1/posts/1234567890/';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.shopee_affiliate.mmp_pid' => 'an_ours']);
        $this->travelTo(Carbon::parse('2026-10-03 09:00:00'));
        $this->token = app(FacebookGroupRunnerSettings::class)->regenerateToken();

        $fetcher = Mockery::mock(VoucherFetchService::class);
        $fetcher->shouldReceive('fetch')->andReturn(new VoucherFetchResult(
            'kieushopee',
            'https://shopee.vn/product/11/22',
            'https://shopee.vn/product/11/22',
            [
                'voucher_link' => 'https://shopee.vn/product/11/22?mmp_pid=an_source&credential_token=abc',
                'shop_id' => '11',
                'item_id' => '22',
                'product' => ['product_name' => 'Áo thun'],
            ],
        ));
        $this->app->instance(VoucherFetchService::class, $fetcher);
    }

    private function poll(string $key = self::KEY, string $version = self::BOT)
    {
        return $this->postJson('/runner/fb/poll', [
            'claim_key' => $key,
            'state' => 'ok',
            'version' => $version,
        ], ['X-Runner-Token' => $this->token]);
    }

    private function report(FacebookGroupPost $post, string $status, ?string $postUrl = null)
    {
        return $this->postJson("/runner/fb/posts/{$post->id}/result", [
            'claim_key' => self::KEY,
            'status' => $status,
            'post_url' => $postUrl,
        ], ['X-Runner-Token' => $this->token]);
    }

    private function reportComment(FacebookGroupPost $post, string $status, ?string $error = null, ?string $postUrl = null)
    {
        return $this->postJson("/runner/fb/posts/{$post->id}/comment", [
            'status' => $status,
            'error' => $error,
            'post_url' => $postUrl,
        ], ['X-Runner-Token' => $this->token]);
    }

    private function group(): FacebookGroup
    {
        static $n = 0;
        $n++;

        $group = FacebookGroup::create([
            'fb_group_key' => "group{$n}",
            'name' => "Nhóm {$n}",
            'url' => FacebookGroup::urlFor("group{$n}"),
            'enabled' => true,
            'source' => 'sync',
        ]);
        $group->profiles()->attach(FacebookProfile::primary());

        return $group;
    }

    private function queue(bool $inComment = true): FacebookGroupPost
    {
        $deal = FacebookGroupDeal::create([
            'shopee_url' => 'https://shopee.vn/ao-thun-i.11.22',
            'source' => 'kieushopee',
            'product' => ['product_name' => 'Áo thun'],
            'caption' => "{Deal hời|Giá tốt}: Áo thun cotton giảm sâu\n\n{link}",
            'link_in_comment' => $inComment,
        ]);

        return FacebookGroupPost::create([
            'facebook_group_deal_id' => $deal->id,
            'facebook_group_id' => $this->group()->id,
            'status' => FacebookGroupPost::PENDING,
            'queued_at' => now(),
        ]);
    }

    /** Nhận bài, báo kết quả — bài đã lên nhóm (hoặc không) theo $status. */
    private function postedVia(string $status = FacebookGroupPost::POSTED, ?string $postUrl = self::POST_URL): FacebookGroupPost
    {
        $post = $this->queue();
        $this->poll()->assertJson(['type' => 'post', 'post_id' => $post->id]);
        $this->report($post, $status, $postUrl)->assertOk();

        return $post->fresh();
    }

    public function test_post_has_no_link_and_the_link_waits_in_the_comment(): void
    {
        $post = $this->queue();

        $caption = $this->poll()->assertOk()->assertJson(['type' => 'post'])->json('caption');

        $post->refresh();
        $this->assertStringNotContainsString('http', $caption);
        $this->assertStringContainsString('bình luận', $caption);
        $this->assertStringNotContainsString('{', $caption);
        $this->assertStringContainsString(url('/go/'), $post->comment);
        $this->assertStringContainsString($post->buy_url, $post->comment);
        $this->assertSame(FacebookGroupPost::COMMENT_PENDING, $post->comment_status);
    }

    public function test_link_stays_in_the_post_when_admin_did_not_ask_for_a_comment(): void
    {
        $post = $this->queue(inComment: false);

        $caption = $this->poll()->assertJson(['type' => 'post'])->json('caption');

        $this->assertStringContainsString(url('/go/'), $caption);
        $this->assertNull($post->fresh()->comment);
        $this->assertNull($post->fresh()->comment_status);
    }

    public function test_old_bot_leaves_link_in_comment_posts_waiting(): void
    {
        $waiting = $this->queue();
        $plain = $this->queue(inComment: false);

        $this->poll(version: '1.3.1-node')->assertJson(['type' => 'post', 'post_id' => $plain->id]);
        $this->assertSame(FacebookGroupPost::PENDING, $waiting->fresh()->status);

        $this->poll(self::KEY2)->assertJson(['type' => 'idle', 'reason' => 'busy']);
    }

    public function test_comment_job_comes_right_after_the_post_without_waiting_the_gap(): void
    {
        $post = $this->postedVia();
        $this->assertSame(self::POST_URL, $post->post_url);
        $this->assertNotNull(app(FacebookGroupRunnerSettings::class)->nextAllowedAt());

        $this->poll(self::KEY2)->assertOk()->assertExactJson([
            'type' => 'comment',
            'post_id' => $post->id,
            'group_url' => $post->group->url,
            'group_name' => $post->group->name,
            'post_url' => self::POST_URL,
            'caption' => $post->caption,
            'comment' => $post->comment,
            'marker' => preg_replace('#^https?://#', '', $post->buy_url),
            'search' => [$post->group->url.'my_posted_content/'],
            'profile' => FacebookProfile::primary()->forRunner(),
        ]);

        $this->assertSame(1, $post->fresh()->comment_attempts);
        // Đã giao rồi thì lượt sau không giao trùng — quay về nghỉ giữa hai bài.
        $this->poll(self::KEY2)->assertJson(['type' => 'idle', 'reason' => 'gap']);
    }

    public function test_old_bot_never_gets_comment_jobs(): void
    {
        $post = $this->postedVia();

        $this->poll(self::KEY2, '1.3.1-node')->assertJson(['type' => 'idle']);
        $this->assertSame(0, $post->fresh()->comment_attempts);
    }

    public function test_commented_is_final_and_a_second_report_is_rejected(): void
    {
        $post = $this->postedVia();
        $this->poll(self::KEY2)->assertJson(['type' => 'comment']);

        $this->reportComment($post, 'commented')->assertOk();

        $post->refresh();
        $this->assertSame(FacebookGroupPost::COMMENT_DONE, $post->comment_status);
        $this->assertNotNull($post->commented_at);
        $this->reportComment($post, 'commented')->assertStatus(409);
    }

    public function test_reports_for_posts_never_handed_out_are_rejected(): void
    {
        $post = $this->postedVia();

        $this->reportComment($post, 'commented')->assertStatus(409);
        $this->reportComment($post, 'nonsense')->assertUnprocessable();
    }

    public function test_unconfirmed_post_is_tried_once_then_waits_for_the_review_to_see_it_published(): void
    {
        $post = $this->postedVia(postUrl: null);
        $this->poll(self::KEY2)->assertJson(['type' => 'comment', 'post_url' => null]);
        $this->reportComment($post, 'not_found', 'Không thấy bài trong "Nội dung của bạn"')->assertOk();

        $this->travel(30)->minutes();
        $this->poll(self::KEY2)->assertJsonMissing(['type' => 'comment']);
        $this->assertSame(FacebookGroupPost::COMMENT_PENDING, $post->fresh()->comment_status);

        // Lượt kiểm tra duyệt bài thấy bài ở tab "Đã đăng", kèm link bài.
        $post->forceFill(['review_state' => FacebookGroupPost::REVIEW_PUBLISHED, 'post_url' => self::POST_URL])->save();
        $this->poll(self::KEY2)->assertJson(['type' => 'comment', 'post_id' => $post->id, 'post_url' => self::POST_URL]);
        $this->reportComment($post, 'failed', 'Không thấy ô bình luận')->assertOk();
        $this->assertSame(FacebookGroupPost::COMMENT_PENDING, $post->fresh()->comment_status);

        // Lượt thử cách nhau 20 phút, tối đa 3 lượt.
        $this->travel(10)->minutes();
        $this->poll(self::KEY2)->assertJsonMissing(['type' => 'comment']);
        $this->travel(11)->minutes();
        $this->poll(self::KEY2)->assertJson(['type' => 'comment']);
        $this->reportComment($post, 'failed', 'Không thấy ô bình luận')->assertOk();

        $post->refresh();
        $this->assertSame(3, $post->comment_attempts);
        $this->assertSame(FacebookGroupPost::COMMENT_FAILED, $post->comment_status);
        $this->assertSame('Không thấy ô bình luận', $post->comment_error);
    }

    public function test_post_waiting_for_group_approval_gets_its_comment_once_published(): void
    {
        $post = $this->postedVia(FacebookGroupPost::PENDING_APPROVAL);
        $this->assertSame(FacebookGroupPost::COMMENT_PENDING, $post->comment_status);

        $this->poll(self::KEY2)->assertJsonMissing(['type' => 'comment']);

        $post->forceFill(['review_state' => FacebookGroupPost::REVIEW_PENDING])->save();
        $this->poll(self::KEY2)->assertJsonMissing(['type' => 'comment']);

        $post->forceFill(['review_state' => FacebookGroupPost::REVIEW_PUBLISHED])->save();
        $this->poll(self::KEY2)->assertJson(['type' => 'comment', 'post_id' => $post->id]);
    }

    public function test_declined_or_removed_posts_get_no_comment(): void
    {
        $post = $this->postedVia();
        $post->forceFill(['review_state' => FacebookGroupPost::REVIEW_REMOVED])->save();

        $this->poll(self::KEY2)->assertJsonMissing(['type' => 'comment']);
    }

    public function test_post_that_never_went_up_needs_no_comment(): void
    {
        $post = $this->postedVia(FacebookGroupPost::FAILED, postUrl: null);

        $this->assertNull($post->comment_status);
    }

    public function test_ambiguous_comment_is_not_retried(): void
    {
        $post = $this->postedVia();
        $this->poll(self::KEY2)->assertJson(['type' => 'comment']);
        $this->reportComment($post, 'ambiguous')->assertOk();

        $this->travel(1)->hour();
        $this->poll(self::KEY2)->assertJsonMissing(['type' => 'comment']);
        $this->assertSame(FacebookGroupPost::COMMENT_UNKNOWN, $post->fresh()->comment_status);
    }

    public function test_silent_bot_on_the_last_attempt_gives_up(): void
    {
        $post = $this->postedVia();
        $post->forceFill(['review_state' => FacebookGroupPost::REVIEW_PUBLISHED, 'comment_attempts' => 2])->save();
        $this->poll(self::KEY2)->assertJson(['type' => 'comment']);

        $this->travel(21)->minutes();
        $this->poll(self::KEY2)->assertJsonMissing(['type' => 'comment']);

        $this->assertSame(FacebookGroupPost::COMMENT_FAILED, $post->fresh()->comment_status);
    }

    public function test_no_comment_outside_the_posting_window_or_for_a_blocked_page(): void
    {
        $post = $this->postedVia();

        $this->travelTo(Carbon::parse('2026-10-03 23:00:00'));
        $this->poll(self::KEY2)->assertJson(['type' => 'idle', 'reason' => 'outside_window']);

        $this->travelTo(Carbon::parse('2026-10-04 09:00:00'));
        FacebookProfile::primary()->forceFill(['blocked_at' => now()])->save();
        $this->poll(self::KEY2)->assertJsonMissing(['type' => 'comment']);

        FacebookProfile::primary()->forceFill(['blocked_at' => null])->save();
        $this->poll(self::KEY2)->assertJson(['type' => 'comment', 'post_id' => $post->id]);
    }

    public function test_only_group_post_links_are_kept(): void
    {
        $post = $this->postedVia(postUrl: 'https://evil.example/groups/x/posts/1');

        $this->assertNull($post->post_url);

        $this->poll(self::KEY2)->assertJson(['type' => 'comment']);
        $this->reportComment($post, 'commented', null, self::POST_URL)->assertOk();
        $this->assertSame(self::POST_URL, $post->fresh()->post_url);
    }

    public function test_retry_and_requeue_reset_the_comment(): void
    {
        $post = $this->postedVia(FacebookGroupPost::FAILED, postUrl: null);
        $post->forceFill(['comment_attempts' => 2, 'comment_error' => 'x'])->save();

        $this->actingAs($this->createAdmin())->post("/admin/fb-posts/{$post->id}/retry")->assertSessionHasNoErrors();

        $post->refresh();
        $this->assertSame(FacebookGroupPost::PENDING, $post->status);
        $this->assertNull($post->comment);
        $this->assertSame(0, $post->comment_attempts);
        $this->assertNull($post->comment_error);
    }

    public function test_admin_chooses_link_in_comment_for_link_posts_only(): void
    {
        $group = $this->group();
        $payload = [
            'shopee_url' => 'https://shopee.vn/ao-thun-i.11.22',
            'caption' => "Deal: Áo thun\n\n{link}",
            'fallback_buy_url' => url('/go/abc123'),
            'link_in_comment' => true,
            'group_ids' => [$group->id],
        ];

        $this->actingAs($this->createAdmin())->post('/admin/fb-posts', $payload)->assertSessionHasNoErrors();
        $this->assertTrue(FacebookGroupDeal::latest('id')->first()->link_in_comment);

        $other = $this->group();
        $this->actingAs($this->createAdmin())->post('/admin/fb-posts', [
            'shopee_url' => null,
            'caption' => 'Bài tự soạn',
            'link_in_comment' => true,
            'group_ids' => [$other->id],
        ])->assertSessionHasNoErrors();
        $this->assertFalse(FacebookGroupDeal::latest('id')->first()->link_in_comment);
    }

    public function test_post_list_shows_comment_state_and_warns_when_bot_is_too_old(): void
    {
        $this->queue();
        app(FacebookGroupRunnerSettings::class)->recordRunner(['state' => 'ok', 'version' => '1.3.1-node']);

        $this->actingAs($this->createAdmin())->get('/admin/fb-posts')->assertInertia(fn (Assert $page) => $page
            ->where('deals.0.link_in_comment', true)
            ->where('deals.0.posts.0.comment_status', null)
            ->where('runner.supports_comments', false)
            ->where('runner.comments_waiting', true));

        app(FacebookGroupRunnerSettings::class)->recordRunner(['state' => 'ok', 'version' => self::BOT]);
        $this->actingAs($this->createAdmin())->get('/admin/fb-posts')->assertInertia(fn (Assert $page) => $page
            ->where('runner.supports_comments', true)
            ->where('runner.comments_waiting', false));
    }

    public function test_runner_version_check(): void
    {
        $this->assertTrue(FacebookGroupCommentQueue::runnerSupports('1.4.0-node'));
        $this->assertFalse(FacebookGroupCommentQueue::runnerSupports('1.3.1-node'));
        $this->assertFalse(FacebookGroupCommentQueue::runnerSupports('1.1.0'));
        $this->assertFalse(FacebookGroupCommentQueue::runnerSupports(null));
    }
}
