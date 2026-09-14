<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Models\FacebookReelSlot;
use App\Models\ShortLink;
use App\Services\FacebookReelSlotService;
use App\Services\FacebookReelSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Job facebook:sync-reels: bảng slot reel phải phản ánh caption THẬT trên Facebook, không phải
 * thứ mình tưởng đã ghi. Mỗi test dựng một lệch pha giữa bảng và Facebook rồi kiểm tra job
 * kéo bảng về đúng thực tế.
 */
class FacebookReelSyncTest extends TestCase
{
    use RefreshDatabase;

    private const HOODIE_URL = 'https://shopee.vn/ao-hoodie-i.1.100?mmp_pid=x';

    private const JEAN_URL = 'https://shopee.vn/quan-jean-i.1.200?mmp_pid=x';

    private function config(array $reels): ApiConfig
    {
        return ApiConfig::create([
            'name' => 'Facebook',
            'endpoint' => 'https://graph.facebook.com',
            'platform' => 'facebook',
            'app_id' => '111222',
            'app_secret' => 'page-token',
            'is_active' => true,
            'meta' => [
                'comment_redirect_enabled' => true,
                'reel_caption_enabled' => true,
                'target_reel_ids' => $reels,
                'reel_lease_minutes' => 10,
            ],
        ]);
    }

    private function shortLink(string $code, string $targetUrl, string $name): ShortLink
    {
        return ShortLink::create([
            'code' => $code,
            'target_url' => $targetUrl,
            'target_hash' => sha1($targetUrl),
            'source' => 'kieushopee',
            'product_name' => $name,
        ]);
    }

    /** Caption y như FacebookReelSlotService viết: short-link nằm dòng đầu. */
    private function captionWith(string $code): string
    {
        return url('/go/'.$code)."\n\n🔥 Sản phẩm\n🎟️ Bấm link ở ngay trên để nhận giá đã giảm.";
    }

    /** Caption "thật" trên Facebook của từng reel — test đổi giữa chừng để dựng lệch pha. */
    private array $captions = [];

    private bool $graphDown = false;

    /**
     * Một stub duy nhất cho cả test: POST (đổi caption) luôn thành công, GET (đọc caption) trả
     * theo $captions tại thời điểm gọi. Http::fake gọi nhiều lần sẽ GỘP stub và stub cũ khớp
     * trước, nên không thể "fake lại" ở giữa test — phải đổi trạng thái qua property.
     */
    private function fakeGraph(): void
    {
        Http::fake(function ($request) {
            if ($this->graphDown) {
                return Http::response(['error' => ['message' => 'token expired']], 400);
            }

            if ($request->method() !== 'GET') {
                return Http::response(['success' => true]);
            }

            preg_match('#/(\d+)\?#', $request->url(), $m);

            return Http::response(['id' => $m[1], 'description' => $this->captions[$m[1]] ?? null]);
        });
    }

    public function test_records_which_product_each_reel_is_actually_showing(): void
    {
        $this->config(['reel/111', 'reel/222']);
        $this->shortLink('hood', self::HOODIE_URL, 'Áo Hoodie');
        $this->captions = ['111' => $this->captionWith('hood'), '222' => 'Reel trống, chưa đặt link'];
        $this->fakeGraph();

        $results = app(FacebookReelSyncService::class)->sync();

        $this->assertSame(['updated', 'ok'], array_column($results, 'status'));

        $first = FacebookReelSlot::where('reel_id', '111')->first();
        $this->assertSame('1-100', $first->product_key);
        $this->assertSame(url('/go/hood'), $first->target_url);
        $this->assertSame('Áo Hoodie', $first->product_name);
        $this->assertNotNull($first->synced_at);

        $second = FacebookReelSlot::where('reel_id', '222')->first();
        $this->assertNull($second->product_key);
        $this->assertSame('Reel trống, chưa đặt link', $second->caption);
    }

    /**
     * Bảng nói reel đang thuê cho Áo Hoodie, nhưng Facebook thật lại đang hiện link Quần Jean
     * (ai đó sửa tay). Job phải tin Facebook: đổi sản phẩm VÀ thả lease — giữ lease là khách
     * bấm Áo Hoodie sẽ được đưa tới reel đang hiện Quần Jean.
     */
    public function test_facebook_wins_over_the_table_and_the_stale_lease_is_released(): void
    {
        $config = $this->config(['reel/111']);
        $this->shortLink('hood', self::HOODIE_URL, 'Áo Hoodie');
        $this->shortLink('jean', self::JEAN_URL, 'Quần Jean');

        $this->fakeGraph();
        (new FacebookReelSlotService($config))->reelUrlFor('1-100', 'Áo Hoodie', url('/go/hood'));
        $this->assertTrue(FacebookReelSlot::where('reel_id', '111')->first()->isLeased());

        $this->captions = ['111' => $this->captionWith('jean')];
        app(FacebookReelSyncService::class)->sync();

        $slot = FacebookReelSlot::where('reel_id', '111')->first();
        $this->assertSame('1-200', $slot->product_key);
        $this->assertSame(url('/go/jean'), $slot->target_url);
        $this->assertFalse($slot->isLeased());
    }

    /** Caption đúng như bảng ghi → giữ nguyên lease, không làm phiền khách đang xem. */
    public function test_matching_caption_keeps_the_lease(): void
    {
        $config = $this->config(['reel/111']);
        $this->shortLink('hood', self::HOODIE_URL, 'Áo Hoodie');

        $this->fakeGraph();
        (new FacebookReelSlotService($config))->reelUrlFor('1-100', 'Áo Hoodie', url('/go/hood'));

        $this->captions = ['111' => $this->captionWith('hood')];
        $results = app(FacebookReelSyncService::class)->sync();

        $this->assertSame('ok', $results[0]['status']);
        $this->assertTrue(FacebookReelSlot::where('reel_id', '111')->first()->isLeased());
    }

    /** Graph API lỗi thì không được xoá trạng thái đang có — chỉ ghi lỗi lại. */
    public function test_api_error_keeps_the_record_and_stores_the_error(): void
    {
        $config = $this->config(['reel/111']);
        $this->shortLink('hood', self::HOODIE_URL, 'Áo Hoodie');

        $this->fakeGraph();
        (new FacebookReelSlotService($config))->reelUrlFor('1-100', 'Áo Hoodie', url('/go/hood'));

        $this->graphDown = true;
        $results = app(FacebookReelSyncService::class)->sync();

        $this->assertSame('error', $results[0]['status']);
        $slot = FacebookReelSlot::where('reel_id', '111')->first();
        $this->assertSame('1-100', $slot->product_key);
        $this->assertTrue($slot->isLeased());
        $this->assertStringContainsString('token expired', $slot->sync_error);
    }

    public function test_command_exits_quietly_when_reel_mode_is_not_configured(): void
    {
        Http::fake();

        $this->artisan('facebook:sync-reels')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_command_prints_a_row_per_reel(): void
    {
        $this->config(['reel/111']);
        $this->captions = ['111' => 'trống'];
        $this->fakeGraph();

        $this->artisan('facebook:sync-reels')
            ->expectsOutputToContain('111')
            ->assertSuccessful();
    }
}
