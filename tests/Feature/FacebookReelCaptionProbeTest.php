<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Models\User;
use App\Services\FacebookReelSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Nút "Thử ghi" ở /admin/scheduler: ghi lại ĐÚNG caption đang có để biết Meta còn cho đổi
 * caption reel hay không (đường POST /{reel_id} không được Meta tài liệu hoá — xem
 * FacebookPageService::updateReelCaption).
 *
 * Hai thứ phải giữ bằng test: nó KHÔNG được đổi nội dung reel, và nó phải phân biệt được
 * "Meta chặn ghi" với "ID reel sai" — hai ca này admin chữa theo hai cách hoàn toàn khác nhau.
 */
class FacebookReelCaptionProbeTest extends TestCase
{
    use RefreshDatabase;

    private const CAPTION = "https://tietkiemvi.com/go/abc\n\n🔥 Áo Hoodie";

    private function config(array $reels = ['reel/111']): ApiConfig
    {
        return ApiConfig::create([
            'name' => 'Facebook',
            'endpoint' => 'https://graph.facebook.com',
            'platform' => 'facebook',
            'app_id' => '111222',
            'app_secret' => 'page-token',
            'is_active' => true,
            'meta' => ['reel_caption_enabled' => true, 'target_reel_ids' => $reels],
        ]);
    }

    public function test_reports_success_when_facebook_still_accepts_the_write(): void
    {
        $this->config();
        Http::fake(fn ($request) => $request->method() === 'GET'
            ? Http::response(['id' => '111', 'description' => self::CAPTION])
            : Http::response(['success' => true]));

        $result = app(FacebookReelSyncService::class)->probeCaptionWrite('111');

        $this->assertTrue($result['ok']);

        // Điều quan trọng nhất: caption gửi đi phải y hệt caption đọc được, nếu không nút chẩn
        // đoán này tự nó phá nội dung reel đang chạy.
        Http::assertSent(fn ($request) => $request->method() !== 'POST'
            || $request['description'] === self::CAPTION);
    }

    public function test_says_meta_refused_when_the_undocumented_write_endpoint_is_dead(): void
    {
        $this->config();
        Http::fake(fn ($request) => $request->method() === 'GET'
            ? Http::response(['id' => '111', 'description' => self::CAPTION])
            : Http::response(['error' => ['message' => 'An unknown error has occurred.', 'code' => 1]], 400));

        $result = app(FacebookReelSyncService::class)->probeCaptionWrite('111');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('TỪ CHỐI', $result['message']);
        $this->assertStringContainsString('An unknown error has occurred.', $result['message']);
    }

    public function test_separates_a_wrong_reel_id_from_a_refused_write(): void
    {
        $this->config();
        Http::fake(fn () => Http::response(
            ['error' => ['message' => "Unsupported get request. Object with ID '111' does not exist"]],
            400,
        ));

        $result = app(FacebookReelSyncService::class)->probeCaptionWrite('111');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Không ĐỌC được caption', $result['message']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_refuses_reels_outside_the_configured_pool(): void
    {
        $this->config(['reel/111']);
        Http::fake();

        // Không chặn thì đây thành cửa sửa caption bất kỳ object nào token với tới được.
        $result = app(FacebookReelSyncService::class)->probeCaptionWrite('999');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('không nằm trong nhóm reel', $result['message']);
        Http::assertNothingSent();
    }

    public function test_skips_reels_with_no_caption_instead_of_writing_an_empty_one(): void
    {
        $this->config();
        Http::fake(fn () => Http::response(['id' => '111', 'description' => '']));

        $result = app(FacebookReelSyncService::class)->probeCaptionWrite('111');

        $this->assertFalse($result['ok']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_route_is_admin_only(): void
    {
        $this->config();
        Http::fake();

        Role::findOrCreate('user');
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)
            ->post('/admin/scheduler/reel-caption-probe', ['reel_id' => '111'])
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
