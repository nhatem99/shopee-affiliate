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

    /** Đăng comment rồi trả về permalink trỏ thẳng tới comment đó (null nếu thất bại). */
    public function postComment(string $postId, string $message): ?string
    {
        try {
            $response = Http::asForm()->timeout(10)->post(
                'https://graph.facebook.com/'.self::GRAPH_VERSION."/{$postId}/comments",
                [
                    'message' => $message,
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

            return $this->fetchPermalink($response->json('id'));
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

    private function fetchPermalink(?string $commentId): ?string
    {
        if (! $commentId) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get(
                'https://graph.facebook.com/'.self::GRAPH_VERSION."/{$commentId}",
                ['fields' => 'permalink_url', 'access_token' => $this->token]
            );

            return $response->successful() ? $response->json('permalink_url') : null;
        } catch (\Exception $e) {
            Log::warning('FacebookPageService: không lấy được permalink comment: '.$e->getMessage(), [
                'comment_id' => $commentId,
            ]);

            return null;
        }
    }
}
