<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZaloOaService
{
    // Endpoint theo tài liệu "Gửi tin nhắn nhóm dạng Text" (official-account/nhom-chat-gmf/tin-nhan/text_message).
    // Trang tài liệu Zalo For Developers là SPA nên không tự động xác minh được nội dung —
    // KIỂM TRA LẠI endpoint này trên developers.zalo.me trước khi bật tính năng group reply.
    private const GROUP_MESSAGE_ENDPOINT = 'https://openapi.zalo.me/v3.0/oa/group/message';

    public function sendText(string $userId, string $text): bool
    {
        $token = config('services.zalo.oa_token');

        if (! $token) {
            Log::warning('Zalo OA token not configured, skipping send.');

            return false;
        }

        try {
            $response = Http::withHeaders(['access_token' => $token])
                ->timeout(10)
                ->post('https://openapi.zalo.me/v3.0/oa/message/cs', [
                    'recipient' => ['user_id' => $userId],
                    'message' => ['text' => $text],
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::warning('Zalo OA send error: '.$e->getMessage());

            return false;
        }
    }

    public function sendGroupText(string $groupId, string $text): bool
    {
        $token = config('services.zalo.oa_token');

        if (! $token) {
            Log::warning('Zalo OA token not configured, skipping group send.');

            return false;
        }

        try {
            $response = Http::withHeaders(['access_token' => $token])
                ->timeout(10)
                ->post(self::GROUP_MESSAGE_ENDPOINT, [
                    'recipient' => ['group_id' => $groupId],
                    'message' => ['text' => $text],
                ]);

            if (! $response->successful()) {
                Log::warning('Zalo OA group send failed', [
                    'group_id' => $groupId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            return $response->successful();
        } catch (\Exception $e) {
            Log::warning('Zalo OA group send error: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Xác thực request webhook thực sự đến từ Zalo, chặn request giả mạo khiến
     * OA tự động gửi nội dung không kiểm soát vào nhóm công khai.
     * Công thức: sha256(app_id + rawBody + timestamp + secretKey), so với header X-ZEvent-Signature
     * (dạng "mac=<hex>").
     */
    public function verifyWebhookSignature(string $rawBody, string $timestamp, ?string $signatureHeader): bool
    {
        $appId = config('services.zalo.app_id');
        $secretKey = config('services.zalo.secret_key');

        if (! $appId || ! $secretKey) {
            Log::warning('Zalo webhook rejected: app_id/secret_key chưa cấu hình.');

            return false;
        }

        if (! $signatureHeader) {
            return false;
        }

        $expected = hash('sha256', $appId.$rawBody.$timestamp.$secretKey);
        $provided = str_starts_with($signatureHeader, 'mac=')
            ? substr($signatureHeader, 4)
            : $signatureHeader;

        return hash_equals($expected, $provided);
    }
}
