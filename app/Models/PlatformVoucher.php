<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformVoucher extends Model
{
    protected $fillable = [
        'platform',
        'source',
        'code',
        'title',
        'discount_type',
        'discount_value',
        'minimum_order',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'discount_value' => 'float',
        'minimum_order' => 'float',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where(fn ($q) => $q->where('platform', $platform)->orWhere('platform', 'all'));
    }
}
