<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một DÒNG SẢN PHẨM trong báo cáo hoa hồng affiliate Shopee — không phải một đơn hàng.
 *
 * Đơn nhiều sản phẩm chiếm nhiều dòng cùng order_id, và Shopee dồn toàn bộ hoa hồng cấp đơn vào
 * dòng đầu (dòng sau bằng 0). Muốn số tiền của một ĐƠN thì phải gộp theo order_id — xem
 * scopeCompleted() + ShopeeReportImportService::syncCommissions().
 */
#[Fillable([
    'order_id', 'item_id', 'model_id', 'checkout_id',
    'shop_id', 'shop_name', 'product_name', 'quantity', 'price', 'order_value',
    'net_commission', 'total_order_commission',
    'order_status_raw', 'status',
    'sub_id_raw', 'user_sub_id', 'user_id',
    'channel', 'content_type',
    'clicked_at', 'ordered_at', 'completed_at',
])]
class ShopeeOrder extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price' => 'decimal:2',
            'order_value' => 'decimal:2',
            'net_commission' => 'decimal:2',
            'total_order_commission' => 'decimal:2',
            'clicked_at' => 'datetime',
            'ordered_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
