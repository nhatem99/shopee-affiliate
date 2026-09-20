<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Services\FacebookPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Nút "Thử comment" ở /admin/api-config và bộ lọc reel khỏi danh sách bài viết.
 *
 * Hai thứ này cùng phục vụ một việc: sau khi Meta đóng đường đổi caption reel (19-09-2026),
 * comment dưới BÀI VIẾT THƯỜNG là đường duy nhất còn lại để khách đi qua Facebook. Chọn nhầm
 * một reel vào ô bài viết thì comment vẫn đăng thành công, khách vẫn sang Facebook, chỉ là tới
 * nơi link BẤM KHÔNG ĐƯỢC — hỏng âm thầm, không có lỗi nào để lần ra.
 */
class FacebookCommentProbeTest extends TestCase
{
    use RefreshDatabase;

    private const POST_ID = '111222_333';

    private const COMMENT_ID = '111222_333_444';

    private function config(): ApiConfig
    {
        return ApiConfig::create([
            'name' => 'Facebook',
            'endpoint' => 'https://graph.facebook.com',
            'platform' => 'facebook',
            'app_id' => '111222',
            'app_secret' => 'page-token',
            'is_active' => true,
            'meta' => ['comment_redirect_enabled' => true],
        ]);
    }

    private function service(): FacebookPageService
    {
        $config = $this->config();

        return new FacebookPageService($config->app_id, $config->app_secret);
    }

    public function test_posts_a_comment_then_deletes_it(): void
    {
        Http::fake(fn ($request) => $request->method() === 'DELETE'
            ? Http::response(['success' => true])
            : Http::response(['id' => self::COMMENT_ID, 'permalink_url' => 'https://facebook.com/c/444']));

        $result = $this->service()->probeComment(self::POST_ID);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('đã xoá sạch', $result['message']);

        // Phải xoá bằng id ĐẦY ĐỦ — Graph không nhận đoạn cuối ("444"), gửi nhầm là comment
        // thử nghiệm nằm lại trên page thật mà admin tưởng đã dọn.
        Http::assertSent(fn ($request) => $request->method() !== 'DELETE'
            || str_contains($request->url(), self::COMMENT_ID));
    }

    public function test_warns_loudly_when_the_comment_was_posted_but_could_not_be_deleted(): void
    {
        Http::fake(fn ($request) => $request->method() === 'DELETE'
            ? Http::response(['error' => ['message' => 'cannot delete']], 400)
            : Http::response(['id' => self::COMMENT_ID, 'permalink_url' => 'https://facebook.com/c/444']));

        $result = $this->service()->probeComment(self::POST_ID);

        // Vẫn là ok=true vì câu hỏi "đường comment còn sống không" đã có đáp án, nhưng phải nói
        // rõ còn một comment thật nằm lại — nuốt chuyện này là để rác trên page của khách.
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('xoá không được', $result['message']);
        $this->assertStringContainsString('https://facebook.com/c/444', $result['message']);
    }

    public function test_reports_failure_when_facebook_refuses_the_comment(): void
    {
        Http::fake(fn () => Http::response(['error' => ['message' => 'no permission']], 403));

        $result = $this->service()->probeComment(self::POST_ID);

        $this->assertFalse($result['ok']);
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_video_posts_are_kept_out_of_the_normal_post_list(): void
    {
        $config = $this->config();

        Http::fake([
            '*/video_reels*' => Http::response(['data' => [['id' => '999', 'description' => 'reel']]]),
            '*/posts*' => Http::response(['data' => [
                // Post id của reel là {page_id}_{story_id}: story_id KHÔNG bằng video id, nên
                // bộ lọc theo id trước đây không loại được gì. Chỉ media_type phân biệt nổi.
                ['id' => '111222_888', 'message' => 'Reel lọt vào feed', 'attachments' => ['data' => [['media_type' => 'video']]]],
                ['id' => '111222_777', 'message' => 'Ảnh sản phẩm', 'attachments' => ['data' => [['media_type' => 'photo']]]],
                ['id' => '111222_666', 'message' => 'Bài chỉ có chữ'],
            ]]),
        ]);

        $this->actingAs($this->createAdmin())
            ->getJson("/admin/api-config/{$config->id}/facebook-posts")
            ->assertOk()
            ->assertJsonPath('posts.0.id', '111222_777')
            ->assertJsonPath('posts.1.id', '111222_666')
            ->assertJsonCount(2, 'posts');
    }

    public function test_probe_route_rejects_a_non_facebook_config(): void
    {
        $config = ApiConfig::create([
            'name' => 'Kieu',
            'endpoint' => 'https://sansale.kieushopee.com/22',
            'platform' => 'kieushopee',
            'app_secret' => 'khong-lien-quan',
            'is_active' => true,
        ]);

        Http::fake();

        $this->actingAs($this->createAdmin())
            ->postJson("/admin/api-config/{$config->id}/probe-comment", ['post_id' => self::POST_ID])
            ->assertStatus(422);

        Http::assertNothingSent();
    }
}
