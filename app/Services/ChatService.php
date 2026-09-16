<?php

namespace App\Services;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Setting;
use App\Models\ShopeeOrder;
use App\Models\User;
use App\Notifications\AdminReplyNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Chat hỗ trợ khách ↔ admin.
 *
 * Không có WebSocket: cả hai phía tự gọi lại trang mỗi vài giây (router.reload với only:) trong
 * lúc mở trang chat. Đây là chat hỗ trợ một-một, người ta gõ rồi chờ trả lời — độ trễ vài giây
 * không ai nhận ra, mà đổi lại không phải dựng thêm tiến trình nào trên VPS. Ai rời trang thì
 * chuông thông báo lo phần còn lại.
 */
class ChatService
{
    /** Bật/tắt ở Admin → Cài đặt. Tắt thì /ho-tro trả 404 và khách quay về dùng icon Messenger. */
    public const ENABLED_KEY = 'support_chat_enabled';

    public const MAX_LENGTH = 2000;

    /** Ảnh đính kèm: 5MB là thoải mái cho một ảnh chụp màn hình điện thoại. */
    public const MAX_IMAGE_KB = 5120;

    /** Số tin tải về mỗi lần mở hội thoại. Đủ sâu để đọc lại mạch chuyện, không tải cả năm lịch sử. */
    private const HISTORY_LIMIT = 200;

    /**
     * Khách vừa mở trang trong khoảng này thì coi như đang ngồi xem: admin trả lời lúc đó không
     * bắn chuông, vì tin nhắn hiện thẳng trước mắt họ sau nhịp poll kế tiếp.
     */
    private const WATCHING_SECONDS = 60;

    /**
     * "Đang gõ" còn hiệu lực bao lâu kể từ lần cuối bên kia báo. Phải DÀI HƠN nhịp poll (5s bên
     * khách, 10s bên admin) — bằng hoặc ngắn hơn thì chữ "đang gõ" nhấp nháy tắt/bật giữa hai
     * nhịp dù người ta vẫn đang gõ liên tục.
     */
    private const TYPING_TTL_SECONDS = 15;

    /** Mốc "đang gõ"/"đã đọc" chỉ cần chính xác tới hàng chục giây — đừng ghi DB mỗi nhịp poll. */
    private const TOUCH_EVERY_SECONDS = 4;

    private const READ_TOUCH_EVERY_SECONDS = 30;

    public static function enabled(): bool
    {
        return Setting::getBool(self::ENABLED_KEY, true);
    }

    public function conversationFor(User $user): ChatConversation
    {
        return ChatConversation::firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * Lịch sử hội thoại theo thứ tự cũ → mới (đúng thứ tự đọc trên màn hình).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function history(ChatConversation $conversation): Collection
    {
        return $conversation->messages()
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->sortBy('id')
            ->values()
            ->map(fn (ChatMessage $m) => $m->present());
    }

    /**
     * Trạng thái của PHÍA BÊN KIA để vẽ "đang gõ..." và "Đã xem".
     *
     * @return array{peer_typing: bool, peer_read_ts: int|null}
     */
    public function meta(ChatConversation $conversation, bool $forAdmin): array
    {
        $typingAt = $forAdmin ? $conversation->user_typing_at : $conversation->admin_typing_at;
        $readAt = $forAdmin ? $conversation->user_read_at : $conversation->admin_read_at;

        return [
            'peer_typing' => (bool) $typingAt?->gt(now()->subSeconds(self::TYPING_TTL_SECONDS)),
            'peer_read_ts' => $readAt?->timestamp,
        ];
    }

    /**
     * Lưu ảnh đính kèm, trả về đường dẫn tương đối trên disk 'uploads'.
     *
     * store() tự đặt tên ngẫu nhiên: tên file do khách đặt không bao giờ được thành tên file
     * trên server (trùng nhau, lộ thông tin, và là một mặt tấn công không cần thiết).
     */
    public function storeImage(UploadedFile $file): string
    {
        return $file->store('chat/'.now()->format('Y/m'), 'uploads');
    }

    public function sendFromUser(User $user, ?string $body, ?string $imagePath = null, ?string $orderId = null): ChatMessage
    {
        $conversation = $this->conversationFor($user);

        $message = $conversation->messages()->create([
            'sender_id' => $user->id,
            'from_admin' => false,
            'body' => $body,
            'image_path' => $imagePath,
            'order_id' => $orderId,
        ]);

        $conversation->update(['last_message_at' => $message->created_at]);
        $conversation->increment('admin_unread');

        return $message;
    }

    public function sendFromAdmin(ChatConversation $conversation, User $admin, ?string $body, ?string $imagePath = null): ChatMessage
    {
        $message = $conversation->messages()->create([
            'sender_id' => $admin->id,
            'from_admin' => true,
            'body' => $body,
            'image_path' => $imagePath,
        ]);

        $conversation->update(['last_message_at' => $message->created_at]);
        $conversation->increment('user_unread');

        $this->notifyUser($conversation, $message);

        return $message;
    }

    /**
     * Mã đơn khách gửi kèm phải là đơn CỦA CHÍNH HỌ — nếu không thì bỏ qua, không gắn.
     *
     * Không phải chuyện bảo mật to tát (mã đơn không tiết lộ gì), mà là chuyện đúng/sai: một mã
     * lạ gắn vào tin nhắn chỉ khiến admin đi tra một đơn không liên quan.
     */
    public function resolveOrderId(User $user, ?string $orderId): ?string
    {
        if ($orderId === null || $orderId === '') {
            return null;
        }

        $exists = ShopeeOrder::where('user_id', $user->id)
            ->where('order_id', $orderId)
            ->exists();

        return $exists ? $orderId : null;
    }

    /**
     * Khách đang mở trang chat — gọi ở mỗi nhịp poll.
     *
     * Chặn ghi khi không có gì đổi: nhịp poll 5 giây nhân số khách đang mở trang là rất nhiều
     * lượt UPDATE cho một cột mốc thời gian mà chỉ cần chính xác tới hàng chục giây.
     */
    public function markReadByUser(ChatConversation $conversation): void
    {
        if ($conversation->user_unread === 0 && $this->isFresh($conversation->user_read_at)) {
            return;
        }

        $conversation->update(['user_unread' => 0, 'user_read_at' => now()]);
    }

    public function markReadByAdmin(ChatConversation $conversation): void
    {
        if ($conversation->admin_unread === 0 && $this->isFresh($conversation->admin_read_at)) {
            return;
        }

        $conversation->update(['admin_unread' => 0, 'admin_read_at' => now()]);
    }

    /**
     * Báo "đang gõ".
     *
     * Không có request riêng cho việc này: chính nhịp poll mang theo ?typing=1 khi ô soạn đang có
     * chữ. Một tính năng trang trí thì không đáng để thêm một lượt request nào cả — và cũng vì
     * thế TYPING_TTL_SECONDS phải dài hơn nhịp poll.
     */
    public function touchTyping(ChatConversation $conversation, bool $fromAdmin): void
    {
        $column = $fromAdmin ? 'admin_typing_at' : 'user_typing_at';

        if ($conversation->{$column}?->gt(now()->subSeconds(self::TOUCH_EVERY_SECONDS))) {
            return;
        }

        $conversation->update([$column => now()]);
    }

    public function unreadForUser(User $user): int
    {
        return (int) ChatConversation::where('user_id', $user->id)->value('user_unread');
    }

    /**
     * Badge ở sidebar admin: đếm SỐ HỘI THOẠI đang chờ trả lời, không phải số tin nhắn — một
     * khách gõ liền 5 dòng vẫn chỉ là một người đang chờ.
     */
    public function unreadForAdmin(): int
    {
        return ChatConversation::where('admin_unread', '>', 0)->count();
    }

    /**
     * Dòng xem trước trong danh sách admin và trong chuông thông báo của khách.
     */
    public static function preview(?ChatMessage $message, int $width = 60): string
    {
        if (! $message) {
            return '';
        }

        $text = trim((string) $message->body);

        if ($text === '') {
            return $message->image_path ? '📷 Đã gửi một ảnh' : '';
        }

        return mb_strimwidth($text, 0, $width, '…');
    }

    private function isFresh(mixed $timestamp): bool
    {
        return (bool) $timestamp?->gt(now()->subSeconds(self::READ_TOUCH_EVERY_SECONDS));
    }

    /**
     * Chuông thông báo khi admin trả lời.
     *
     * Bỏ qua hai trường hợp, đều vì cùng một lý do — chuông là để KÉO khách quay lại, không phải
     * để tường thuật: khách đang ngồi xem thì không cần kéo, và khi đã có một chuông chưa đọc
     * nằm đó rồi thì admin gõ tiếp 4 dòng nữa cũng không làm nó đáng chú ý hơn.
     */
    private function notifyUser(ChatConversation $conversation, ChatMessage $message): void
    {
        $user = $conversation->user;

        if (! $user) {
            return;
        }

        if ($conversation->user_read_at?->gt(now()->subSeconds(self::WATCHING_SECONDS))) {
            return;
        }

        $alreadyWaiting = $user->unreadNotifications()
            ->where('type', AdminReplyNotification::class)
            ->exists();

        if ($alreadyWaiting) {
            return;
        }

        $user->notify(new AdminReplyNotification(self::preview($message, 120)));
    }
}
