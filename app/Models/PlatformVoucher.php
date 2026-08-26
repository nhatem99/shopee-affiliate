<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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

    protected static function booted(): void
    {
        // Xoá cache mỗi khi voucher thay đổi để admin sửa/xoá thấy hiệu lực ngay,
        // không phải đợi hết 60s cache của suggestedList().
        static::saved(fn () => Cache::forget('platform_vouchers:suggested:12'));
        static::deleted(fn () => Cache::forget('platform_vouchers:suggested:12'));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where(fn ($q) => $q->where('platform', $platform)->orWhere('platform', 'all'));
    }

    /**
     * Danh sách voucher gợi ý hiển thị ở trang chủ/kết quả — giống nhau cho mọi
     * người xem nên cache ngắn hạn được, tránh query lặp lại trên mỗi request.
     */
    public static function suggestedList(int $limit = 12): array
    {
        return Cache::remember("platform_vouchers:suggested:{$limit}", 60, function () use ($limit) {
            return static::active()->latest()->take($limit)->get()->map(fn ($v) => [
                'id' => $v->id,
                'platform' => $v->platform,
                'source' => $v->source,
                'code' => $v->code,
                'title' => $v->title,
                'discount_type' => $v->discount_type,
                'discount_value' => $v->discount_value,
                'minimum_order' => $v->minimum_order,
                'expires_at' => $v->expires_at?->toIso8601String(),
            ])->toArray();
        });
    }
}
