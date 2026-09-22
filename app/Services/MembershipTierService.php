<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hạng thành viên & đặc quyền: hoàn càng nhiều thì phần hoa hồng chia lại càng cao.
 *
 * Ba điều quyết định toàn bộ thiết kế ở đây:
 *
 *  1. Phần thưởng hạng là ĐIỂM PHẦN TRĂM cộng thẳng vào tỉ lệ chia hoa hồng (Setting
 *     'cashback_rate'). Hạng Vàng với tỉ lệ nền 70% nghĩa là khách nhận 73% hoa hồng ròng của
 *     đơn, không phải "70% rồi nhân thêm 3%". Đây là tiền thật — xem CashbackService::award.
 *  2. Hạng xét theo TIỀN HOÀN ĐÃ DUYỆT trong một quý, mốc thời gian lấy `confirmed_at` (lúc
 *     tiền vào ví) đúng như bảng xếp hạng tháng (CashbackLeaderboardService). Khách tự kiểm
 *     chứng được: trang Đơn hàng hiện đúng ngày này.
 *  3. Hạng của quý NÀY xét theo tổng của quý TRƯỚC, và đứng yên suốt quý. Nếu xét theo số đang
 *     chạy của quý hiện tại thì một đơn bị huỷ giữa quý sẽ hạ hạng khách ngay lập tức, kéo theo
 *     tiền hoàn của các đơn sau đó tụt xuống — không cách nào giải thích cho khách được.
 *
 * Thưởng người mới (Commission::TYPE_WELCOME_BONUS) KHÔNG tính vào hạng: đó là tiền cho không,
 * ai đăng ký cũng có, để nó đẩy hạng là biếu không đặc quyền cho tài khoản chưa mua gì.
 */
class MembershipTierService
{
    /** Công tắc ở Admin > Cài đặt. Mặc định BẬT — deploy xong là chương trình chạy luôn. */
    public const ENABLED_KEY = 'membership_tier_enabled';

    /** Hạng của tài khoản chưa qua lần xét nào, và của người không đạt mốc nào cả. */
    public const DEFAULT_KEY = 'tan_binh';

    /**
     * Sáu hạng, BẮT BUỘC xếp từ thấp lên cao — tierForTotal() và nextAfter() duyệt theo thứ tự
     * này. `threshold` là tổng tiền hoàn đã duyệt trong một quý, `bonus` là số điểm phần trăm
     * cộng vào tỉ lệ chia hoa hồng.
     *
     * @var list<array{key: string, label: string, icon: string, bonus: float, threshold: int}>
     */
    private const TIERS = [
        ['key' => 'tan_binh', 'label' => 'Tân binh', 'icon' => '🌱', 'bonus' => 0.0, 'threshold' => 0],
        ['key' => 'dong', 'label' => 'Đồng', 'icon' => '🛡️', 'bonus' => 1.0, 'threshold' => 200000],
        ['key' => 'bac', 'label' => 'Bạc', 'icon' => '🎖️', 'bonus' => 2.0, 'threshold' => 500000],
        ['key' => 'vang', 'label' => 'Vàng', 'icon' => '⭐', 'bonus' => 3.0, 'threshold' => 1000000],
        ['key' => 'bach_kim', 'label' => 'Bạch kim', 'icon' => '💎', 'bonus' => 4.0, 'threshold' => 2000000],
        ['key' => 'kim_cuong', 'label' => 'Kim cương', 'icon' => '✨', 'bonus' => 5.0, 'threshold' => 3000000],
    ];

    /**
     * Chương trình hạng có đang chạy không.
     *
     * Bám theo công tắc của hoàn tiền y như CashbackService::displayRate(): tỉ lệ nền bằng 0 là
     * hệ thống không chia đồng nào cho ai, lúc đó "thưởng thêm 5%" là thưởng thêm trên số 0 —
     * hiện bảng hạng trong trạng thái đó chỉ là hứa suông.
     *
     * Đọc thẳng Setting thay vì tiêm CashbackService: CashbackService đã tiêm service này để
     * tính tiền, tiêm ngược lại là container quay vòng vô hạn.
     */
    public function enabled(): bool
    {
        return Setting::getBool(self::ENABLED_KEY, true)
            && (float) Setting::get(CashbackService::RATE_KEY, 0) > 0;
    }

    /**
     * @return list<array{key: string, label: string, icon: string, bonus: float, threshold: int}>
     */
    public function tiers(): array
    {
        return self::TIERS;
    }

    /**
     * Mô tả của một hạng. Khoá lạ hoặc null đều trả về hạng thấp nhất — cột `tier` trong DB có
     * thể còn giá trị của một lần đổi tên hạng trước đó, và việc đó không được phép làm vỡ trang
     * tài khoản của khách.
     *
     * @return array{key: string, label: string, icon: string, bonus: float, threshold: int}
     */
    public function definition(?string $key): array
    {
        foreach (self::TIERS as $tier) {
            if ($tier['key'] === $key) {
                return $tier;
            }
        }

        return self::TIERS[0];
    }

    /** Số điểm phần trăm cộng vào tỉ lệ hoàn tiền cho một hạng. 0 khi chương trình đang tắt. */
    public function bonusFor(?string $key): float
    {
        return $this->enabled() ? $this->definition($key)['bonus'] : 0.0;
    }

    public function bonusForUser(?User $user): float
    {
        return $user === null ? 0.0 : $this->bonusFor($user->tier);
    }

    /**
     * Phần thưởng hạng của nhiều khách trong một truy vấn — CashbackService::sync() chạy qua
     * hàng nghìn đơn mỗi lượt, tra từng người một là N+1 ngay trong vòng lặp ghi tiền.
     *
     * @param  list<int>  $userIds
     * @return array<int, float>
     */
    public function bonusRatesFor(array $userIds): array
    {
        if (! $this->enabled() || $userIds === []) {
            return [];
        }

        return User::whereIn('id', $userIds)
            ->pluck('tier', 'id')
            ->map(fn ($tier) => $this->definition($tier)['bonus'])
            ->all();
    }

    /**
     * Hạng cao nhất mà tổng tiền hoàn này với tới.
     *
     * @return array{key: string, label: string, icon: string, bonus: float, threshold: int}
     */
    public function tierForTotal(float $total): array
    {
        $matched = self::TIERS[0];

        foreach (self::TIERS as $tier) {
            // ">" chứ không phải ">=" cho khớp đúng chữ trên giao diện ("Tích lũy > 200.000đ").
            // Ngưỡng 0 của hạng thấp nhất vẫn luôn khớp nhờ $matched khởi tạo sẵn ở trên.
            if ($total > $tier['threshold']) {
                $matched = $tier;
            }
        }

        return $matched;
    }

    /**
     * Hạng ngay trên một hạng cho trước, null nếu đã kịch trần.
     *
     * @return array{key: string, label: string, icon: string, bonus: float, threshold: int}|null
     */
    public function nextAfter(string $key): ?array
    {
        $found = false;

        foreach (self::TIERS as $tier) {
            if ($found) {
                return $tier;
            }

            $found = $tier['key'] === $key;
        }

        return null;
    }

    /** Tổng tiền hoàn đã duyệt của một khách trong khoảng thời gian. */
    public function earnedBetween(int $userId, CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) $this->eligibleCommissions($from, $to)->where('user_id', $userId)->sum('amount');
    }

    /**
     * Xét lại hạng cho MỘT khách theo tổng của quý trước.
     *
     * @return bool có đổi gì trong DB không
     */
    public function refresh(User $user, ?CarbonImmutable $at = null): bool
    {
        $quarter = ($at ?? CarbonImmutable::now())->startOfQuarter();
        $previous = $quarter->subQuarter();

        $tier = $this->tierForTotal($this->earnedBetween($user->id, $previous, $previous->endOfQuarter()))['key'];

        return $this->store($user, $tier, self::quarterKey($quarter));
    }

    /**
     * Xét lại hạng cho toàn bộ khách. Lệnh tiers:refresh gọi hàm này mỗi ngày.
     *
     * Admin bị loại: tài khoản admin tự mua để kiểm thử không phải khách hàng, và chính nó là
     * tài khoản dễ đạt hạng cao nhất trong một hệ thống mới dựng.
     *
     * @return array{checked: int, updated: int, quarter: string}
     */
    public function refreshAll(?CarbonImmutable $at = null): array
    {
        $quarter = ($at ?? CarbonImmutable::now())->startOfQuarter();
        $previous = $quarter->subQuarter();
        $quarterKey = self::quarterKey($quarter);

        $totals = $this->totalsByUser($previous, $previous->endOfQuarter());

        $summary = ['checked' => 0, 'updated' => 0, 'quarter' => $quarterKey];

        User::query()
            ->where('role', '!=', 'admin')
            ->select(['id', 'tier', 'tier_quarter'])
            ->chunkById(500, function ($users) use (&$summary, $totals, $quarterKey) {
                foreach ($users as $user) {
                    $summary['checked']++;

                    $tier = $this->tierForTotal((float) ($totals[$user->id] ?? 0))['key'];

                    if ($this->store($user, $tier, $quarterKey)) {
                        $summary['updated']++;
                    }
                }
            });

        return $summary;
    }

    /**
     * Tiến độ hạng của một khách — dữ liệu cho trang Tài khoản và khối hạng ở trang khách.
     *
     * Điểm dễ hiểu sai nhất của cả tính năng, nên payload nói thẳng ra cả hai vế: `current` là
     * hạng ĐANG hưởng (xét từ quý trước, không đổi giữa quý) còn `pending` là hạng sẽ nhận vào
     * đầu quý sau theo số đang tích luỹ. Gộp hai thứ này thành một con số là khách nhìn thấy
     * "Hạng Vàng" rồi thắc mắc sao ví vẫn cộng theo hạng Đồng.
     *
     * @return array{
     *     current: array{key: string, label: string, icon: string, bonus: float, threshold: int},
     *     pending: array{key: string, label: string, icon: string, bonus: float, threshold: int},
     *     next: array{key: string, label: string, icon: string, bonus: float, threshold: int}|null,
     *     quarter: string,
     *     earned: float,
     *     to_next: float|null,
     *     progress: float,
     *     next_review: string
     * }
     */
    public function progressFor(User $user, ?CarbonImmutable $at = null): array
    {
        $quarter = ($at ?? CarbonImmutable::now())->startOfQuarter();

        $earned = $this->earnedBetween($user->id, $quarter, $quarter->endOfQuarter());

        $pending = $this->tierForTotal($earned);
        $next = $this->nextAfter($pending['key']);

        return [
            'current' => $this->definition($user->tier),
            'pending' => $pending,
            'next' => $next,
            'quarter' => 'Quý '.$quarter->quarter.'/'.$quarter->year,
            'earned' => $earned,
            'to_next' => $next === null ? null : max(0.0, $next['threshold'] - $earned),
            'progress' => $this->progressPercent($earned, $pending, $next),
            'next_review' => $quarter->addQuarter()->format('d/m/Y'),
        ];
    }

    /**
     * Dữ liệu cho khối "Hạng thành viên & Đặc quyền" ở trang chủ và trang /hoan-tien.
     *
     * Bảng hạng luôn dựng từ hằng số PHP chứ không gõ lại trong .vue: bonus ở đây là số quyết
     * định tiền thật, chép ra frontend là sớm muộn hai nơi nói hai giá khác nhau.
     *
     * @return array{tiers: list<array<string, mixed>>, me: array<string, mixed>|null}
     */
    public function publicTiers(?User $viewer = null): array
    {
        // Admin không có hạng (refreshAll bỏ qua họ) — đánh dấu hạng cho admin là hiện một thông
        // tin không có thật ngay trên trang khách mà admin đang duyệt thử.
        $me = $viewer !== null && ! $viewer->isAdmin() ? $this->progressFor($viewer) : null;

        return [
            'tiers' => self::TIERS,
            'me' => $me,
        ];
    }

    /** Khoá quý dùng trong cột `tier_quarter`: '2026-Q4'. */
    public static function quarterKey(CarbonImmutable $quarter): string
    {
        return $quarter->year.'-Q'.$quarter->quarter;
    }

    /**
     * Ghi hạng mới. Trả về false khi không có gì đổi — để lệnh chạy hàng ngày không đụng vào
     * `updated_at` của toàn bộ bảng users mỗi đêm.
     *
     * forceFill chứ không update(): `tier` cố tình nằm ngoài $fillable của User, cùng lý do với
     * cột `role` — nó quyết định số tiền khách nhận, nên chỉ được đổi từ đúng chỗ này.
     */
    private function store(User $user, string $tier, string $quarterKey): bool
    {
        if ($user->tier === $tier && $user->tier_quarter === $quarterKey) {
            return false;
        }

        $user->forceFill([
            'tier' => $tier,
            'tier_quarter' => $quarterKey,
            'tier_updated_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Phần trăm quãng đường từ ngưỡng hạng đang đạt tới ngưỡng hạng kế — chỉ để vẽ thanh tiến
     * độ. Kịch trần thì đầy thanh.
     */
    private function progressPercent(float $earned, array $current, ?array $next): float
    {
        if ($next === null) {
            return 100.0;
        }

        $span = $next['threshold'] - $current['threshold'];

        if ($span <= 0) {
            return 0.0;
        }

        return round(max(0.0, min(100.0, ($earned - $current['threshold']) / $span * 100)), 1);
    }

    /**
     * @return array<int, float>
     */
    private function totalsByUser(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->eligibleCommissions($from, $to)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(amount) as total')
            ->pluck('total', 'user_id')
            ->map(fn ($total) => (float) $total)
            ->all();
    }

    /**
     * Tiền được tính vào hạng: chỉ hoa hồng THẬT từ đơn hàng, đã vào ví (approved) hoặc đã rút
     * (paid). Rút tiền rồi mà bị tụt hạng thì thành phạt người mua nhiều nhất.
     */
    private function eligibleCommissions(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Commission::query()
            ->where('type', Commission::TYPE_CASHBACK)
            ->whereIn('status', ['approved', 'paid'])
            ->whereBetween('confirmed_at', [$from, $to]);
    }
}
