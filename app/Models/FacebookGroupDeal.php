<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Một bài deal admin soạn ở /admin/fb-posts — đăng vào nhiều nhóm, mỗi nhóm một
 * FacebookGroupPost.
 */
#[Fillable(['shopee_url', 'canonical_url', 'source', 'product', 'caption', 'fallback_buy_url', 'fallback_ytb_url', 'created_by'])]
class FacebookGroupDeal extends Model
{
    protected function casts(): array
    {
        return [
            'product' => 'array',
        ];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(FacebookGroupPost::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
