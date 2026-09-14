<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacebookReelSlot extends Model
{
    protected $fillable = [
        'reel_id',
        'product_key',
        'product_name',
        'target_url',
        'leased_until',
        'caption',
        'synced_at',
        'sync_error',
    ];

    protected $casts = [
        'leased_until' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function isLeased(): bool
    {
        return $this->leased_until !== null && $this->leased_until->isFuture();
    }

    public function url(): string
    {
        return "https://www.facebook.com/reel/{$this->reel_id}";
    }
}
