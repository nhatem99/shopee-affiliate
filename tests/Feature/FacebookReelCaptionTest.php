<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Services\FacebookPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Đổi caption reel qua Graph API — endpoint KHÔNG được Meta tài liệu hoá, nên các test dưới
 * đây chỉ khoá đúng phần mình kiểm soát được: gọi đúng URL/field, và không bao giờ báo thành
 * công khi Facebook từ chối. Việc Facebook có thật sự cho sửa hay không phải đo bằng
 * `php artisan facebook:reel-caption` trên page thật.
 */
class FacebookReelCaptionTest extends TestCase
{
    use RefreshDatabase;

    private function service(): FacebookPageService
    {
        return new FacebookPageService('111222', 'page-token');
    }

    public function test_sends_description_to_the_reel_node(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        $this->assertTrue($this->service()->updateReelCaption('987654', 'Caption mới'));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/987654')
                && ! str_contains($request->url(), '/comments')
                && $request['description'] === 'Caption mới';
        });
    }

    public function test_reports_failure_and_keeps_the_raw_error(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(
            ['error' => ['message' => 'Insufficient permission', 'code' => 200]],
            403,
        )]);

        $service = $this->service();

        $this->assertFalse($service->updateReelCaption('987654', 'Caption mới'));
        // Lệnh chẩn đoán in nguyên văn body này ra để biết thiếu quyền hay endpoint không tồn tại.
        $this->assertStringContainsString('Insufficient permission', $service->lastError);
    }

    /** Graph trả success=false thì phải coi là thất bại, không được nhận nhầm là đổi được. */
    public function test_explicit_success_false_is_a_failure(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => false])]);

        $this->assertFalse($this->service()->updateReelCaption('987654', 'Caption mới'));
    }

    public function test_reads_caption_back_for_verification(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['description' => 'Caption hiện tại'])]);

        $this->assertSame('Caption hiện tại', $this->service()->fetchReelCaption('987654'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fields=description'));
    }

    public function test_lists_reels_from_the_video_reels_edge(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
            ['id' => '555', 'description' => 'Reel A', 'created_time' => '2026-09-01T10:00:00+0000'],
        ]])]);

        $reels = $this->service()->listReels();

        $this->assertSame('555', $reels[0]['id']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/111222/video_reels'));
    }

    /** Page nào Graph không nhận edge /video_reels thì phải tự lùi về /videos, không bỏ cuộc. */
    public function test_falls_back_to_the_videos_edge(): void
    {
        Http::fake([
            'graph.facebook.com/*/video_reels*' => Http::response(['error' => ['message' => 'Unsupported get request']], 400),
            'graph.facebook.com/*/videos*' => Http::response(['data' => [['id' => '777', 'description' => 'Video B']]]),
        ]);

        $reels = $this->service()->listReels();

        $this->assertSame('777', $reels[0]['id']);
    }

    public function test_command_stops_when_facebook_is_not_configured(): void
    {
        $this->artisan('facebook:reel-caption', ['reel' => '987654'])
            ->assertExitCode(1);
    }

    /** Đọc được caption nhưng không truyền --message thì không được gửi lệnh sửa nào. */
    public function test_command_only_reads_without_the_message_option(): void
    {
        ApiConfig::create([
            'name' => 'Facebook',
            'endpoint' => 'https://graph.facebook.com',
            'platform' => 'facebook',
            'app_id' => '111222',
            'app_secret' => 'page-token',
            'is_active' => true,
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['description' => 'Caption hiện tại'])]);

        $this->artisan('facebook:reel-caption', ['reel' => 'https://www.facebook.com/reel/987654'])
            ->assertExitCode(0);

        Http::assertSentCount(1);
    }
}
