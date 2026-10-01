<?php

namespace App\Http\Controllers;

use App\Services\ZaloWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nơi Zalo Bot đẩy tin nhắn về. Route nằm NGOÀI nhóm web (xem bootstrap/app.php) nên không đi
 * qua CSRF, GeoBlock (server Zalo không ở VN/JP) và chế độ bảo trì — bảo trì mà chặn webhook
 * thì mất luôn tin khách nhắn trong lúc đó.
 */
class ZaloWebhookController extends Controller
{
    public function __invoke(Request $request, ZaloWebhookService $webhook): JsonResponse
    {
        $secret = (string) config('services.zalo_bot.webhook_secret');

        // Chưa đặt secret = coi như chưa bật webhook; không nhận request "không khoá".
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Bot-Api-Secret-Token'))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $webhook->handle($request->json()->all());

        // Luôn 200 khi secret đúng, kể cả update trùng/không dùng được — trả lỗi chỉ khiến
        // Zalo gửi lại đúng thứ mình đã quyết định bỏ.
        return response()->json(['message' => 'Success']);
    }
}
