<?php

namespace App\Http\Controllers;

use App\Models\ShopeeOrder;
use App\Services\ChatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Chat hỗ trợ phía KHÁCH (/ho-tro). Phía admin ở Admin\ChatController.
 */
class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

    public function index(Request $request): Response
    {
        $this->ensureAvailable($request);

        $conversation = $this->chat->conversationFor($request->user());

        // Mở trang (kể cả nhịp poll với only:['messages','meta']) = đã đọc. Đặt ở đây chứ không ở
        // một route riêng để khỏi thêm một request nữa cho mỗi nhịp poll.
        $this->chat->markReadByUser($conversation);

        // Nhịp poll tự mang theo ?typing=1 khi khách đang gõ dở — xem ChatService::touchTyping.
        if ($request->boolean('typing')) {
            $this->chat->touchTyping($conversation, false);
        }

        return Inertia::render('Chat', [
            'messages' => $this->chat->history($conversation),
            'meta' => $this->chat->meta($conversation, false),
            'context' => $this->orderContext($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureAvailable($request);

        $request->validate([
            'body' => ['nullable', 'string', 'max:'.ChatService::MAX_LENGTH],
            // Chỉ nhận ảnh, và cố ý KHÔNG có svg: svg là XML chạy được script, mà file này rồi sẽ
            // nằm trên chính tên miền của mình.
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ChatService::MAX_IMAGE_KB],
            'order_id' => ['nullable', 'string', 'max:64'],
        ]);

        $body = trim((string) $request->input('body')) ?: null;
        $image = $request->hasFile('image') ? $this->chat->storeImage($request->file('image')) : null;

        // Bắt ở đây chứ không bằng rule required_without: "   " vẫn qua được required_without
        // nhưng sau khi trim thì rỗng, tức gửi lên một tin nhắn trắng.
        if ($body === null && $image === null) {
            throw ValidationException::withMessages(['body' => 'Nhập nội dung hoặc chọn một ảnh.']);
        }

        $this->chat->sendFromUser(
            $request->user(),
            $body,
            $image,
            $this->chat->resolveOrderId($request->user(), $request->input('order_id')),
        );

        return back();
    }

    /**
     * Khách bấm "Hỏi về đơn này" ở /don-hang thì tới đây kèm ?don=<mã đơn> — hiện sẵn một thẻ
     * ghim đơn đó trên ô soạn, để admin không phải hỏi lại "đơn nào bạn ơi".
     *
     * @return array{order_id: string, product_name: string|null}|null
     */
    private function orderContext(Request $request): ?array
    {
        $orderId = $this->chat->resolveOrderId($request->user(), $request->query('don'));

        if ($orderId === null) {
            return null;
        }

        return [
            'order_id' => $orderId,
            'product_name' => ShopeeOrder::where('user_id', $request->user()->id)
                ->where('order_id', $orderId)
                ->value('product_name'),
        ];
    }

    /**
     * Admin có trang riêng để trả lời, vào đây chỉ tổ tự nhắn cho chính mình. Tắt chat ở Cài đặt
     * thì trang này biến mất hẳn, không để lại một trang trống không ai đọc tin.
     */
    private function ensureAvailable(Request $request): void
    {
        abort_if($request->user()->isAdmin(), 403, 'Trang này dành cho khách hàng.');
        abort_unless(ChatService::enabled(), 404);
    }
}
