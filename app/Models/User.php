<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'phone', 'role', 'wallet_balance', 'otp', 'otp_expires_at'])]
#[Hidden(['password', 'remember_token', 'otp'])]
class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'password' => 'hashed',
            'wallet_balance' => 'decimal:2',
        ];
    }

    public function affiliateLinks(): HasMany
    {
        return $this->hasMany(AffiliateLink::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function payoutAccounts(): HasMany
    {
        return $this->hasMany(PayoutAccount::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Tổng hoa hồng đã được duyệt (approved).
     */
    public function approvedCommissionTotal(): float
    {
        return (float) $this->commissions()->where('status', 'approved')->sum('amount');
    }

    /**
     * Tổng số tiền đã/đang bị giữ cho các lệnh rút chưa bị từ chối.
     */
    public function reservedWithdrawalTotal(): float
    {
        return (float) $this->withdrawals()
            ->whereIn('status', ['pending', 'approved', 'completed'])
            ->sum('amount');
    }

    /**
     * Số dư khả dụng để rút.
     */
    public function availableBalance(): float
    {
        return $this->approvedCommissionTotal() - $this->reservedWithdrawalTotal();
    }
}
