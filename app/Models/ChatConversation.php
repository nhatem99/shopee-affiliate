<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Một hội thoại hỗ trợ = một khách. Xem migration create_chat_tables để biết vì sao chỉ có một.
 */
#[Fillable([
    'user_id', 'last_message_at', 'user_unread', 'admin_unread',
    'user_read_at', 'admin_read_at', 'user_typing_at', 'admin_typing_at',
])]
class ChatConversation extends Model
{
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'user_read_at' => 'datetime',
            'admin_read_at' => 'datetime',
            'user_typing_at' => 'datetime',
            'admin_typing_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    /**
     * Dòng xem trước trong danh sách của admin — eager load bằng with('latestMessage') để danh
     * sách 30 hội thoại không thành 30 truy vấn.
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class, 'conversation_id')->latestOfMany();
    }
}
