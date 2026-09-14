<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class VoucherRef extends Model
{
    use Prunable;

    protected $fillable = [
        'ref',
        'url',
        'source_url',
        'ytb_url',
        'source',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Dọn ref đã hết hạn — chạy qua `model:prune` trong lịch (routes/console.php). */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now());
    }
}
