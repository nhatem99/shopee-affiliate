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

    /** Caption vừa được GHI lên Facebook — stub đọc lại trả đúng nó, y như Graph thật. */
    private ?string $sentCaption = null;

    private function recordSentCaption($request): mixed
    {
        $this->sentCaption = (string) $request['description'];

        return Http::response(['success' => true]);
    }

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

    public function test_link_free_probe_sends_a_caption_with_no_link_and_remembers_the_original(): void
    {
        $this->config();
        Http::fake(fn ($request) => $request->method() === 'GET'
            ? Http::response(['id' => '111', 'description' => $this->sentCaption ?? self::CAPTION])
            : $this->recordSentCaption($request));

        $result = app(FacebookReelSyncService::class)->probeCaptionWrite('111', withLink: false);

        $this->assertTrue($result['ok']);

        // Caption gửi đi phải sạch link HOÀN TOÀN. Bỏ mỗi "https://" là chưa đủ — Facebook nhận
        // ra "tietkiemvi.com" không cần scheme đứng trước, nên phép thử sẽ vẫn dính biến cũ.
        $this->assertStringNotContainsString('tietkiemvi', (string) $this->sentCaption);
        $this->assertStringNotContainsString('http', (string) $this->sentCaption);

        // Và caption gốc phải được giữ TRƯỚC khi báo thành công: admin đóng tab ngay sau đó thì
        // reel đang mang caption kiểm tra kỹ thuật mà không còn gì để lần về nội dung cũ.
        $this->assertSame(self::CAPTION, app(FacebookReelSyncService::class)->captionBackup('111'));
    }

    public function test_restore_puts_the_original_caption_back(): void
    {
        $this->config();
        Http::fake(fn ($request) => $request->method() === 'GET'
            ? Http::response(['id' => '111', 'description' => $this->sentCaption ?? self::CAPTION])
            : $this->recordSentCaption($request));

        $reels = app(FacebookReelSyncService::class);
        $reels->probeCaptionWrite('111', withLink: false);

        $result = $reels->restoreCaption('111');

        $this->assertTrue($result['ok']);
        $this->assertSame(self::CAPTION, $this->sentCaption);
        $this->assertNull($reels->captionBackup('111'));
    }

    public function test_restore_keeps_the_backup_when_facebook_refuses(): void
    {
        $this->config();
        $this->sentCaption = null;
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['id' => '111', 'description' => $this->sentCaption ?? self::CAPTION]);
            }

            // Ca dễ xảy ra nhất: caption gốc chứa link, mà link mới là thứ đang bị chặn.
            if (str_contains((string) $request['description'], 'tietkiemvi')) {
                return Http::response(['error' => ['message' => 'An unknown error has occurred.', 'code' => 1]], 500);
            }

            return $this->recordSentCaption($request);
        });

        $reels = app(FacebookReelSyncService::class);
        $reels->probeCaptionWrite('111', withLink: false);

        $result = $reels->restoreCaption('111');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sửa tay', $result['message']);

        // Giữ bản lưu thì admin còn bấm lại được và trang còn hiện cảnh báo; quên đi là reel
        // nằm lại với caption kiểm tra kỹ thuật mà không còn chỗ nào nhắc.
        $this->assertSame(self::CAPTION, $reels->captionBackup('111'));
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
