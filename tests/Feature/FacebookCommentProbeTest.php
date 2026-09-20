<?php

namespace Tests\Feature;

use App\Models\ApiConfig;
use App\Services\FacebookCommentProbeService;
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

    private function probe(): FacebookCommentProbeService
    {
        return app(FacebookCommentProbeService::class);
    }

    private function fakePostOk(): void
    {
        Http::fake(fn ($request) => $request->method() === 'DELETE'
            ? Http::response(['success' => true])
            : Http::response(['id' => self::COMMENT_ID, 'permalink_url' => 'https://facebook.com/permalink/444']));
    }

    public function test_returns_the_exact_url_a_real_customer_would_get(): void
    {
        $config = $this->config();
        $this->fakePostOk();

        $result = $this->probe()->post($config, self::POST_ID);

        $this->assertTrue($result['ok']);

        // Cả phép thử này chỉ có nghĩa nếu URL trả ra GIỐNG HỆT URL khách nhận. Dạng
        // /{page_id}/posts/{story_fbid}?comment_id={id} là thứ ShortLinkController dựng qua
        // FacebookPostTarget::urlForComment — không phải permalink thô của Graph.
        $this->assertSame(
            'https://www.facebook.com/111222/posts/333?comment_id=444',
            $result['url'],
        );
    }

    public function test_comment_contains_a_real_link_so_clickability_can_be_judged(): void
    {
        $config = $this->config();
        $this->fakePostOk();

        $this->probe()->post($config, self::POST_ID);

        // Không có link trong comment thì mở ra chẳng kiểm chứng được gì — mà "link trong bình
        // luận có bấm được không" mới là câu hỏi đắt nhất, và với reel thì câu trả lời là KHÔNG.
        Http::assertSent(fn ($request) => $request->method() !== 'POST'
            || str_contains((string) $request['message'], url('/')));
    }

    public function test_can_post_the_same_probe_without_a_link(): void
    {
        $config = $this->config();
        $this->fakePostOk();

        $result = $this->probe()->post($config, self::POST_ID, withLink: false);

        // Phép thử hai biến: mọi lệnh ghi thất bại ngày 20-09 đều chứa link, lệnh thành công duy
        // nhất thì không — nên chưa tách được "Meta chặn theo loại bài" khỏi "chặn nội dung có
        // link". Thử cùng một bài theo cả hai kiểu mới tách được.
        Http::assertSent(fn ($request) => $request->method() !== 'POST'
            || ! str_contains((string) $request['message'], url('/')));

        // Và thông báo phải nói rõ vừa thử kiểu nào — đọc kết quả mà không biết mình thử biến
        // nào thì phép thử vô nghĩa.
        $this->assertStringContainsString('KHÔNG link', $result['message']);
    }

    public function test_reel_target_is_posted_to_its_bare_id_and_returns_a_reel_url(): void
    {
        $config = $this->config();
        $this->fakePostOk();

        $result = $this->probe()->post($config, 'https://www.facebook.com/reel/987654');

        // Graph nhận id trần, không nhận cả link: gọi thẳng bằng chuỗi admin nhập thì URL thành
        // "/https://www.facebook.com/reel/987654/comments" và request chết.
        Http::assertSent(fn ($request) => $request->method() !== 'POST'
            || str_contains($request->url(), '/987654/comments'));

        // Và link trả về phải là dạng /reel/ — dạng duy nhất mở được ứng dụng Facebook.
        $this->assertSame('https://www.facebook.com/reel/987654?comment_id=444', $result['url']);
    }

    public function test_keeps_the_comment_so_it_can_be_opened_on_a_real_phone(): void
    {
        $config = $this->config();
        $this->fakePostOk();

        $this->probe()->post($config, self::POST_ID);

        // Không được xoá ngay: câu hỏi "khách mở ra có tới đúng bình luận không" chỉ máy thật
        // trả lời được, mà comment xoá rồi thì không còn gì để mở.
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
        $this->assertNotNull($this->probe()->pending($config));
    }

    public function test_deletes_only_the_remembered_comment_using_its_full_id(): void
    {
        $config = $this->config();
        $this->fakePostOk();
        $this->probe()->post($config, self::POST_ID);

        $result = $this->probe()->delete($config);

        $this->assertTrue($result['ok']);
        $this->assertNull($this->probe()->pending($config));

        // Phải xoá bằng id ĐẦY ĐỦ — Graph không nhận đoạn cuối ("444"), gửi nhầm là comment
        // thử nghiệm nằm lại trên page thật mà admin tưởng đã dọn.
        Http::assertSent(fn ($request) => $request->method() !== 'DELETE'
            || str_contains($request->url(), self::COMMENT_ID));
    }

    public function test_keeps_remembering_the_comment_when_deleting_it_fails(): void
    {
        $config = $this->config();
        Http::fake(fn ($request) => $request->method() === 'DELETE'
            ? Http::response(['error' => ['message' => 'cannot delete']], 400)
            : Http::response(['id' => self::COMMENT_ID, 'permalink_url' => 'https://facebook.com/permalink/444']));

        $this->probe()->post($config, self::POST_ID);
        $result = $this->probe()->delete($config);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('xoá tay', $result['message']);

        // Quên đi là comment nằm lại trên page mà không còn chỗ nào nhắc, và nút xoá cũng mất
        // luôn đối tượng để thử lại.
        $this->assertNotNull($this->probe()->pending($config));
    }

    public function test_reports_failure_with_the_raw_graph_error(): void
    {
        $config = $this->config();
        Http::fake(fn () => Http::response(['error' => ['message' => 'no permission', 'code' => 200]], 403));

        $result = $this->probe()->post($config, self::POST_ID);

        $this->assertFalse($result['ok']);
        $this->assertNull($this->probe()->pending($config));

        // Phải in nguyên văn lỗi Graph, không đẩy admin đi lục /admin/logs: "thiếu quyền" và
        // "Meta chặn hẳn endpoint" dẫn tới hai hướng xử lý khác hẳn nhau.
        $this->assertStringContainsString('no permission', $result['message']);
    }

    public function test_delete_says_so_when_there_is_nothing_pending(): void
    {
        $config = $this->config();
        Http::fake();

        $result = $this->probe()->delete($config);

        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
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
