<?php

namespace Tests\Feature;

use App\Exceptions\AffiliateScanException;
use App\Jobs\PrepareFacebookVideo;
use App\Models\ApiConfig;
use App\Models\FacebookVideo;
use App\Services\FacebookDealLink;
use App\Services\FacebookDealLinkBuilder;
use App\Services\FacebookGroupRunnerSettings;
use App\Services\FacebookVideoEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Reel từ video sản phẩm Shopee (/admin/fb-videos): admin dán link → bot điện thoại lấy link video →
 * server tải, cắt, dựng → admin bấm đăng Reel qua Graph API.
 */
class FacebookVideoTest extends TestCase
{
    use RefreshDatabase;

    private const BOT = '1.8.0-node';

    private const SHOPEE_URL = 'https://shopee.vn/product/1562252410/27690212870';

    private const MP4 = 'https://down-bs-sg.vod.susercontent.com/api/v4/11110105/mms/vn-abc.16000081703.mp4';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('local');
        $this->token = app(FacebookGroupRunnerSettings::class)->regenerateToken();
    }

    private function poll(string $version = self::BOT)
    {
        return $this->postJson('/runner/fb/poll', [
            'claim_key' => 'claim-key-000000000001',
            'state' => 'ok',
            'version' => $version,
        ], ['X-Runner-Token' => $this->token]);
    }

    private function report(FacebookVideo $video, string $status, array $videos = [], ?string $error = null)
    {
        return $this->postJson("/runner/fb/videos/{$video->id}/result", [
            'status' => $status,
            'videos' => $videos,
            'error' => $error,
        ], ['X-Runner-Token' => $this->token]);
    }

    private function video(array $attributes = []): FacebookVideo
    {
        return FacebookVideo::create($attributes + [
            'shopee_url' => self::SHOPEE_URL,
            'status' => FacebookVideo::WAITING_BOT,
            'product' => ['product_name' => 'Mũ bảo hiểm Royal M163', 'discounted_price' => 199000],
            'buy_url' => 'https://tietkiemvi.com/go/abc',
            'caption' => "🔥 Mũ bảo hiểm\n👉 Bấm link này để mua có mã giảm giá:\nhttps://tietkiemvi.com/go/abc",
            'overlay_top' => 'Mũ bảo hiểm Royal M163',
            'overlay_bottom' => 'Chỉ còn 199.000đ',
        ]);
    }

    private function facebookConfig(): ApiConfig
    {
        return ApiConfig::create([
            'name' => 'Facebook',
            'endpoint' => 'https://graph.facebook.com',
            'platform' => 'facebook',
            'app_id' => '111222',
            'app_secret' => 'page-token',
            'is_active' => true,
        ]);
    }

    /** Ghi một file giả làm video vào chỗ của nó. */
    private function putFile(FacebookVideo $video, string $kind): void
    {
        $path = $kind === 'source' ? $video->sourcePath() : $video->outputPath();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'fake-mp4-bytes');
    }

    // ── Dán link ─────────────────────────────────────────────────────────────

    public function test_pasting_a_link_builds_the_buy_link_and_waits_for_the_bot(): void
    {
        $this->mock(FacebookDealLinkBuilder::class, fn (MockInterface $mock) => $mock->shouldReceive('build')->once()->andReturn(new FacebookDealLink(
            buyUrl: 'https://tietkiemvi.com/go/abc',
            source: 'ganma',
            canonicalUrl: self::SHOPEE_URL,
            product: ['product_name' => 'Mũ Bảo Hiểm {Royal} M163 🔥', 'discounted_price' => 199000, 'original_price' => 299000, 'discount_percent' => 33],
        )));

        $this->actingAs($this->createAdmin())
            ->post('/admin/fb-videos', ['url' => 'https://s.shopee.vn/abc123'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $video = FacebookVideo::sole();
        $this->assertSame(FacebookVideo::WAITING_BOT, $video->status);
        // Bot mở link sản phẩm đầy đủ, không phải link rút gọn.
        $this->assertSame(self::SHOPEE_URL, $video->shopee_url);
        // Link mua ở ngay đầu caption, trước "Xem thêm".
        $this->assertStringContainsString('https://tietkiemvi.com/go/abc', implode("\n", array_slice(explode("\n", $video->caption), 0, 3)));
        $this->assertStringContainsString('299.000₫', $video->caption);
        // Chữ trên video: không emoji (font VPS không vẽ được), giá có chữ đ.
        $this->assertSame('Mũ Bảo Hiểm {Royal} M163', $video->overlay_top);
        $this->assertSame('Chỉ còn 199.000đ - giảm 33%', $video->overlay_bottom);
    }

    public function test_pasting_a_link_without_voucher_shows_the_reason(): void
    {
        $this->mock(FacebookDealLinkBuilder::class, fn (MockInterface $mock) => $mock->shouldReceive('build')
            ->andThrow(new AffiliateScanException('Sản phẩm này hiện chưa lấy được mã giảm giá, thử lại sau.')));

        $this->actingAs($this->createAdmin())
            ->post('/admin/fb-videos', ['url' => self::SHOPEE_URL])
            ->assertSessionHasErrors(['url' => 'Sản phẩm này hiện chưa lấy được mã giảm giá, thử lại sau.']);

        $this->assertSame(0, FacebookVideo::count());
    }

    public function test_page_lists_videos_and_runner_support(): void
    {
        $this->video();

        $this->actingAs($this->createAdmin())->get('/admin/fb-videos')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/FacebookVideos', false)
                ->has('videos', 1)
                ->where('runner.supported', false)
                ->where('runner.min_version', '1.8.0')
                ->where('facebookReady', false));
    }

    // ── Bot lấy video ───────────────────────────────────────────────────────

    public function test_only_new_bot_gets_the_job_and_only_once_even_when_paused(): void
    {
        $video = $this->video();
        app(FacebookGroupRunnerSettings::class)->pause('Admin tạm dừng');

        $this->assertSame('idle', $this->poll('1.7.3-node')->json('type'));

        $job = $this->poll()->assertOk()->json();
        $this->assertSame(['type' => 'shopee_video', 'video_id' => $video->id, 'url' => self::SHOPEE_URL], $job);
        $this->assertSame(FacebookVideo::FETCHING, $video->fresh()->status);

        $this->assertNotSame('shopee_video', $this->poll()->json('type'));
    }

    public function test_found_video_picks_the_sharpest_shopee_link_and_starts_processing(): void
    {
        Queue::fake();
        $video = $this->video(['status' => FacebookVideo::FETCHING, 'claimed_at' => now()]);
        $small = str_replace('16000081703', '16000051703', self::MP4);

        $this->report($video, 'found', [
            ['url' => $small, 'width' => 360, 'height' => 640, 'duration' => 31],
            ['url' => self::MP4, 'width' => 720, 'height' => 1280, 'duration' => 31],
            ['url' => 'https://evil.example/big.mp4', 'width' => 4000, 'height' => 4000],
        ])->assertOk();

        $video->refresh();
        $this->assertSame(FacebookVideo::PROCESSING, $video->status);
        $this->assertSame(self::MP4, $video->source_url);
        Queue::assertPushed(PrepareFacebookVideo::class, fn ($job) => $job->videoId === $video->id && $job->download);
    }

    public function test_found_with_only_foreign_links_fails(): void
    {
        Queue::fake();
        $video = $this->video(['status' => FacebookVideo::FETCHING, 'claimed_at' => now()]);

        $this->report($video, 'found', [['url' => 'https://evil.example/a.mp4']])->assertOk();

        $this->assertSame(FacebookVideo::FAILED, $video->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_login_required_tells_admin_what_to_do_and_refetch_requeues(): void
    {
        $video = $this->video(['status' => FacebookVideo::FETCHING, 'claimed_at' => now()]);

        $this->report($video, 'login_required')->assertOk();
        $video->refresh();
        $this->assertSame(FacebookVideo::FAILED, $video->status);
        $this->assertStringContainsString('chưa đăng nhập Shopee', $video->error);

        $this->actingAs($this->createAdmin())->post("/admin/fb-videos/{$video->id}/refetch")->assertRedirect();
        $this->assertSame(FacebookVideo::WAITING_BOT, $video->fresh()->status);
        $this->assertSame('shopee_video', $this->poll()->json('type'));
    }

    public function test_no_video_and_late_reports(): void
    {
        $video = $this->video(['status' => FacebookVideo::FETCHING, 'claimed_at' => now()]);

        $this->report($video, 'no_video')->assertOk();
        $this->assertSame(FacebookVideo::NO_VIDEO, $video->fresh()->status);

        // Đã có kết quả rồi thì báo lần nữa bị từ chối.
        $this->report($video, 'found', [['url' => self::MP4]])->assertStatus(409);
        $this->assertSame(FacebookVideo::NO_VIDEO, $video->fresh()->status);
    }

    public function test_silent_bot_claim_fails_after_ten_minutes(): void
    {
        $video = $this->video(['status' => FacebookVideo::FETCHING, 'claimed_at' => now()->subMinutes(11)]);

        $this->poll();

        $this->assertSame(FacebookVideo::FAILED, $video->fresh()->status);
    }

    // ── Tải + dựng ──────────────────────────────────────────────────────────

    public function test_prepare_downloads_source_and_renders(): void
    {
        Http::fake([self::MP4 => Http::response('fake-mp4-bytes')]);
        $video = $this->video(['status' => FacebookVideo::PROCESSING, 'source_url' => self::MP4]);
        $this->mock(FacebookVideoEditor::class, function (MockInterface $mock) {
            $mock->shouldReceive('duration')->andReturn(31.0);
            $mock->shouldReceive('render')->once()->andReturn(28.0);
        });

        PrepareFacebookVideo::dispatchSync($video->id, download: true);

        $video->refresh();
        $this->assertSame(FacebookVideo::READY, $video->status);
        $this->assertTrue($video->has_source);
        $this->assertTrue($video->has_output);
        $this->assertSame(31.0, $video->source_duration);
        $this->assertSame(28.0, $video->output_duration);
        $this->assertSame('fake-mp4-bytes', File::get($video->sourcePath()));
        Http::assertSent(fn (Request $request) => $request->url() === self::MP4 && $request->hasHeader('Referer', 'https://shopee.vn/'));
    }

    public function test_prepare_marks_failed_when_shopee_refuses_the_download(): void
    {
        Http::fake([self::MP4 => Http::response('', 403)]);
        $video = $this->video(['status' => FacebookVideo::PROCESSING, 'source_url' => self::MP4]);

        PrepareFacebookVideo::dispatchSync($video->id, download: true);

        $video->refresh();
        $this->assertSame(FacebookVideo::FAILED, $video->status);
        $this->assertStringContainsString('HTTP 403', $video->error);
        $this->assertFalse($video->has_source);
        $this->assertFileDoesNotExist($video->sourcePath().'.part');
    }

    public function test_failed_rebuild_keeps_the_previous_output_publishable(): void
    {
        $video = $this->video(['status' => FacebookVideo::PROCESSING, 'has_source' => true, 'has_output' => true]);
        $this->mock(FacebookVideoEditor::class, function (MockInterface $mock) {
            $mock->shouldReceive('duration')->andReturn(5.0);
            $mock->shouldReceive('render')->andThrow(new \RuntimeException('Video gốc dài 5.0 giây, cắt ... còn chưa tới 3 giây'));
        });

        PrepareFacebookVideo::dispatchSync($video->id);

        $video->refresh();
        $this->assertSame(FacebookVideo::READY, $video->status);
        $this->assertStringContainsString('chưa tới 3 giây', $video->error);
    }

    public function test_manual_upload_replaces_source_and_renders(): void
    {
        Queue::fake();
        $video = $this->video(['status' => FacebookVideo::FAILED, 'error' => 'Shopee chặn']);
        // Đầu file MP4 thật (hộp ftyp) để kiểm tra mimetype nhận ra là video/mp4.
        $mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64);

        $this->actingAs($this->createAdmin())
            ->post("/admin/fb-videos/{$video->id}/upload", ['video' => UploadedFile::fake()->createWithContent('clip.mp4', $mp4)])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $video->refresh();
        $this->assertSame(FacebookVideo::PROCESSING, $video->status);
        $this->assertTrue($video->has_source);
        $this->assertNull($video->error);
        $this->assertFileExists($video->sourcePath());
        Queue::assertPushed(PrepareFacebookVideo::class, fn ($job) => $job->videoId === $video->id && ! $job->download);
    }

    public function test_upload_rejects_non_video(): void
    {
        $video = $this->video(['status' => FacebookVideo::FAILED]);

        $this->actingAs($this->createAdmin())
            ->post("/admin/fb-videos/{$video->id}/upload", ['video' => UploadedFile::fake()->createWithContent('notes.txt', 'not a video')])
            ->assertSessionHasErrors('video');

        $this->assertFalse($video->fresh()->has_source);
    }

    public function test_editing_overlay_rebuilds_but_caption_only_does_not(): void
    {
        Queue::fake();
        $video = $this->video(['status' => FacebookVideo::READY, 'has_source' => true, 'has_output' => true]);
        $admin = $this->createAdmin();
        $fields = ['caption' => 'Caption mới', 'overlay_top' => $video->overlay_top, 'overlay_bottom' => $video->overlay_bottom, 'trim_start' => 1.5, 'trim_end' => 1.5];

        $this->actingAs($admin)->patch("/admin/fb-videos/{$video->id}", $fields)->assertRedirect();
        $this->assertSame(FacebookVideo::READY, $video->fresh()->status);
        $this->assertSame('Caption mới', $video->fresh()->caption);
        Queue::assertNothingPushed();

        $this->actingAs($admin)->patch("/admin/fb-videos/{$video->id}", ['trim_end' => 3] + $fields)->assertRedirect();
        $this->assertSame(FacebookVideo::PROCESSING, $video->fresh()->status);
        Queue::assertPushed(PrepareFacebookVideo::class);
    }

    public function test_preview_file_is_admin_only(): void
    {
        $video = $this->video(['status' => FacebookVideo::READY, 'has_output' => true]);
        $this->putFile($video, 'output');

        $this->get("/admin/fb-videos/{$video->id}/file/output")->assertRedirect();
        $this->actingAs($this->createAdmin())->get("/admin/fb-videos/{$video->id}/file/output")->assertOk();
    }

    public function test_deleting_removes_files(): void
    {
        $video = $this->video(['status' => FacebookVideo::READY, 'has_source' => true, 'has_output' => true]);
        $this->putFile($video, 'source');
        $this->putFile($video, 'output');

        $this->actingAs($this->createAdmin())->delete("/admin/fb-videos/{$video->id}")->assertRedirect();

        $this->assertSame(0, FacebookVideo::count());
        $this->assertDirectoryDoesNotExist(dirname($video->sourcePath()));
    }

    // ── Đăng Reel ───────────────────────────────────────────────────────────

    public function test_publish_uploads_reel_and_records_link(): void
    {
        $this->facebookConfig();
        $video = $this->video(['status' => FacebookVideo::READY, 'has_output' => true]);
        $this->putFile($video, 'output');
        Http::fake([
            'graph.facebook.com/v21.0/111222/video_reels' => Http::sequence()
                ->push(['video_id' => '987654321', 'upload_url' => 'https://rupload.facebook.com/video-upload/v21.0/987654321'])
                ->push(['success' => true]),
            'rupload.facebook.com/*' => Http::response(['success' => true]),
            'graph.facebook.com/v21.0/987654321*' => Http::response(['status' => ['video_status' => 'ready', 'publishing_phase' => ['status' => 'complete']]]),
        ]);

        $this->actingAs($this->createAdmin())->post("/admin/fb-videos/{$video->id}/publish")->assertRedirect();

        $video->refresh();
        $this->assertSame(FacebookVideo::POSTED, $video->status);
        $this->assertSame('987654321', $video->fb_video_id);
        $this->assertSame('https://www.facebook.com/reel/987654321', $video->permalink);
        $this->assertNull($video->error);
        // Đăng xong thì bỏ file trên VPS.
        $this->assertFalse($video->has_output);
        $this->assertDirectoryDoesNotExist(dirname($video->outputPath()));

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://rupload.facebook.com/')
            && $request->hasHeader('Authorization', 'OAuth page-token')
            && $request->hasHeader('offset', '0')
            && $request->hasHeader('file_size', (string) strlen('fake-mp4-bytes'))
            && $request->body() === 'fake-mp4-bytes');
        Http::assertSent(fn (Request $request) => ($request['upload_phase'] ?? null) === 'finish'
            && $request['video_state'] === 'PUBLISHED'
            && $request['description'] === $video->caption);
    }

    public function test_publish_without_permission_goes_back_to_ready_with_a_hint(): void
    {
        $this->facebookConfig();
        $video = $this->video(['status' => FacebookVideo::READY, 'has_output' => true]);
        $this->putFile($video, 'output');
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => '(#200) The user hasn\'t authorized the application to perform this action', 'code' => 200]], 403),
        ]);

        $this->actingAs($this->createAdmin())->post("/admin/fb-videos/{$video->id}/publish")->assertRedirect();

        $video->refresh();
        $this->assertSame(FacebookVideo::READY, $video->status);
        $this->assertStringContainsString('pages_manage_posts', $video->error);
        $this->assertNull($video->fb_video_id);
    }

    public function test_publish_requires_a_ready_video(): void
    {
        $video = $this->video(['status' => FacebookVideo::PROCESSING]);

        $this->actingAs($this->createAdmin())->post("/admin/fb-videos/{$video->id}/publish")->assertStatus(409);
    }
}
