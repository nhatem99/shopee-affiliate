<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Danh sách bài ở /admin/api-config phải tách reel ra khỏi bài viết thường, vì hai nhóm dùng
 * vào hai việc trái ngược nhau: bài thường để ĐĂNG COMMENT (link trong bình luận bấm được),
 * reel để ĐỔI CAPTION (link trong bình luận reel KHÔNG bấm được — xem FacebookPostTarget).
 * Tick nhầm nhóm là khách bấm vào chỗ không làm gì được, nên phần tách nhóm được khoá ở đây.
 */
class AdminFacebookPostListTest extends TestCase
{
    use RefreshDatabase;

    private function config(): ApiConfig
    {
        return ApiConfig::create([
            'name' => 'Facebook',
            'endpoint' => 'https://graph.facebook.com',
            'platform' => 'facebook',
            'app_id' => '111222',
            'app_secret' => 'page-token',
            'is_active' => true,
            'meta' => [],
        ]);
    }

    public function test_returns_reels_and_normal_posts_as_separate_groups(): void
    {
        Http::fake([
            'graph.facebook.com/*/video_reels*' => Http::response(['data' => [
                ['id' => '987654', 'description' => 'Reel giảm giá', 'created_time' => '2026-09-01T10:00:00+0000'],
            ]]),
            'graph.facebook.com/*/posts*' => Http::response(['data' => [
                ['id' => '111222_333444', 'message' => 'Bài viết thường', 'created_time' => '2026-09-02T10:00:00+0000'],
            ]]),
        ]);

        $this->actingAs($this->createAdmin())
            ->getJson("/admin/api-config/{$this->config()->id}/facebook-posts")
            ->assertOk()
            ->assertJsonCount(1, 'posts')
            ->assertJsonCount(1, 'reels')
            ->assertJsonPath('posts.0.id', '111222_333444')
            ->assertJsonPath('reels.0.id', '987654')
            // Graph trả caption reel ở field `description`; phía Vue hiển thị hai nhóm bằng
            // cùng một khuôn nên field phải được đổi tên thành `message`.
            ->assertJsonPath('reels.0.message', 'Reel giảm giá');
    }

    public function test_reel_published_to_the_feed_is_not_listed_twice(): void
    {
        // Reel đăng thẳng lên feed lọt vào cả /posts dưới dạng "{page_id}_{reel_id}". Nếu không
        // lọc thì admin thấy cùng một reel ở hai nhóm và không biết tick cái nào mới đúng.
        Http::fake([
            'graph.facebook.com/*/video_reels*' => Http::response(['data' => [
                ['id' => '987654', 'description' => 'Reel đăng lên feed'],
            ]]),
            'graph.facebook.com/*/posts*' => Http::response(['data' => [
                ['id' => '111222_987654', 'message' => 'Reel đăng lên feed'],
                ['id' => '111222_333444', 'message' => 'Bài viết thường'],
            ]]),
        ]);

        $this->actingAs($this->createAdmin())
            ->getJson("/admin/api-config/{$this->config()->id}/facebook-posts")
            ->assertOk()
            ->assertJsonCount(1, 'posts')
            ->assertJsonPath('posts.0.id', '111222_333444')
            ->assertJsonCount(1, 'reels');
    }

    public function test_still_lists_posts_when_the_page_has_no_reels(): void
    {
        // Token thiếu quyền đọc video_reels/videos là chuyện thường; nhóm bài viết vẫn phải
        // dùng được chứ không kéo nhau chết cả hai.
        Http::fake([
            'graph.facebook.com/*/video_reels*' => Http::response(['error' => ['message' => 'nope']], 403),
            'graph.facebook.com/*/videos*' => Http::response(['error' => ['message' => 'nope']], 403),
            'graph.facebook.com/*/posts*' => Http::response(['data' => [
                ['id' => '111222_333444', 'message' => 'Bài viết thường'],
            ]]),
        ]);

        $this->actingAs($this->createAdmin())
            ->getJson("/admin/api-config/{$this->config()->id}/facebook-posts")
            ->assertOk()
            ->assertJsonCount(1, 'posts')
            ->assertJsonCount(0, 'reels');
    }
}
