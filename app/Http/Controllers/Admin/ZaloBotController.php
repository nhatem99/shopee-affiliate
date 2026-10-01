<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ZaloChat;
use App\Services\ZaloBotService;
use App\Services\ZaloBotSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Trang cấu hình Zalo Bot cho admin: nhập token, chọn ai nhận báo, đặt webhook, gửi thử —
 * thay cho sửa .env và chạy artisan trên VPS.
 */
class ZaloBotController extends Controller
{
    public function __construct(
        private ZaloBotService $bot,
        private ZaloBotSettings $settings,
    ) {}

    public function index(): Response
    {
        $bot = null;
        $webhookUrl = null;
        $error = null;

        if ($this->bot->isConfigured()) {
            try {
                $bot = $this->bot->getMe();
                try {
                    $webhookUrl = $this->bot->getWebhookInfo()['url'] ?? null;
                } catch (RuntimeException) {
                    // getWebhookInfo trả 404 khi chưa từng đặt webhook.
                }
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        $adminIds = $this->settings->adminChatIds();

        return Inertia::render('Admin/ZaloBot', [
            'configured' => $this->bot->isConfigured(),
            'tokenFromEnv' => $this->settings->tokenFromEnv(),
            'adminIdsFromEnv' => $this->settings->adminChatIdsFromEnv(),
            'bot' => $bot ? [
                'name' => $bot['display_name'] ?? null,
                'account' => $bot['account_name'] ?? null,
            ] : null,
            'error' => $error,
            'webhookUrl' => $webhookUrl,
            'expectedWebhookUrl' => route('zalo.webhook'),
            'adminChatIds' => $adminIds,
            'chats' => ZaloChat::orderByDesc('last_message_at')->limit(100)->get()
                ->map(fn (ZaloChat $c) => [
                    'chat_id' => $c->chat_id,
                    'display_name' => $c->display_name,
                    'last_message_at' => $c->last_message_at?->toIso8601String(),
                    'is_admin' => in_array($c->chat_id, $adminIds, true),
                ]),
        ]);
    }

    public function saveToken(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);

        try {
            $me = $this->bot->getMeWithToken($data['token']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['token' => 'Token không dùng được: '.$e->getMessage()]);
        }

        $this->settings->saveToken($data['token']);

        return back()->with('success', 'Đã lưu token bot "'.($me['display_name'] ?? '?').'".');
    }

    public function saveAdmins(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'chat_ids' => ['present', 'array', 'max:20'],
            // Chỉ chọn được người đã nhắn cho bot — bot không gửi được cho ai khác.
            'chat_ids.*' => ['string', 'exists:zalo_chats,chat_id'],
        ]);

        $this->settings->saveAdminChatIds($data['chat_ids']);

        return back()->with('success', 'Đã lưu danh sách admin nhận báo.');
    }

    public function sendTest(): RedirectResponse
    {
        $admins = $this->settings->adminChatIds();
        if (! $admins) {
            return back()->withErrors(['test' => 'Chưa chọn admin nhận báo.']);
        }

        try {
            foreach ($admins as $chatId) {
                // Gửi thẳng, không qua queue: admin cần biết kết quả ngay trên trang.
                $this->bot->sendMessage($chatId, '✅ Tin thử từ trang admin. Bot đang hoạt động.');
            }
        } catch (RuntimeException $e) {
            return back()->withErrors(['test' => $e->getMessage()]);
        }

        return back()->with('success', 'Đã gửi tin thử tới '.count($admins).' admin.');
    }

    public function setWebhook(): RedirectResponse
    {
        $url = route('zalo.webhook');
        if (! str_starts_with($url, 'https://')) {
            return back()->withErrors(['webhook' => "Zalo chỉ nhận webhook HTTPS, địa chỉ hiện tại là {$url}."]);
        }

        try {
            $result = $this->bot->setWebhook($url, $this->settings->ensureWebhookSecret());
        } catch (RuntimeException $e) {
            return back()->withErrors(['webhook' => $e->getMessage()]);
        }

        $verification = $result['verification'] ?? [];
        if (($verification['ok'] ?? true) === false) {
            return back()->withErrors(['webhook' => 'Đã đặt nhưng Zalo gọi thử thất bại: '
                .($verification['outcome'] ?? '?').' — '.($verification['hint'] ?? '')]);
        }

        return back()->with('success', 'Đã đặt webhook. Giờ ai nhắn cho bot sẽ hiện trong danh sách bên dưới.');
    }

    public function deleteWebhook(): RedirectResponse
    {
        try {
            $this->bot->deleteWebhook();
        } catch (RuntimeException $e) {
            return back()->withErrors(['webhook' => $e->getMessage()]);
        }

        return back()->with('success', 'Đã gỡ webhook.');
    }
}
