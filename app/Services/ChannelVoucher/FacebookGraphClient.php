<?php

namespace App\Services\ChannelVoucher;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Vỏ mỏng quanh Meta Graph API cho đúng 4 việc mà việc đúc mã cần: đăng comment, đọc lại comment
 * (lấy permalink_url), xoá comment, và kiểm tra token còn sống.
 *
 * Không dùng SDK của Meta: 4 endpoint này chỉ là GET/POST/DELETE thuần, kéo cả SDK về chỉ để gọi
 * chúng là thêm một thứ phải nâng cấp theo mà không được gì.
 */
class FacebookGraphClient
{
    private const TIMEOUT = 15;

    /**
     * Đăng một comment vào post (Facebook) hoặc media (Instagram).
     *
     * @return string|null comment id dạng {object_id}_{comment_id}, null nếu Graph từ chối
     */
    public function comment(string $objectId, string $message, string $accessToken): ?string
    {
        $response = $this->call('post', "{$objectId}/comments", $accessToken, ['message' => $message]);

        return $response?->json('id');
    }

    /**
     * Đọc lại comment vừa đăng. permalink_url là URL tĩnh trỏ thẳng tới comment đó — chính là
     * trang mà trình duyệt sẽ mở để bấm vào link.
     *
     * @return array{id: string, message: ?string, permalink_url: ?string}|null
     */
    public function commentDetails(string $commentId, string $accessToken): ?array
    {
        $response = $this->call('get', $commentId, $accessToken, ['fields' => 'id,message,permalink_url']);

        if (! $response) {
            return null;
        }

        return [
            'id' => $response->json('id', $commentId),
            'message' => $response->json('message'),
            // Comment trên Instagram KHÔNG có permalink_url (Graph API không trả field này cho
            // IG comment) — người gọi phải tự lùi về permalink của media, xem ChannelVoucherMinter.
            'permalink_url' => $response->json('permalink_url'),
        ];
    }

    /**
     * Permalink của media Instagram — đường lùi cho kênh 'ig' vì comment IG không có permalink riêng.
     */
    public function mediaPermalink(string $mediaId, string $accessToken): ?string
    {
        return $this->call('get', $mediaId, $accessToken, ['fields' => 'permalink'])?->json('permalink');
    }

    /**
     * Xoá comment sau khi đã đọc xong link. Link Shopee đã đúc vẫn sống bình thường sau đó —
     * chữ ký nằm trong URL, không phụ thuộc comment còn hay mất.
     */
    public function deleteComment(string $commentId, string $accessToken): bool
    {
        return (bool) $this->call('delete', $commentId, $accessToken)?->json('success', false);
    }

    /**
     * Token còn sống không và đang là ai — dùng cho `php artisan voucher:mint-check`, để phân biệt
     * "token hết hạn" với "post sai ID" thay vì chỉ thấy một lỗi 400 chung chung.
     *
     * @return array{id: string, name: ?string}|null
     */
    public function identity(string $accessToken): ?array
    {
        $response = $this->call('get', 'me', $accessToken, ['fields' => 'id,name']);

        if (! $response || ! $response->json('id')) {
            return null;
        }

        return ['id' => $response->json('id'), 'name' => $response->json('name')];
    }

    private function call(string $method, string $path, string $accessToken, array $params = []): ?Response
    {
        $version = config('services.facebook.graph_version');
        $url = "https://graph.facebook.com/{$version}/{$path}";

        try {
            // Graph API nhận tham số qua query cho MỌI method, kể cả POST/DELETE (đúng như ví dụ
            // curl trong tài liệu của Meta). Đưa hết vào query để không phải đoán xem endpoint nào
            // chịu JSON body, endpoint nào không.
            //
            // Không log $params ở bất kỳ nhánh nào bên dưới — access_token nằm trong đó, lọt vào
            // /admin/logs là coi như token bị lộ.
            /** @var PendingRequest $request */
            $request = Http::timeout(self::TIMEOUT)
                ->withHeaders(['Accept' => 'application/json'])
                ->withQueryParameters($params + ['access_token' => $accessToken]);

            $response = $request->{$method}($url);
        } catch (\Exception $e) {
            Log::error('FacebookGraphClient: lỗi kết nối Graph API', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->successful()) {
            return $response;
        }

        // Graph trả lỗi có cấu trúc; code/subcode mới là thứ tra được, message thường quá chung.
        // Đáng nhớ: 190 = token hỏng/hết hạn, 200 = thiếu quyền, 368 = Page đang bị tạm khoá
        // vì hành vi spam, 613 = vượt rate limit.
        Log::error('FacebookGraphClient: Graph API từ chối', [
            'method' => $method,
            'path' => $path,
            'status' => $response->status(),
            'code' => $response->json('error.code'),
            'subcode' => $response->json('error.error_subcode'),
            'message' => $response->json('error.message'),
        ]);

        return null;
    }
}
