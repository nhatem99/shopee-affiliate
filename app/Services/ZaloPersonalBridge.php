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
