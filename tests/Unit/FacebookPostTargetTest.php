<?php

namespace Tests\Unit;

use App\Services\FacebookPostTarget;
use PHPUnit\Framework\TestCase;

/**
 * URL cuối cùng mà khách bấm để tới bình luận chứa link sản phẩm. Sai chỗ này thì không có gì
 * báo lỗi: comment vẫn đăng, khách vẫn sang Facebook, chỉ là rơi vào bài viết và phải tự tìm
 * bình luận giữa đống bình luận khác — phần lớn sẽ bỏ cuộc.
 */
class FacebookPostTargetTest extends TestCase
{
    /** Permalink Graph trả về cho comment trên BÀI THƯỜNG — chú ý actor id KHÁC page id. */
    private const GRAPH_PERMALINK = 'https://www.facebook.com/122113725549371579/posts/122116116489371579?comment_id=1803565154397590';

    public function test_normal_post_uses_the_permalink_graph_returns(): void
    {
        // Page có HAI id: Graph dùng 1135866952951524, URL công khai dùng 122113725549371579.
        // Tự ghép bằng id Graph thì Facebook chuyển hướng về URL chuẩn và RỤNG ?comment_id=,
        // khách rơi vào bài chứ không xuống bình luận (đo trên page thật 20-09-2026).
        $url = FacebookPostTarget::urlForComment('1135866952951524_122116116489371579', [
            'comment_id' => '1803565154397590',
            'permalink_url' => self::GRAPH_PERMALINK,
        ]);

        $this->assertSame(self::GRAPH_PERMALINK, $url);
        $this->assertStringNotContainsString('1135866952951524/posts', $url);
    }

    public function test_reel_builds_its_own_url_instead_of_the_permalink(): void
    {
        // Permalink của Graph cho comment trên reel là dạng /posts/, mở ra trang bài viết chứ
        // không phải giao diện Reels — mất luôn cái lợi duy nhất của reel là bật được app.
        $url = FacebookPostTarget::urlForComment('https://www.facebook.com/reel/123456', [
            'comment_id' => '999',
            'permalink_url' => 'https://www.facebook.com/122113725549371579/posts/123456?comment_id=999',
        ]);

        $this->assertSame('https://www.facebook.com/reel/123456?comment_id=999', $url);
    }

    public function test_normal_post_falls_back_to_a_built_url_when_graph_gives_no_permalink(): void
    {
        // Lưới an toàn: không nhảy đúng bình luận, nhưng vẫn tới đúng bài — hơn là trả chuỗi rỗng.
        $url = FacebookPostTarget::urlForComment('111_222', [
            'comment_id' => '333',
            'permalink_url' => '',
        ]);

        $this->assertSame('https://www.facebook.com/111/posts/222?comment_id=333', $url);
    }

    public function test_graph_id_is_what_the_api_is_called_with(): void
    {
        // Reel: gọi Graph bằng id trần, không phải cả link.
        $this->assertSame('123456', FacebookPostTarget::parse('https://www.facebook.com/reel/123456')->graphId);
        $this->assertTrue(FacebookPostTarget::parse('reel/123456')->isReel);

        // Bài thường: giữ nguyên dạng {page_id}_{story_fbid} như Graph trả về.
        $this->assertSame('111_222', FacebookPostTarget::parse('111_222')->graphId);
        $this->assertFalse(FacebookPostTarget::parse('111_222')->isReel);
    }
}
