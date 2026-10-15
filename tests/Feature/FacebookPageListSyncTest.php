<?php

namespace Tests\Feature;

use App\Models\FacebookProfile;
use App\Services\FacebookEngageQueue;
use App\Services\FacebookEngageSettings;
use App\Services\FacebookGroupRunnerSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Nút "Lấy danh sách page": bot đọc "Trang của bạn", server thêm page mới (tắt sẵn) và tắt page
 * không còn trên Facebook.
 */
class FacebookPageListSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BOT = '1.7.0-node';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->token = app(FacebookGroupRunnerSettings::class)->regenerateToken();
        FacebookProfile::primary()->forceFill(['fb_id' => '100000000000001'])->save();
    }

    private function poll(string $version = self::BOT)
    {
        return $this->postJson('/runner/fb/poll', [
            'claim_key' => 'claim-key-000000000001',
            'state' => 'ok',
            'version' => $version,
        ], ['X-Runner-Token' => $this->token]);
    }

    private function upload(array $pages, ?string $error = null)
    {
        return $this->postJson('/runner/fb/pages', ['pages' => $pages, 'error' => $error], ['X-Runner-Token' => $this->token]);
    }

    private function page(array $attributes): FacebookProfile
    {
        return FacebookProfile::create($attributes + ['position' => (int) FacebookProfile::max('position') + 1, 'enabled' => true]);
    }

    public function test_admin_request_is_given_to_new_bot_once(): void
    {
        $this->actingAs($this->createAdmin())->post('/admin/fb-groups/profiles/sync')->assertRedirect();

        $this->assertSame('idle', $this->poll('1.6.0-node')->json('type'));
        $this->assertSame('sync_pages', $this->poll()->json('type'));
        $this->assertNotSame('sync_pages', $this->poll()->json('type'));
    }

    public function test_new_pages_added_disabled_known_matched_missing_disabled(): void
    {
        $byId = $this->page(['fb_id' => '61550000000001', 'url' => FacebookProfile::profileUrl('61550000000001'), 'name' => 'Shop Một']);
        $byUrl = $this->page(['url' => 'https://www.facebook.com/ShopHai', 'name' => 'Shop Hai']);
        $byName = $this->page(['url' => 'https://www.facebook.com/shopba.vn', 'name' => 'Shop Ba']);
        $gone = $this->page(['fb_id' => '61550000000009', 'url' => FacebookProfile::profileUrl('61550000000009'), 'name' => 'Shop Đã Xoá']);

        $result = $this->upload([
            ['url' => 'https://www.facebook.com/profile.php?id=61550000000001', 'name' => 'Shop Một (tên mới)'],
            ['url' => 'https://www.facebook.com/shophai', 'name' => 'Shop Hai'],
            // Facebook liệt kê page thêm bằng tên rút gọn bằng profile.php?id= — khớp theo tên.
            ['url' => 'https://www.facebook.com/profile.php?id=61550000000003', 'name' => 'shop ba'],
            ['url' => 'https://www.facebook.com/profile.php?id=61550000000004', 'name' => 'Shop Mới'],
            // Nick chính không phải page.
            ['url' => 'https://www.facebook.com/profile.php?id=100000000000001', 'name' => 'Minh Nhật'],
        ])->assertOk()->json();

        $this->assertSame(['created' => 1, 'updated' => 3, 'missing' => 1, 'error' => null], $result);

        $this->assertSame('Shop Một', $byId->fresh()->name);
        $this->assertTrue($byUrl->fresh()->enabled);
        $this->assertSame('61550000000003', $byName->fresh()->fb_id);

        $new = FacebookProfile::where('fb_id', '61550000000004')->firstOrFail();
        $this->assertFalse($new->enabled);
        $this->assertSame('Shop Mới', $new->name);
        $this->assertSame(FacebookProfile::profileUrl('61550000000004'), $new->url);

        $gone->refresh();
        $this->assertFalse($gone->enabled);
        $this->assertNotNull($gone->missing_since);
        $this->assertTrue(FacebookProfile::primary()->enabled);
        $this->assertSame(6, FacebookProfile::count());
    }

    public function test_page_seen_again_clears_missing_mark(): void
    {
        $page = $this->page(['fb_id' => '61550000000001', 'url' => FacebookProfile::profileUrl('61550000000001'), 'missing_since' => now(), 'enabled' => false]);

        $this->upload([['url' => FacebookProfile::profileUrl('61550000000001'), 'name' => 'Shop']])->assertOk();

        $this->assertNull($page->fresh()->missing_since);
    }

    public function test_avatar_label_names_are_cleaned_and_old_wrong_names_fixed(): void
    {
        // Bot 1.7.0 đầu tiên lưu nhầm chữ mô tả ảnh đại diện làm tên page.
        $wrong = $this->page(['fb_id' => '61550000000001', 'url' => FacebookProfile::profileUrl('61550000000001'), 'name' => 'Ảnh đại diện của Mẹ Của Bi']);
        $kept = $this->page(['fb_id' => '61550000000002', 'url' => FacebookProfile::profileUrl('61550000000002'), 'name' => 'Tên tôi tự đặt']);

        $this->upload([
            ['url' => FacebookProfile::profileUrl('61550000000001'), 'name' => 'Mẹ Của Bi'],
            ['url' => FacebookProfile::profileUrl('61550000000002'), 'name' => 'Shop Hai'],
            ['url' => FacebookProfile::profileUrl('61550000000003'), 'name' => 'Ảnh đại diện của Shop Ba'],
        ])->assertOk();

        $this->assertSame('Mẹ Của Bi', $wrong->fresh()->name);
        $this->assertSame('Tên tôi tự đặt', $kept->fresh()->name);
        $this->assertSame('Shop Ba', FacebookProfile::where('fb_id', '61550000000003')->value('name'));
    }

    public function test_empty_list_or_error_changes_nothing(): void
    {
        $page = $this->page(['fb_id' => '61550000000001', 'url' => FacebookProfile::profileUrl('61550000000001')]);

        $this->upload([])->assertOk()->assertJsonPath('missing', 0);
        $this->upload([], 'Không về được nick chính')->assertOk();

        $this->assertTrue($page->fresh()->enabled);
        $this->assertNull($page->fresh()->missing_since);
        $this->assertSame('Không về được nick chính', app(FacebookGroupRunnerSettings::class)->pagesSyncResult()['error']);
    }

    public function test_enabling_new_page_first_time_requests_group_sync(): void
    {
        $page = $this->page(['fb_id' => '61550000000004', 'url' => FacebookProfile::profileUrl('61550000000004'), 'enabled' => false]);
        $settings = app(FacebookGroupRunnerSettings::class);
        $settings->clearSyncRequest();

        $this->actingAs($this->createAdmin())->patch("/admin/fb-groups/profiles/{$page->id}", ['enabled' => true])->assertRedirect();

        $this->assertTrue($settings->syncRequested());
    }

    public function test_engage_stops_using_missing_page(): void
    {
        $page = $this->page(['fb_id' => '61550000000001', 'url' => FacebookProfile::profileUrl('61550000000001'), 'missing_since' => now()]);
        $engage = app(FacebookEngageSettings::class);
        $engage->saveConfig(['enabled' => true, 'profile_id' => $page->id]);
        $engage->saveTemplates('Follow chéo nha');

        $this->assertSame('profile_missing', app(FacebookEngageQueue::class)->blockingReason(self::BOT)['reason']);
    }

    public function test_admin_page_shows_result_and_missing_pages(): void
    {
        $gone = $this->page(['fb_id' => '61550000000009', 'url' => FacebookProfile::profileUrl('61550000000009')]);
        $this->upload([['url' => FacebookProfile::profileUrl('61550000000004'), 'name' => 'Shop Mới']])->assertOk();
        app(FacebookGroupRunnerSettings::class)->recordRunner(['state' => 'ok', 'version' => self::BOT]);

        $this->actingAs($this->createAdmin())->get('/admin/fb-groups')->assertInertia(fn (Assert $page) => $page
            ->where('pagesSync.supported', true)
            ->where('pagesSync.result.created', 1)
            ->where('pagesSync.result.missing', 1)
            ->where('profiles.1.id', $gone->id)
            ->where('profiles.1.missing_since', fn ($value) => $value !== null));
    }
}
