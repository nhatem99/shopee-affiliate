<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'affiliate_link_id', 'code', 'discount_type', 'discount_value',
    'minimum_order', 'expires_at', 'is_freeship', 'source',
])]
class Voucher extends Model
{
    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'minimum_order' => 'decimal:2',
            'expires_at' => 'datetime',
            'is_freeship' => 'boolean',
        ];
    }

    public function affiliateLink(): BelongsTo
    {
        return $this->belongsTo(AffiliateLink::class);
    }
}
