<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Đăng comment lên bài viết có sẵn trên fanpage Facebook qua Graph API, dùng Page Access
 * Token của chính fanpage (không phải User Access Token). Token phải có quyền
 * pages_manage_engagement trên page tương ứng với pageId.
 */
class FacebookPageService
{
    private const GRAPH_VERSION = 'v21.0';

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
     * Đăng comment rồi trả về permalink trỏ thẳng tới comment đó (null nếu thất bại).
     *
     * Xin luôn `permalink_url` ngay trong response của lệnh POST (Graph API hỗ trợ `fields`
     * trên các endpoint tạo object) để tránh phải gọi thêm 1 request GET riêng — bước GET
     * phụ đó từng là điểm lỗi: comment đăng thành công nhưng gọi tiếp bị timeout/lỗi mạng
     * khiến cả hàm coi như thất bại và fallback nhầm về Shopee dù comment đã lên thật.
     * Nếu vì lý do gì đó vẫn không có permalink_url trong response, thử gọi GET riêng 1 lần;
     * nếu vẫn không có, dùng link dự phòng theo comment id thay vì bỏ cuộc hoàn toàn.
     */
    public function postComment(string $postId, string $message): ?string
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

            $commentId = $response->json('id');

            if ($permalink = $response->json('permalink_url')) {
                return $this->toMobileUrl($permalink);
            }

            $fallback = $this->fetchPermalink($commentId) ?? ($commentId ? "https://www.facebook.com/{$commentId}" : null);

            return $this->toMobileUrl($fallback);
        } catch (\Exception $e) {
            Log::warning('FacebookPageService: lỗi khi đăng comment: '.$e->getMessage(), [
                'page_id' => $this->pageId,
                'post_id' => $postId,
            ]);

            return null;
        }
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

    /**
     * Đổi www.facebook.com -> m.facebook.com — bản mobile web ít bị app Facebook "cướp" quyền
     * mở link hơn (Universal Link/App Link deep-vào-đúng-comment của app rất hay không ổn định,
     * nhiều khi chỉ mở app ra trang chủ chứ không tới đúng bài/comment). m. vẫn tự cuộn đúng
     * tới comment khi mở bằng trình duyệt.
     */
    private function toMobileUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        return preg_replace('#^https://www\.facebook\.com/#', 'https://m.facebook.com/', $url);
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
