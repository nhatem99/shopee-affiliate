<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

// 'role' cố tình không nằm trong danh sách này — chỉ được gán qua forceFill() ở nơi đáng
// tin cậy (seeder), tránh khả năng leo quyền nếu sau này có code update($request->all()).
#[Fillable(['name', 'email', 'password', 'phone', 'google_id', 'wallet_balance'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable;

    /**
     * Độ dài mã sub_id. Shopee chưa công bố giới hạn độ dài của ô Sub_id, nên giữ ngắn: đủ dài
     * để không đụng nhau (36^10 khả năng), đủ ngắn để không sợ bị cắt cụt giữa đường.
     */
    public const SUB_ID_LENGTH = 10;

    protected static function booted(): void
    {
        // Gắn ở model chứ KHÔNG ở controller: hiện có ba đường tạo user (RegisterController,
        // GoogleController, AdminUserSeeder) và mỗi đường mới thêm sau này đều
        // sẽ quên mất bước này — user thiếu sub_id là user vĩnh viễn không nhận được hoàn tiền,
        // mà lỗi đó không hề báo gì cả.
        static::creating(function (self $user): void {
            $user->sub_id ??= self::generateSubId();
        });
    }

    /**
     * Sinh mã định danh gửi kèm link affiliate, quay về trong cột Sub_id của báo cáo Shopee.
     *
     * Ba ràng buộc, phá cái nào cũng hỏng theo kiểu âm thầm:
     *
     *  1. KHÔNG chứa dấu "-". Đó là ký tự Shopee dùng tách 5 khe của ô Sub_id
     *     (xem AffiliateLinkRewriterService::buildSubId), lẫn vào là vỡ cấu trúc và đọc
     *     ngược ra sai mã. Str::random() chỉ trả [A-Za-z0-9] nên vốn đã an toàn.
     *  2. KHÔNG trùng marker kênh IG/YT — trùng thì resolveSubId() nhận nhầm mã của khách
     *     thành mã kênh và cả nhóm traffic bị gắn sai nhãn.
     *  3. KHÔNG suy ra được tên miền/thương hiệu, và không đoán được từ id tài khoản: giá trị
     *     này Shopee đọc được và khách nhìn thấy trên thanh địa chỉ.
     */
    public static function generateSubId(): string
    {
        do {
            $code = Str::lower(Str::random(self::SUB_ID_LENGTH));
        } while (self::subIdIsReserved($code) || self::where('sub_id', $code)->exists());

        return $code;
    }

    /**
     * Mã ngẫu nhiên 10 ký tự gần như không bao giờ rơi trúng mấy nhãn ngắn này, nhưng kiểm tra
     * ở đây để khi ai đó rút ngắn SUB_ID_LENGTH thì va chạm bị chặn ngay thay vì lặng lẽ làm
     * lệch báo cáo kênh.
     */
    private static function subIdIsReserved(string $code): bool
    {
        $reserved = array_merge(
            (array) config('services.shopee_affiliate.ig_markers', []),
            (array) config('services.shopee_affiliate.yt_markers', []),
            [
                (string) config('services.shopee_affiliate.utm_content'),
                (string) config('services.shopee_affiliate.utm_content_ig'),
                (string) config('services.shopee_affiliate.utm_content_yt'),
            ],
        );

        $reserved = array_filter(array_map(
            fn ($value) => mb_strtolower(trim((string) $value)),
            $reserved,
        ), fn (string $value) => $value !== '');

        return in_array($code, $reserved, true);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'wallet_balance' => 'decimal:2',
            'banned_at' => 'datetime',
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

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
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
