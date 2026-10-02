<?php

namespace App\Services;

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
