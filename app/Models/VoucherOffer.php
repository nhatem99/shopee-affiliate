<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Một mã giảm giá toàn sàn trong kho hiển thị ở /ma-giam-gia.
 *
 * `claim_url` đã mang affiliate ID của mình từ lúc đồng bộ, nhưng CHƯA có sub_id của
 * khách đang xem — sub_id chỉ ghép được lúc biết ai đang đọc trang, xem
 * VoucherCatalogService::publicUrlFor().
 */
#[Fillable([
    'platform', 'external_id', 'code', 'title', 'subtitle', 'applies_text',
    'shop_name', 'voucher_image', 'claim_url', 'category', 'usage_percent',
    'usage_text', 'ends_at', 'synced_at',
])]
class VoucherOffer extends Model
{
    protected function casts(): array
    {
        return [
            'ends_at' => 'datetime',
            'synced_at' => 'datetime',
            'usage_percent' => 'integer',
        ];
    }

    /** Mã chưa hết hạn. Không có hạn thì coi như còn dùng được. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function scopeForPlatform(Builder $query, string $platform): Builder
    {
        return $platform === 'all' ? $query : $query->where('platform', $platform);
    }

    /**
     * Tìm theo mã, tiêu đề, mô tả hoặc tên shop — đúng những gì khách nhìn thấy trên thẻ,
     * để gõ chữ trên thẻ nào cũng ra thẻ đó.
     */
    public function scopeSearch(Builder $query, string $keyword): Builder
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $keyword).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('code', 'like', $like)
                ->orWhere('title', 'like', $like)
                ->orWhere('subtitle', 'like', $like)
                ->orWhere('shop_name', 'like', $like);
        });
    }
}
