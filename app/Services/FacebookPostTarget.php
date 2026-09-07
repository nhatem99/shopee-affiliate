<?php

namespace App\Services;

/**
 * Một bài viết đích trong nhóm bài nhận comment, phân giải từ chuỗi admin nhập ở
 * /admin/api-config (meta.target_post_ids).
 *
 * Chấp nhận hai dạng vì URL công khai của chúng khác hẳn nhau:
 *
 *  • REEL — dán link reel ("https://www.facebook.com/reel/123456" hoặc "reel/123456").
 *    URL công khai là /reel/{id}.
 *    ĐÃ ĐO TRÊN MÁY THẬT, KẾT QUẢ TRÁI CHIỀU: link /reel/ MỞ ĐƯỢC ứng dụng Facebook (iPhone
 *    còn nhảy đúng tới bình luận, Android chỉ dừng ở video) — nhưng link NẰM TRONG bình luận
 *    của reel thì KHÔNG bấm được, Facebook hiển thị nó thành chuỗi text thường. Kiểm chứng
 *    bằng cách tự tay comment một link khác cũng vậy, nên đây là hành vi của giao diện bình
 *    luận trong Reels chứ không phải do domain hay do đăng qua Graph API.
 *    => Reel KHÔNG dùng được cho luồng ĐĂNG COMMENT: khách sang tới nơi nhưng không bấm được.
 *    Vẫn giữ code xử lý reel để nếu Facebook đổi hành vi (hoặc Page mua Meta Verified, vốn
 *    cho phép link native trong Reels) thì chỉ cần đổi cấu hình, không phải sửa lại code.
 *
 *    Lưu ý riêng: CAPTION của reel thì sửa được sau khi đăng qua Graph API (đã kiểm chứng
 *    2026-09-08 — xem FacebookPageService::updateReelCaption), mở ra hướng khác hẳn: đổi
 *    caption theo từng khách thay vì đăng comment. Hướng đó chỉ chạy được nếu link trong
 *    caption reel bấm được — chưa kiểm chứng.
 *
 *  • BÀI VIẾT THƯỜNG — "{page_id}_{story_fbid}" đúng như Graph API trả về.
 *    URL công khai là /{page_id}/posts/{story_fbid}. Thường mở bằng trình duyệt chứ không bật
 *    app, đổi lại link trong bình luận bấm được — đây chính là cách "link in first comment"
 *    mà Facebook khuyến nghị.
 *
 * Cả hai đều nhận comment qua cùng một endpoint POST /{graphId}/comments, chỉ khác cách ghép
 * URL trả cho khách.
 */
final readonly class FacebookPostTarget
{
    private function __construct(
        /** Id dùng để gọi Graph API: POST /{graphId}/comments */
        public string $graphId,
        /** URL công khai của bài, hoặc null nếu không nhận dạng được dạng nhập. */
        public ?string $canonicalUrl,
    ) {}

    public static function parse(string $raw): self
    {
        $raw = trim($raw);

        if (preg_match('#(?:^|/)reel/(\d+)#', $raw, $matches)) {
            return new self($matches[1], "https://www.facebook.com/reel/{$matches[1]}");
        }

        if (preg_match('#^(\d+)_(\d+)$#', $raw, $matches)) {
            return new self($raw, "https://www.facebook.com/{$matches[1]}/posts/{$matches[2]}");
        }

        // Dạng lạ (id đơn, link rút gọn fb.watch, /share/r/...): vẫn đăng comment được nếu
        // Graph API hiểu id đó, nhưng mình không tự dựng được URL công khai — người gọi rơi về
        // permalink_url mà Graph trả kèm.
        return new self($raw, null);
    }

    /**
     * URL trỏ tới đúng bình luận trong bài, hoặc null nếu không dựng được (thiếu comment id
     * hoặc dạng nhập lạ).
     */
    public function commentUrl(?string $commentId): ?string
    {
        if (! $this->canonicalUrl || ! $commentId) {
            return null;
        }

        return $this->canonicalUrl.'?'.http_build_query(['comment_id' => $commentId]);
    }
}
