<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacebookReelSlot extends Model
{
    protected $fillable = [
        'reel_id',
        'product_key',
        'product_name',
        // Mã khách mà caption hiện tại đang phục vụ — null là khách vãng lai. Xem migration
        // 2026_09_25_140000: thiếu nó thì hai khách cùng sản phẩm dùng chung một reel và tiền
        // hoàn chảy vào ví người bấm trước.
        'user_sub_id',
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
