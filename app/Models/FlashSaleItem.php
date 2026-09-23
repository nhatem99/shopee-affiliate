<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Một suất sản phẩm Flash Sale ở một khung giờ cụ thể, hiển thị ở /flashsale.
 *
 * `claim_url` do MÌNH tự dựng lúc đồng bộ (shopid+itemid+mmp_pid của mình), không phải
 * link của nguồn — xem FlashSaleSyncService. Cũng như voucher, link ở đây CHƯA có
 * sub_id của khách; ghép ở FlashSaleService::publicUrlFor() lúc trả response.
 */
#[Fillable([
    'shopid', 'itemid', 'time_slot', 'title', 'image', 'price', 'original_price',
    'percent', 'amount', 'claim_url', 'sync_batch', 'synced_at',
])]
class FlashSaleItem extends Model
{
    protected function casts(): array
    {
        return [
            'shopid' => 'integer',
            'itemid' => 'integer',
            'price' => 'integer',
            'original_price' => 'integer',
            'percent' => 'integer',
            'amount' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function scopeForSlot(Builder $query, string $slot): Builder
    {
        return $slot === 'all' ? $query : $query->where('time_slot', $slot);
    }

    public function scopeSearch(Builder $query, string $keyword): Builder
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $keyword).'%';

        return $query->where('title', 'like', $like);
    }
}
