<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['conversation_id', 'sender_id', 'from_admin', 'body', 'image_path', 'order_id'])]
class ChatMessage extends Model
{
    protected function casts(): array
    {
        return [
            'from_admin' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Hình dạng duy nhất mà cả trang khách (/ho-tro) và trang admin (/admin/chats) cùng đọc.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'from_admin' => $this->from_admin,
            // Giờ trước ngày: trong một hội thoại đang diễn ra thì giờ là thứ cần liếc, ngày chỉ
            // để phân biệt hôm nay với hôm kia.
            'at' => $this->created_at->format('H:i · d/m'),
            // Mốc thô để frontend so với "bên kia đã đọc tới đâu" mà hiện chữ "Đã xem".
            'ts' => $this->created_at->timestamp,
            'image' => $this->image_path ? Storage::disk('uploads')->url($this->image_path) : null,
            'order_id' => $this->order_id,
        ];
    }
}
