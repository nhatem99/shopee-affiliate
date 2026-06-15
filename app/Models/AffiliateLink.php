<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'original_url', 'short_url', 'platform',
    'product_name', 'product_image', 'original_price', 'discounted_price',
    'discount_percent', 'sold_count', 'rating',
])]
class AffiliateLink extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'original_price' => 'decimal:2',
            'discounted_price' => 'decimal:2',
            'rating' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }
}
