<?php

namespace Tests\Feature;

use App\Exceptions\AffiliateScanException;
use App\Models\FacebookGroup;
use App\Models\FacebookGroupDeal;
use App\Models\FacebookGroupPost;
use App\Models\FacebookPostTemplate;
use App\Models\Setting;
use App\Services\FacebookGroupRunnerSettings;
use App\Services\FacebookPostImages;
use App\Services\VoucherFetchResult;
use App\Services\VoucherFetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    /** Ảnh "đã tải lên" nằm sẵn trên disk — tên đúng dạng server sinh. */
    private function uploadedImage(): string
    {
        $name = bin2hex(random_bytes(16)).'.jpg';
        Storage::disk('local')->put(FacebookPostImages::DIR.'/'.$name, 'jpeg-bytes');

        return $name;
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
        $this->post('/admin/fb-posts/images', ['image' => UploadedFile::fake()->image('a.jpg')])->assertForbidden();
        $this->get('/admin/fb-posts/images/'.str_repeat('a', 32).'.jpg')->assertForbidden();
        $this->post('/admin/fb-posts/templates', ['name' => 'x', 'caption' => 'x'])->assertForbidden();
        $template = FacebookPostTemplate::create(['name' => 'Mẫu', 'caption' => 'x']);
        $this->put("/admin/fb-posts/templates/{$template->id}", ['name' => 'y', 'caption' => 'y'])->assertForbidden();
        $this->delete("/admin/fb-posts/templates/{$template->id}")->assertForbidden();
        $this->assertSame('Mẫu', $template->fresh()->name);
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
            ->where('today.max', 10)
            ->where('maxImages', 5)
            ->where('runner.supports_uploads', false)
            ->where('runner.uploads_waiting', false));
    }

    public function test_post_list_shows_custom_posts_and_warns_when_bot_is_too_old(): void
    {
        Storage::fake('local');
        $name = $this->uploadedImage();
        $deal = FacebookGroupDeal::create(['shopee_url' => null, 'caption' => "{Chào|Hello} cả nhà\nDòng 2", 'images' => [$name]]);
        $deal->posts()->create(['facebook_group_id' => $this->group()->id, 'status' => 'pending', 'queued_at' => now()]);
        app(FacebookGroupRunnerSettings::class)->recordRunner(['state' => 'ok', 'version' => '1.0.0-node']);

        $this->admin()->get('/admin/fb-posts')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('deals.0.custom', true)
            ->where('deals.0.excerpt', '{Chào|Hello} cả nhà')
            ->where('deals.0.images', [route('admin.fb-posts.image', $name)])
            ->where('runner.version', '1.0.0-node')
            ->where('runner.uploads_waiting', true));

        app(FacebookGroupRunnerSettings::class)->recordRunner(['state' => 'ok', 'version' => '1.1.0-node']);
        $this->admin()->get('/admin/fb-posts')->assertInertia(fn (Assert $page) => $page
            ->where('runner.supports_uploads', true)
            ->where('runner.uploads_waiting', false));
    }

    public function test_admin_uploads_image_and_views_it(): void
    {
        Storage::fake('local');

        $name = $this->admin()->post('/admin/fb-posts/images', ['image' => UploadedFile::fake()->image('anh.jpg', 50, 50)])
            ->assertOk()->json('name');

        $this->assertTrue(FacebookPostImages::validName($name));
        Storage::disk('local')->assertExists(FacebookPostImages::DIR.'/'.$name);
        $this->admin()->get('/admin/fb-posts/images/'.$name)->assertOk();
        $this->admin()->get('/admin/fb-posts/images/'.str_repeat('a', 32).'.jpg')->assertNotFound();

        $this->admin()->postJson('/admin/fb-posts/images', ['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_upload_prunes_old_unused_images_only(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $unused = $this->uploadedImage();
        $used = $this->uploadedImage();
        $inTemplate = $this->uploadedImage();
        $recent = $this->uploadedImage();
        FacebookGroupDeal::create(['shopee_url' => null, 'caption' => 'x', 'images' => [$used]]);
        FacebookPostTemplate::create(['name' => 'Mẫu', 'caption' => 'x', 'images' => [$inTemplate]]);
        foreach ([$unused, $used, $inTemplate] as $name) {
            touch($disk->path(FacebookPostImages::DIR.'/'.$name), now()->subDays(2)->getTimestamp());
        }

        $this->admin()->post('/admin/fb-posts/images', ['image' => UploadedFile::fake()->image('a.jpg')])->assertOk();

        $disk->assertMissing(FacebookPostImages::DIR.'/'.$unused);
        $disk->assertExists(FacebookPostImages::DIR.'/'.$used);
        $disk->assertExists(FacebookPostImages::DIR.'/'.$inTemplate);
        $disk->assertExists(FacebookPostImages::DIR.'/'.$recent);
    }

    public function test_store_custom_post_without_shopee_link(): void
    {
        Storage::fake('local');
        $group = $this->group();
        $names = [$this->uploadedImage(), $this->uploadedImage()];
        $payload = ['shopee_url' => '', 'caption' => 'Mẹo săn sale hôm nay', 'images' => $names, 'group_ids' => [$group->id]];

        $this->admin()->post('/admin/fb-posts', array_merge($payload, ['caption' => "Mẹo\n{link}"]))
            ->assertSessionHasErrors('caption');
        $this->admin()->post('/admin/fb-posts', array_merge($payload, ['images' => [str_repeat('a', 32).'.jpg']]))
            ->assertSessionHasErrors('images.0');
        $this->admin()->post('/admin/fb-posts', array_merge($payload, ['images' => ['../../.env']]))
            ->assertSessionHasErrors('images.0');
        $this->assertSame(0, FacebookGroupDeal::count());

        // Rác của kiểu bài có link (link dự phòng, sản phẩm) bị bỏ, không lưu vào bài tự soạn.
        $this->admin()->post('/admin/fb-posts', array_merge($payload, ['fallback_buy_url' => url('/go/abc123'), 'product' => ['product_name' => 'X']]))
            ->assertSessionHasNoErrors();

        $deal = FacebookGroupDeal::firstOrFail();
        $this->assertTrue($deal->isCustom());
        $this->assertSame($names, $deal->images);
        $this->assertNull($deal->product);
        $this->assertNull($deal->fallback_buy_url);
        $this->assertSame(1, $deal->posts()->count());
    }

    public function test_templates_can_be_saved_updated_listed_and_deleted(): void
    {
        Storage::fake('local');
        [$a, $b] = [$this->uploadedImage(), $this->uploadedImage()];
        $caption = "Mã 25% 22% mọi người tranh thủ mua nhé. Lấy mã ở đây 👉\nhttps://dealngon.top";

        $this->admin()->post('/admin/fb-posts/templates', ['name' => '', 'caption' => $caption])->assertSessionHasErrors('name');
        $this->admin()->post('/admin/fb-posts/templates', ['name' => 'Mã', 'caption' => "x\n{link}"])->assertSessionHasErrors('caption');
        $this->admin()->post('/admin/fb-posts/templates', ['name' => 'Mã', 'caption' => 'x', 'images' => [str_repeat('a', 32).'.jpg']])
            ->assertSessionHasErrors('images.0');
        $this->admin()->post('/admin/fb-posts/templates', ['name' => 'Mã', 'caption' => 'x', 'images' => array_map(fn () => $this->uploadedImage(), range(1, 6))])
            ->assertSessionHasErrors('images');
        $this->assertSame(0, FacebookPostTemplate::count());

        $this->admin()->post('/admin/fb-posts/templates', ['name' => ' Mã dealngon ', 'caption' => $caption, 'images' => [$a, $b]])
            ->assertSessionHasNoErrors();
        $template = FacebookPostTemplate::firstOrFail();
        $this->assertSame('Mã dealngon', $template->name);
        $this->assertSame([$a, $b], $template->images);

        $this->admin()->get('/admin/fb-posts')->assertInertia(fn (Assert $page) => $page
            ->has('templates', 1)
            ->where('templates.0.name', 'Mã dealngon')
            ->where('templates.0.caption', $caption)
            ->where('templates.0.images', [
                ['name' => $a, 'url' => route('admin.fb-posts.image', $a)],
                ['name' => $b, 'url' => route('admin.fb-posts.image', $b)],
            ]));

        $this->admin()->put("/admin/fb-posts/templates/{$template->id}", ['name' => 'Mã dealngon', 'caption' => 'Nội dung mới', 'images' => [$b]])
            ->assertSessionHasNoErrors();
        $template->refresh();
        $this->assertSame('Nội dung mới', $template->caption);
        $this->assertSame([$b], $template->images);

        $this->admin()->put("/admin/fb-posts/templates/{$template->id}", ['name' => 'Mã dealngon', 'caption' => 'Không ảnh', 'images' => []])
            ->assertSessionHasNoErrors();
        $this->assertNull($template->fresh()->images);

        $this->admin()->delete("/admin/fb-posts/templates/{$template->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, FacebookPostTemplate::count());
        Storage::disk('local')->assertExists(FacebookPostImages::DIR.'/'.$a); // prune() dọn sau, không xoá ngay
    }

    public function test_custom_deals_offer_reuse_but_link_deals_do_not(): void
    {
        Storage::fake('local');
        $name = $this->uploadedImage();
        FacebookGroupDeal::create(['shopee_url' => 'https://shopee.vn/x-i.1.2', 'caption' => '{link}']);
        FacebookGroupDeal::create(['shopee_url' => null, 'caption' => 'Bài tự soạn', 'images' => [$name]]);

        $this->admin()->get('/admin/fb-posts')->assertInertia(fn (Assert $page) => $page
            ->where('deals.0.reuse.caption', 'Bài tự soạn')
            ->where('deals.0.reuse.images', [['name' => $name, 'url' => route('admin.fb-posts.image', $name)]])
            ->where('deals.1.reuse', null));
    }

    public function test_store_caps_images_including_the_product_image(): void
    {
        Storage::fake('local');
        $group = $this->group();
        $names = array_map(fn () => $this->uploadedImage(), range(1, 5));
        $product = ['product_name' => 'Áo thun', 'product_image' => 'https://down-vn.img.susercontent.com/file/abc.webp'];
        $payload = $this->storePayload(['group_ids' => [$group->id], 'product' => $product, 'images' => $names]);

        $this->admin()->post('/admin/fb-posts', $payload)->assertSessionHasErrors('images');
        $this->admin()->post('/admin/fb-posts', array_merge($payload, ['images' => [...$names, $this->uploadedImage()], 'with_product_image' => false]))
            ->assertSessionHasErrors('images');
        $this->assertSame(0, FacebookGroupDeal::count());

        $this->admin()->post('/admin/fb-posts', array_merge($payload, ['with_product_image' => false]))->assertSessionHasNoErrors();

        $deal = FacebookGroupDeal::firstOrFail();
        $this->assertFalse($deal->with_product_image);
        $this->assertSame($names, $deal->images);
        $this->assertFalse($deal->isCustom());
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
        $this->assertNull($deal->images);
        $this->assertTrue($deal->with_product_image);
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
