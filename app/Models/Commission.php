<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'affiliate_link_id', 'type', 'amount', 'tier_bonus_rate', 'status', 'order_id',
    'confirmed_at', 'paid_at',
])]
class Commission extends Model
{
    use HasFactory;

    /** Hoa hồng thật từ đơn hàng — loại mặc định. */
    public const TYPE_CASHBACK = 'cashback';

    /** Tiền thưởng khi đăng ký (xem WelcomeBonusService). Không phải tiền từ đơn nào. */
    public const TYPE_WELCOME_BONUS = 'welcome_bonus';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            // Phần thưởng hạng đã dùng để tính khoản này, đóng băng lúc ghi — xem
            // MembershipTierService và CashbackService::award.
            'tier_bonus_rate' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function affiliateLink(): BelongsTo
    {
        return $this->belongsTo(AffiliateLink::class);
    }
}
