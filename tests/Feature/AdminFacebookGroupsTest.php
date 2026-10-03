<?php

namespace Tests\Feature;

use App\Exceptions\AffiliateScanException;
use App\Models\FacebookGroup;
use App\Models\FacebookGroupDeal;
use App\Models\FacebookGroupPost;
use App\Models\Setting;
use App\Services\FacebookGroupRunnerSettings;
use App\Services\VoucherFetchResult;
use App\Services\VoucherFetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class AdminFacebookGroupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.shopee_affiliate.mmp_pid' => 'an_ours']);
    }

    private function admin()
    {
        return $this->actingAs($this->createAdmin());
    }

    private function group(array $attributes = []): FacebookGroup
    {
        static $n = 0;
        $n++;

        return FacebookGroup::create(array_merge([
            'fb_group_key' => "g{$n}",
            'name' => "Nhóm {$n}",
            'url' => FacebookGroup::urlFor("g{$n}"),
            'enabled' => true,
            'source' => 'sync',
        ], $attributes));
    }

    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'shopee_url' => 'https://shopee.vn/ao-thun-i.11.22',
            'caption' => "Deal: Áo thun\n\n{link}",
            'product' => ['product_name' => 'Áo thun', 'discounted_price' => 99000],
            'fallback_buy_url' => url('/go/abc123'),
            'group_ids' => [],
        ], $overrides);
    }

    public function test_customers_cannot_reach_any_page_or_action(): void
    {
        $this->actingAs($this->createUser());

        $this->get('/admin/fb-groups')->assertForbidden();
        $this->get('/admin/fb-posts')->assertForbidden();
        $this->postJson('/admin/fb-groups/token')->assertForbidden();
        $this->postJson('/admin/fb-posts/compose', ['url' => 'https://shopee.vn/x-i.1.2'])->assertForbidden();
        $this->post('/admin/fb-posts', $this->storePayload())->assertForbidden();
    }

    public function test_pages_render_for_admin(): void
    {
        $this->group(['name' => 'Săn sale']);

        $this->admin()->get('/admin/fb-groups')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/FacebookGroups')
            ->where('paused', false)
            ->where('cadence.max_per_day', 10)
            ->where('groups.0.name', 'Săn sale')
            ->where('tokenTail', null));

        $this->admin()->get('/admin/fb-posts')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/FacebookGroupPosts')
            ->has('groups', 1)
            ->where('today.max', 10));
    }

    public function test_token_is_shown_once_and_stored_encrypted(): void
    {
        $token = $this->admin()->postJson('/admin/fb-groups/token')->assertOk()->json('token');

        $stored = Setting::where('key', 'fb_runner_token')->value('value');
        $this->assertSame(48, strlen($token));
        $this->assertNotSame($token, $stored);
        $this->assertSame($token, Crypt::decryptString($stored));

        $this->admin()->get('/admin/fb-groups')->assertInertia(fn (Assert $page) => $page
            ->where('tokenTail', substr($token, -4)));
    }

    public function test_cadence_is_validated_and_saved(): void
    {
        $valid = [
            'max_per_day' => 6, 'gap_min' => 45, 'gap_max' => 90, 'cooldown_hours' => 48,
            'window_start' => '09:00', 'window_end' => '21:30', 'expire_hours' => 24,
        ];

        $this->admin()->post('/admin/fb-groups/settings', array_merge($valid, ['gap_max' => 30]))
            ->assertSessionHasErrors('gap_max');
        $this->admin()->post('/admin/fb-groups/settings', array_merge($valid, ['window_end' => '08:00']))
            ->assertSessionHasErrors('window_end');

        $this->admin()->post('/admin/fb-groups/settings', $valid)->assertSessionHasNoErrors();
        $this->assertSame($valid, app(FacebookGroupRunnerSettings::class)->cadence());
    }

    public function test_pause_resume_clear_wait_and_sync_request(): void
    {
        $settings = app(FacebookGroupRunnerSettings::class);

        $this->admin()->post('/admin/fb-groups/pause', ['paused' => true]);
        $this->assertTrue($settings->paused());
        $this->admin()->post('/admin/fb-groups/pause', ['paused' => false]);
        $this->assertFalse($settings->paused());

        $settings->setNextAllowedAt(now()->addHour());
        $this->admin()->post('/admin/fb-groups/clear-wait');
        $this->assertNull($settings->nextAllowedAt());

        $this->admin()->post('/admin/fb-groups/sync');
        $this->assertTrue($settings->syncRequested());
    }

    public function test_manual_add_accepts_any_group_link_and_rejects_others(): void
    {
        $this->admin()->post('/admin/fb-groups', ['url' => 'https://m.facebook.com/groups/SanSale/permalink/123/?ref=share'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $group = FacebookGroup::firstOrFail();
        $this->assertSame('sansale', $group->fb_group_key);
        $this->assertSame('https://www.facebook.com/groups/sansale/', $group->url);
        $this->assertTrue($group->enabled);
        $this->assertSame('manual', $group->source);

        $this->admin()->post('/admin/fb-groups', ['url' => 'https://www.facebook.com/somepage'])->assertSessionHasErrors('url');
        $this->admin()->post('/admin/fb-groups', ['url' => 'https://www.facebook.com/groups/joins/'])->assertSessionHasErrors('url');
        $this->assertSame(1, FacebookGroup::count());
    }

    public function test_toggle_group_and_only_manual_groups_can_be_deleted(): void
    {
        $synced = $this->group(['enabled' => false, 'disabled_reason' => 'Chỉ admin được đăng']);
        $manual = $this->group(['source' => 'manual']);

        $this->admin()->patch("/admin/fb-groups/{$synced->id}", ['enabled' => true]);
        $this->assertTrue($synced->fresh()->enabled);
        $this->assertNull($synced->fresh()->disabled_reason);

        $this->admin()->delete("/admin/fb-groups/{$synced->id}")->assertSessionHasErrors('group');
        $this->admin()->delete("/admin/fb-groups/{$manual->id}")->assertSessionHasNoErrors();
        $this->assertSame(1, FacebookGroup::count());
    }

    public function test_compose_builds_draft_with_our_link(): void
    {
        $fetcher = Mockery::mock(VoucherFetchService::class);
        $fetcher->shouldReceive('fetch')->with('https://s.shopee.vn/abc')->andReturn(new VoucherFetchResult(
            'kieushopee', 'https://shopee.vn/product/11/22', 'https://shopee.vn/product/11/22',
            ['voucher_link' => 'https://shopee.vn/product/11/22?mmp_pid=an_source', 'shop_id' => '11', 'item_id' => '22',
                'product' => ['product_name' => 'Tai nghe {Pro}', 'product_image' => 'https://cf.shopee.vn/file/x',
                    'original_price' => 300000, 'discounted_price' => 199000, 'discount_percent' => 34, 'sold_count' => 9]],
        ));
        $this->app->instance(VoucherFetchService::class, $fetcher);

        $data = $this->admin()->postJson('/admin/fb-posts/compose', ['url' => 'https://s.shopee.vn/abc'])->assertOk()->json();

        $this->assertStringStartsWith(url('/go/'), $data['fallback_buy_url']);
        $this->assertNull($data['fallback_ytb_url']);
        $this->assertStringContainsString('{link}', $data['caption']);
        $this->assertStringContainsString('Tai nghe (Pro)', $data['caption']);
        $this->assertStringContainsString('199.000₫', $data['caption']);
        $this->assertArrayNotHasKey('sold_count', $data['product']);
    }

    public function test_compose_reports_source_errors_as_422(): void
    {
        $this->admin()->postJson('/admin/fb-posts/compose', ['url' => 'https://lazada.vn/x'])->assertStatus(422);

        $fetcher = Mockery::mock(VoucherFetchService::class);
        $fetcher->shouldReceive('fetch')->andThrow(new AffiliateScanException('Nguồn mã chỉ nhận link chia sẻ từ app'));
        $this->app->instance(VoucherFetchService::class, $fetcher);

        $this->admin()->postJson('/admin/fb-posts/compose', ['url' => 'https://shopee.vn/x-i.1.2'])
            ->assertStatus(422)->assertJson(['message' => 'Nguồn mã chỉ nhận link chia sẻ từ app']);
    }

    public function test_store_queues_one_post_per_enabled_group(): void
    {
        $a = $this->group();
        $b = $this->group();
        $off = $this->group(['enabled' => false]);

        $this->admin()->post('/admin/fb-posts', $this->storePayload(['group_ids' => [$a->id, $off->id]]))
            ->assertSessionHasErrors('group_ids.1');

        $this->admin()->post('/admin/fb-posts', $this->storePayload(['group_ids' => [$a->id, $b->id, $b->id]]))
            ->assertSessionHasNoErrors();

        $deal = FacebookGroupDeal::firstOrFail();
        $this->assertSame(2, $deal->posts()->count());
        $this->assertSame(['pending'], $deal->posts()->pluck('status')->unique()->values()->all());
        $this->assertNotNull($deal->posts()->first()->queued_at);
    }

    public function test_store_requires_link_placeholder_and_own_fallback_link(): void
    {
        $group = $this->group();

        $this->admin()->post('/admin/fb-posts', $this->storePayload(['group_ids' => [$group->id], 'caption' => 'Không có link']))
            ->assertSessionHasErrors('caption');
        $this->admin()->post('/admin/fb-posts', $this->storePayload(['group_ids' => [$group->id], 'fallback_buy_url' => 'https://evil.example/go/x']))
            ->assertSessionHasErrors('fallback_buy_url');
        $this->admin()->post('/admin/fb-posts', $this->storePayload(['group_ids' => [$group->id], 'shopee_url' => 'https://lazada.vn/x']))
            ->assertSessionHasErrors('shopee_url');

        $this->assertSame(0, FacebookGroupDeal::count());
    }

    public function test_cancel_pending_and_retry_finished_posts(): void
    {
        $deal = FacebookGroupDeal::create(['shopee_url' => 'https://shopee.vn/x-i.1.2', 'caption' => '{link}']);
        $pending = $deal->posts()->create(['facebook_group_id' => $this->group()->id, 'status' => 'pending', 'queued_at' => now()]);
        $posted = $deal->posts()->create(['facebook_group_id' => $this->group()->id, 'status' => 'posted']);
        $ambiguous = $deal->posts()->create([
            'facebook_group_id' => $this->group()->id, 'status' => 'ambiguous',
            'claim_key' => 'old-key', 'caption' => 'x', 'error' => 'không rõ', 'queued_at' => now()->subDays(3),
        ]);

        $this->admin()->post("/admin/fb-posts/{$pending->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame(FacebookGroupPost::CANCELLED, $pending->fresh()->status);

        $this->admin()->post("/admin/fb-posts/{$posted->id}/retry")->assertSessionHasErrors('post');
        $this->admin()->post("/admin/fb-posts/{$posted->id}/cancel")->assertSessionHasErrors('post');

        $this->admin()->post("/admin/fb-posts/{$ambiguous->id}/retry")->assertSessionHasNoErrors();
        $ambiguous->refresh();
        $this->assertSame(FacebookGroupPost::PENDING, $ambiguous->status);
        $this->assertNull($ambiguous->claim_key);
        $this->assertNull($ambiguous->error);
        $this->assertTrue($ambiguous->queued_at->isToday());
    }
}
