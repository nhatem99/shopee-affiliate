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

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
