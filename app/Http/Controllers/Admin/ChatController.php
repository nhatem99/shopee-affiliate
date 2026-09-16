<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Services\ChatService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hộp thư hỗ trợ phía ADMIN (/admin/chats): danh sách khách đang nhắn + khung trả lời.
 *
 * Không chặn khi chat đang tắt ở Cài đặt: tắt là khách không gửi thêm được nữa, còn những gì đã
 * nhắn thì vẫn phải đọc và trả lời cho xong.
 */
class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Chats', [
            'conversations' => $this->conversations(),
            'active' => null,
        ]);
    }

    /**
     * Chỉ con số cho badge ở sidebar — xem lý do dùng JSON thay vì partial reload ở routes/web.php.
     */
    public function unread(): JsonResponse
    {
        return response()->json(['unread' => $this->chat->unreadForAdmin()]);
    }

    public function show(Request $request, ChatConversation $conversation): Response
    {
        $this->chat->markReadByAdmin($conversation);

        if ($request->boolean('typing')) {
            $this->chat->touchTyping($conversation, true);
        }

        $conversation->load('user');

        return Inertia::render('Admin/Chats', [
            'conversations' => $this->conversations(),
            'active' => [
                'id' => $conversation->id,
                'user' => [
                    'id' => $conversation->user?->id,
                    'name' => $conversation->user?->name ?? 'Tài khoản đã xoá',
                    'email' => $conversation->user?->email,
                ],
                'messages' => $this->chat->history($conversation),
                'meta' => $this->chat->meta($conversation, true),
            ],
        ]);
    }

    public function reply(Request $request, ChatConversation $conversation): RedirectResponse
    {
        $request->validate([
            'body' => ['nullable', 'string', 'max:'.ChatService::MAX_LENGTH],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ChatService::MAX_IMAGE_KB],
        ]);

        $body = trim((string) $request->input('body')) ?: null;
        $image = $request->hasFile('image') ? $this->chat->storeImage($request->file('image')) : null;

        if ($body === null && $image === null) {
            throw ValidationException::withMessages(['body' => 'Nhập nội dung hoặc chọn một ảnh.']);
        }

        $this->chat->sendFromAdmin($conversation, $request->user(), $body, $image);

        return back();
    }

    /**
     * Danh sách bên trái. Hội thoại có tin mới lên đầu; hội thoại rỗng (khách bấm vào trang chat
     * rồi thoát, chưa gõ gì) bị loại — nó không phải một việc cần làm.
     */
    private function conversations(): LengthAwarePaginator
    {
        return ChatConversation::with(['user', 'latestMessage'])
            ->whereNotNull('last_message_at')
            ->orderByDesc('last_message_at')
            ->paginate(30)
            ->through(fn (ChatConversation $c) => [
                'id' => $c->id,
                'name' => $c->user?->name ?? 'Tài khoản đã xoá',
                'email' => $c->user?->email,
                'unread' => $c->admin_unread,
                'preview' => ($c->latestMessage?->from_admin ? 'Bạn: ' : '').ChatService::preview($c->latestMessage),
                'ago' => $c->last_message_at?->locale('vi')->diffForHumans(),
            ]);
    }
}
