<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Đăng comment lên bài viết có sẵn trên fanpage Facebook qua Graph API, dùng Page Access
 * Token của chính fanpage (không phải User Access Token). Token phải có quyền
 * pages_manage_engagement trên page tương ứng với pageId.
 */
class FacebookPageService
{
    private const GRAPH_VERSION = 'v21.0';

    /**
     * Nội dung lỗi thô của lần gọi Graph API gần nhất (chỉ các method có set), để lệnh chẩn
     * đoán in ra nguyên văn — thiếu quyền hay sai id nhìn body là biết ngay.
     */
    public ?string $lastError = null;

    public function __construct(
        private readonly string $pageId,
        private readonly string $token,
    ) {}

    /** Dùng cho nút "Kiểm tra kết nối" ở /admin/api-config — gọi thử API không gây tác dụng phụ (không đăng gì). */
    public function testConnection(): bool
    {
        $response = Http::timeout(10)->get(
            'https://graph.facebook.com/'.self::GRAPH_VERSION."/{$this->pageId}",
            [
                'fields' => 'id',
                'access_token' => $this->token,
            ]
        );

        return $response->successful();
    }

    /**
     * Đăng comment rồi trả về ['permalink_url' => ..., 'comment_id' => ...] (null nếu thất bại).
     * comment_id trả về là phần số riêng của comment (không kèm tiền tố post_id) — dùng để
     * ghép URL ?comment_id=... ở ShortLinkController, chính xác hơn permalink_url dạng
     * /{actor_id}/posts/{post_id} với app Facebook trên 1 số thiết bị.
     *
     * Xin luôn `permalink_url` ngay trong response của lệnh POST (Graph API hỗ trợ `fields`
     * trên các endpoint tạo object) để tránh phải gọi thêm 1 request GET riêng — bước GET
     * phụ đó từng là điểm lỗi: comment đăng thành công nhưng gọi tiếp bị timeout/lỗi mạng
     * khiến cả hàm coi như thất bại và fallback nhầm về Shopee dù comment đã lên thật.
     * Nếu vì lý do gì đó vẫn không có permalink_url trong response, thử gọi GET riêng 1 lần;
     * nếu vẫn không có, dùng link dự phòng theo comment id thay vì bỏ cuộc hoàn toàn.
     */
    public function postComment(string $postId, string $message): ?array
    {
        try {
            $response = Http::asForm()->timeout(15)->post(
                'https://graph.facebook.com/'.self::GRAPH_VERSION."/{$postId}/comments",
                [
                    'message' => $message,
                    'fields' => 'id,permalink_url',
                    'access_token' => $this->token,
                ]
            );

            if (! $response->successful()) {
                Log::warning('FacebookPageService: đăng comment thất bại', [
                    'page_id' => $this->pageId,
                    'post_id' => $postId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            // Graph trả id comment dạng {page_id}_{story_fbid}_{comment_id}, nên phần số riêng
            // của comment là đoạn CUỐI. Trước đây cắt bằng explode('_', $id, 2)[1] nên còn dính
            // tiền tố story ("333444_999") — Facebook không nhận giá trị đó, mở link ra chỉ tới
            // bài viết chứ không nhảy xuống đúng bình luận. Lấy đoạn cuối cũng đúng luôn với
            // dạng 2 phần {post_id}_{comment_id}.
            $fullCommentId = $response->json('id');
            $commentId = $fullCommentId ? Str::afterLast($fullCommentId, '_') : null;

            $permalink = $response->json('permalink_url')
                ?? $this->fetchPermalink($fullCommentId)
                ?? ($fullCommentId ? "https://www.facebook.com/{$fullCommentId}" : null);

            if (! $permalink) {
                return null;
            }

            return ['permalink_url' => $permalink, 'comment_id' => $commentId];
        } catch (\Exception $e) {
            Log::warning('FacebookPageService: lỗi khi đăng comment: '.$e->getMessage(), [
                'page_id' => $this->pageId,
                'post_id' => $postId,
            ]);

            return null;
        }
    }

    /**
     * Đổi caption (description) của một reel/video đã đăng: POST /{reel_id} với field
     * `description`.
     *
     * ĐÃ KIỂM CHỨNG NGÀY 2026-09-08 trên page thật: ghi được, đọc lại thấy caption đã đổi.
     * Page Access Token đang dùng cho luồng comment đã đủ quyền, không cần cấp thêm
     * `pages_manage_posts`.
     *
     * Nhưng endpoint này KHÔNG có trong tài liệu Meta — trang "Reels Publishing" chỉ mô tả
     * khởi tạo → upload → publish với `description` đặt lúc publish, còn trang tham chiếu node
     * Video không liệt kê thao tác cập nhật nào. Cái gì không tài liệu hoá thì Meta có thể bỏ
     * bất cứ lúc nào mà không báo. Nên trước mỗi lần dựa vào nó cho một tính năng mới, chạy
     * `php artisan facebook:reel-caption <id> --message="..."` để xác nhận còn sống.
     */
    public function updateReelCaption(string $reelId, string $description): bool
    {
        $this->lastError = null;

        try {
            $response = Http::asForm()->timeout(15)->post(
                'https://graph.facebook.com/'.self::GRAPH_VERSION."/{$reelId}",
                ['description' => $description, 'access_token' => $this->token]
            );

            if (! $response->successful()) {
                $this->lastError = $response->body();

                Log::warning('FacebookPageService: đổi caption reel thất bại', [
                    'reel_id' => $reelId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            // Graph trả {"success": true} khi cập nhật được; một số node trả thẳng object.
            return $response->json('success') ?? true;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::warning('FacebookPageService: lỗi khi đổi caption reel: '.$e->getMessage(), ['reel_id' => $reelId]);

            return false;
        }
    }

    /**
     * Đọc lại caption hiện tại của reel/video — dùng để KIỂM CHỨNG caption đã đổi thật, chứ
     * không tin vào giá trị trả về của lệnh cập nhật.
     */
    public function fetchReelCaption(string $reelId): ?string
    {
        $this->lastError = null;

        try {
            $response = Http::timeout(15)->get(
                'https://graph.facebook.com/'.self::GRAPH_VERSION."/{$reelId}",
                ['fields' => 'description', 'access_token' => $this->token]
            );

            if (! $response->successful()) {
                $this->lastError = $response->body();

                return null;
            }

            return $response->json('description');
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();

            return null;
        }
    }

    /**
     * Liệt kê reels/video của page kèm ID — để lấy Reel ID thật mà không phải mở app copy link
     * từng cái. Reels KHÔNG xuất hiện ở edge /posts nên listRecentPosts() không thấy chúng.
     *
     * Thử /video_reels trước vì đó đúng là surface Reels; page nào Graph không nhận edge đó thì
     * lùi về /videos (edge có tài liệu, reels nằm trong đó cùng các video thường).
     *
     * @return list<array{id: string, description: ?string, permalink_url: ?string, created_time: ?string}>
     */
    public function listReels(int $limit = 25): array
    {
        foreach (['video_reels', 'videos'] as $edge) {
            try {
                $response = Http::timeout(15)->get(
                    'https://graph.facebook.com/'.self::GRAPH_VERSION."/{$this->pageId}/{$edge}",
                    [
                        'fields' => 'id,description,permalink_url,created_time',
                        'limit' => $limit,
                        'access_token' => $this->token,
                    ]
                );

                if ($response->successful()) {
                    $this->lastError = null;

                    return $response->json('data', []);
                }

                $this->lastError = $response->body();
            } catch (\Exception $e) {
                $this->lastError = $e->getMessage();
            }
        }

        Log::warning('FacebookPageService: không liệt kê được reels', [
            'page_id' => $this->pageId,
            'error' => $this->lastError,
        ]);

        return [];
    }

    /** Lấy danh sách bài viết gần đây trên page — dùng cho admin chọn bài để nhận comment. */
    public function listRecentPosts(int $limit = 25): array
    {
        try {
            $response = Http::timeout(10)->get(
                'https://graph.facebook.com/'.self::GRAPH_VERSION."/{$this->pageId}/posts",
                [
                    'fields' => 'id,message,created_time,permalink_url',
                    'limit' => $limit,
                    'access_token' => $this->token,
                ]
            );

            if (! $response->successful()) {
                Log::warning('FacebookPageService: lấy danh sách bài viết thất bại', [
                    'page_id' => $this->pageId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            return $response->json('data') ?? [];
        } catch (\Exception $e) {
            Log::warning('FacebookPageService: lỗi khi lấy danh sách bài viết: '.$e->getMessage(), [
                'page_id' => $this->pageId,
            ]);

            return [];
        }
    }

    private function fetchPermalink(?string $commentId): ?string
    {
        if (! $commentId) {
            return null;
        }

        try {
            $response = Http::timeout(15)->get(
                'https://graph.facebook.com/'.self::GRAPH_VERSION."/{$commentId}",
                ['fields' => 'permalink_url', 'access_token' => $this->token]
            );

            if (! $response->successful()) {
                Log::warning('FacebookPageService: không lấy được permalink comment', [
                    'comment_id' => $commentId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $response->json('permalink_url');
        } catch (\Exception $e) {
            Log::warning('FacebookPageService: không lấy được permalink comment: '.$e->getMessage(), [
                'comment_id' => $commentId,
            ]);

            return null;
        }
    }
}
