<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\WelcomeBonusNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Support\Facades\DB;

/**
 * Việc cần làm ngay khi có tài khoản khách mới: gửi lời chào và (nếu đang bật) cộng tiền thưởng
 * người mới vào ví.
 *
 * Gọi tường minh ở RegisterController và GoogleController chứ không móc vào User::created —
 * factory trong test và seeder cũng tạo user, mà mấy chỗ đó không phải "khách đăng ký".
 */
class WelcomeBonusService
{
    public const ENABLED_KEY = 'welcome_bonus_enabled';

    public const AMOUNT_KEY = 'welcome_bonus_amount';

    public const DEFAULT_AMOUNT = 5000;

    public function enabled(): bool
    {
        return Setting::getBool(self::ENABLED_KEY, true) && $this->amount() > 0;
    }

    public function amount(): float
    {
        return (float) Setting::get(self::AMOUNT_KEY, self::DEFAULT_AMOUNT);
    }

    /**
     * Số tiền khách sẽ được thưởng khi đăng ký, 0 nếu đang tắt. Dùng để hiện lời mời ở trang
     * đăng ký — chỉ hứa khi thật sự có.
     */
    public function promisedAmount(): float
    {
        return $this->enabled() ? $this->amount() : 0.0;
    }

    /**
     * @param  string  $source  'email' | 'google' — chỉ để viết câu chào cho đúng.
     */
    public function welcome(User $user, string $source = 'email'): void
    {
        $user->notify(new WelcomeNotification($source));

        $this->grant($user);
    }

    /**
     * Cộng thưởng. Idempotent: order_id là unique trong bảng commissions, nên user nào đã có
     * dòng WELCOME-{id} thì gọi lại bao nhiêu lần cũng không thêm được nữa.
     */
    public function grant(User $user): ?Commission
    {
        if (! $this->enabled() || $user->isAdmin()) {
            return null;
        }

        $orderId = 'WELCOME-'.$user->id;

        return DB::transaction(function () use ($user, $orderId) {
            if (Commission::where('order_id', $orderId)->lockForUpdate()->exists()) {
                return null;
            }

            $commission = Commission::create([
                'user_id' => $user->id,
                'affiliate_link_id' => null,
                'type' => Commission::TYPE_WELCOME_BONUS,
                'amount' => $this->amount(),
                'status' => 'approved',
                'order_id' => $orderId,
                'confirmed_at' => now(),
            ]);

            $user->notify(new WelcomeBonusNotification((float) $commission->amount));

            return $commission;
        });
    }
}
