<?php

namespace App\Services;

use App\Exceptions\BridgeDownloadFailedException;
use App\Exceptions\BridgeUrlsNotSupportedException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Gọi cầu nối hermes-zalo-plugin (Node + zca-js) đang giữ phiên nick Zalo cá nhân.
 * Cầu nối đẩy tin đến qua SSE `GET /events` và nhận lệnh gửi qua `POST /send` — xem
 * https://github.com/cuongdev/hermes-zalo-plugin (server.js).
 */
class ZaloPersonalBridge
{
    /**
     * @return array{ok?: bool, loggedIn?: bool, sessionDead?: bool, ownId?: ?string}
     */
    public function health(): array
    {
        return (array) $this->request(5)->get('/health')->throw()->json();
    }

    /**
     * Trạng thái gộp cho trang /admin/zalo-nick: đăng nhập chưa, mã QR đang chờ quét (nếu có),
     * `zalo:group-listen` có đang nghe không. Cầu nối không chạy thì trả reachable=false chứ
     * không ném lỗi — trang gọi lại vài giây một lần, lúc nào cũng phải có cái để hiện.
     *
     * @return array{reachable: bool, error?: string, loggedIn?: bool, sessionDead?: bool,
     *     sessionDeadReason?: ?string, ownId?: ?string, listening?: bool,
     *     qr?: array{status: ?string, image: ?string, scannedBy: ?string}}
     */
    public function status(): array
    {
        try {
            $health = $this->health();
            $qr = (array) $this->request(5)->get('/qr')->throw()->json();
        } catch (Throwable $e) {
            return ['reachable' => false, 'error' => $e->getMessage()];
        }

        // generating | waiting_scan | scanned | expired | declined | logged_in | none
        // (zaloClient.js::login). Ảnh chỉ còn giá trị lúc đang chờ quét.
        $qrStatus = $qr['status'] ?? null;

        return [
            'reachable' => true,
            'loggedIn' => ! empty($health['loggedIn']) && empty($health['sessionDead']),
            'sessionDead' => ! empty($health['sessionDead']),
            'sessionDeadReason' => $health['sessionDeadReason'] ?? null,
            'ownId' => $health['ownId'] ?? null,
            // Mỗi `zalo:group-listen` đang chạy là một kết nối SSE tới cầu nối.
            'listening' => ($health['sseClients'] ?? 0) > 0,
            'qr' => [
                'status' => $qrStatus,
                'image' => $qrStatus === 'waiting_scan' && ! empty($qr['image'])
                    ? 'data:image/png;base64,'.$qr['image']
                    : null,
                'scannedBy' => $qr['displayName'] ?? null,
            ],
        ];
    }

    /**
     * Bỏ phiên hiện tại (nếu có) và bắt đầu đăng nhập bằng mã QR mới. Cầu nối trả lời ngay,
     * mã QR xuất hiện ở status() sau một hai giây và tự đổi mã mới khi mã cũ hết hạn.
     */
    public function relogin(): void
    {
        $this->request(10)->post('/relogin', ['forceQR' => true])->throw();
    }

    /**
     * Mở luồng SSE. Không giới hạn tổng thời gian: cầu nối gửi "ping" mỗi 15 giây, nên
     * 60 giây không có byte nào là kết nối đã chết — read_timeout cắt để vòng ngoài nối lại.
     */
    public function openEvents(int $lastEventId = 0): Response
    {
        $request = $this->request(0)->withOptions(['stream' => true, 'read_timeout' => 60]);
        if ($lastEventId > 0) {
            $request = $request->withHeaders(['Last-Event-ID' => (string) $lastEventId]);
        }

        return $request->get('/events')->throw();
    }

    /**
     * @param  array<string, mixed>|null  $quote  nguyên trường `quote` của tin đến — Zalo hiện
     *                                            câu trả lời dạng trích đúng tin đó
     * @param  list<array{start: int, len: int, st: string}>  $styles
     */
    public function sendText(string $threadId, string $threadType, string $text, ?array $quote = null, array $styles = []): void
    {
        try {
            $response = $this->request(20)->post('/send', array_filter([
                'threadId' => $threadId,
                'threadType' => $threadType,
                'text' => $text,
                'quote' => $quote,
                // Cầu nối tự đổi markdown (*, __, ~~...) sang kiểu chữ khi KHÔNG có styles —
                // tên sản phẩm có dấu * là mất chữ. Luôn gửi styles để tắt bước đó.
                'styles' => $styles ?: null,
            ], fn ($v) => $v !== null));
        } catch (Throwable $e) {
            throw new RuntimeException('Cầu nối Zalo /send: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Cầu nối Zalo /send lỗi [{$response->status()}]: ".($response->json('error') ?? $response->body()));
        }
    }

    /**
     * Danh sách nhóm mà nick đang tham gia. Dùng để admin chọn nhóm nguồn / nhóm đích cho
     * tính năng mirror mà không cần nhớ id nhóm.
     *
     * @return list<array{id: string, name: string}>
     */
    public function groups(): array
    {
        try {
            $response = $this->request(30)->get('/contacts')->throw();
        } catch (Throwable $e) {
            throw new RuntimeException('Cầu nối Zalo /contacts: '.$e->getMessage(), 0, $e);
        }

        $groups = $response->json('groups');

        return is_array($groups) ? $groups : [];
    }

    /**
     * Danh sách id admin của một nhóm (chủ nhóm + admin được chỉ định). Dùng cho adminsOnly
     * của tính năng mirror: chỉ đăng lại bài của admin nhóm nguồn, bỏ qua thành viên thường.
     *
     * @return list<string>
     */
    public function groupAdmins(string $groupId): array
    {
        try {
            $response = $this->request(10)
                ->get('/chat-info', ['threadId' => $groupId, 'threadType' => 'group'])
                ->throw();
        } catch (Throwable $e) {
            throw new RuntimeException("Cầu nối Zalo /chat-info [{$groupId}]: ".$e->getMessage(), 0, $e);
        }

        $info = data_get($response->json(), "info.gridInfoMap.{$groupId}");

        // Cầu nối swallow mọi lỗi Zalo (rate-limit backoff, getGroupInfo null) và trả HTTP 200
        // với info: null. Ném lỗi thay vì trả [] — caller phải phân biệt "không có admin"
        // (nhóm thực sự trống, không thể xảy ra) với "chưa lấy được" (lỗi tạm thời).
        if (! is_array($info)) {
            throw new RuntimeException("Cầu nối Zalo /chat-info [{$groupId}]: info.gridInfoMap[{$groupId}] null — cầu nối đang rate-limit hoặc lỗi nội bộ");
        }

        $creatorId = data_get($info, 'creatorId');
        $adminIds = (array) data_get($info, 'adminIds', []);

        $all = array_filter(
            array_unique(array_merge($creatorId ? [(string) $creatorId] : [], array_map('strval', $adminIds))),
            fn (string $id) => $id !== '',
        );

        // Mọi nhóm đều có chủ nhóm — danh sách rỗng nghĩa là bridge trả dữ liệu không đủ.
        if (empty($all)) {
            throw new RuntimeException("Cầu nối Zalo /chat-info [{$groupId}]: gridInfoMap[{$groupId}] không có creatorId — cầu nối trả dữ liệu thiếu");
        }

        return array_values($all);
    }

    /**
     * Gửi một hoặc nhiều ảnh qua URL. Cầu nối mới hỗ trợ trường `urls` (tự tải về file tạm) —
     * cầu nối cũ trả 400 "threadId and paths required" vì chỉ biết nhận đường dẫn file cục bộ.
     *
     * Ném BridgeUrlsNotSupportedException khi cầu nối cũ chưa nâng cấp — caller tự xử lý fallback.
     *
     * @param  list<string>  $urls
     */
    public function sendImages(string $threadId, string $threadType, array $urls, ?string $caption = null): void
    {
        try {
            $response = $this->request(120)->post('/send-attachment', array_filter([
                'threadId' => $threadId,
                'threadType' => $threadType,
                'urls' => $urls,
                'caption' => $caption,
            ], fn ($v) => $v !== null));
        } catch (Throwable $e) {
            throw new RuntimeException('Cầu nối Zalo /send-attachment: '.$e->getMessage(), 0, $e);
        }

        // Cầu nối cũ không có trường 'urls' — lỗi 400 với nội dung nhắc tới 'paths'.
        if ($response->status() === 400 && str_contains((string) $response->body(), 'paths')) {
            throw new BridgeUrlsNotSupportedException(
                'bridge chưa hỗ trợ gửi ảnh qua URL — cần nâng cấp cầu nối lên phiên bản mới nhất'
            );
        }

        // 422 = cầu nối mới nhưng ít nhất một URL ảnh không tải được (404, quá lớn, timeout...).
        // Lỗi này xác định — retry cùng URL sẽ cho cùng kết quả — fallback về text-only.
        if ($response->status() === 422) {
            throw new BridgeDownloadFailedException(
                'cầu nối không tải được ảnh — '.($response->json('error') ?? $response->body())
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException("Cầu nối Zalo /send-attachment lỗi [{$response->status()}]: ".($response->json('error') ?? $response->body()));
        }
    }

    private function request(int $timeout): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('services.zalo_personal.bridge_url'), '/'))
            ->timeout($timeout)
            ->connectTimeout(5)
            ->acceptJson();

        $token = (string) config('services.zalo_personal.token');

        return $token === '' ? $request : $request->withHeaders(['x-bridge-token' => $token]);
    }
}
