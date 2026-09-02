<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class BlockedIp extends Model
{
    protected $fillable = [
        'ip_address',
        'note',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('blocked_ips:list'));
        static::deleted(fn () => Cache::forget('blocked_ips:list'));
    }

    /**
     * Danh sách IP bị chặn, cache lại vì middleware đọc bảng này ở MỌI request.
     */
    public static function activeIps(): array
    {
        return Cache::rememberForever('blocked_ips:list', function () {
            return static::pluck('ip_address')->all();
        });
    }
}
