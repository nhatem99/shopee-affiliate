<?php

namespace App\Services;

use App\Exceptions\CheckInException;
use App\Models\CheckIn;
use App\Models\Commission;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\CheckInRewardNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Điểm danh nhận quà: mỗi ngày bấm một lần, bốc ngẫu nhiên một phần quà trong kho quà của ngày
 * hôm đó, điểm danh liên tiếp đủ 7/30 ngày thì được thưởng thêm.
 *
 * Bốn điều quyết định thiết kế ở đây:
 *
 *  1. Tiền thưởng ghi vào bảng commissions với type 'checkin', y hệt cách thưởng người mới đi
 *     chung bảng đó (xem WelcomeBonusService). Số dư ví = tổng commission approved trừ tiền
 *     đang giữ (User::availableBalance), nên không phải sửa một dòng nào trong cách tính tiền.
 *  2. Đây là TIỀN CHO KHÔNG, không đến từ đơn hàng nào. Nên nó không được tính vào bảng xếp
 *     hạng, không được tính vào hạng thành viên, và tự nó không rút ra được — cả ba chỗ đó đều
 *     chỉ đếm Commission::TYPE_CASHBACK (xem User::hasRealCashback,
 *     MembershipTierService::eligibleCommissions, CashbackLeaderboardService). Cày tài khoản ảo
 *     để điểm danh thì chỉ nhìn được con số trong ví chứ không rút ra được đồng nào.
 *  3. Mức quà THẤP NHẤT không giới hạn số lượng. Hết quà là hết mấy mệnh giá cao, ai bấm cũng
 *     vẫn nhận được và chuỗi ngày vẫn nối tiếp — chuỗi bị đứt vì "hết quà" là lỗi của hệ thống
 *     mà khách phải chịu, và mất chuỗi là mất đúng thứ giữ khách quay lại.
 *  4. Chặn điểm danh hai lần một ngày bằng ràng buộc unique(user_id, checked_on) trong DB chứ
 *     không bằng câu if: khách bấm hai nhát liên tiếp (hoặc mạng chậm rồi bấm lại) là hai
 *     request chạy song song, câu if trong PHP đọc xong cả hai đều thấy "chưa điểm danh".
 */
class DailyCheckInService
{
    /** Công tắc ở Admin > Cài đặt. Mặc định BẬT — deploy xong là chương trình chạy luôn. */
    public const ENABLED_KEY = 'checkin_enabled';

    /**
     * Kho quà mỗi ngày, BẮT BUỘC xếp từ cao xuống thấp và mục CUỐI CÙNG phải là mục không giới
     * hạn (quantity = null) — draw() lấy mục cuối làm quà an ủi khi mọi mệnh giá có hạn đã hết.
     *
     * `quantity` là số phần phát ra trong MỘT ngày, tự đầy lại lúc nửa đêm (giờ Việt Nam) vì
     * tồn kho tính bằng cách đếm số lượt đã phát trong ngày chứ không trừ dần vào một con số
     * lưu sẵn — không có gì để quên reset.
     *
     * Tổng số phần có hạn (9 + 50 + 600 = 659) chính là con số "Còn N phần quà" khách thấy đầu
     * ngày. Sửa mấy con số này là sửa tiền thật, nên để trong PHP chứ không cho gõ từ trang
     * admin — cùng lý do với bảng hạng trong MembershipTierService.
     *
     * @var list<array{amount: int, quantity: int|null}>
     */
    private const PRIZES = [
        ['amount' => 1000, 'quantity' => 9],
        ['amount' => 500, 'quantity' => 50],
        ['amount' => 150, 'quantity' => 600],
        ['amount' => 100, 'quantity' => null],
    ];

    /**
     * Số "ô quay" của một ngày. Quà có hạn chiếm số ô đúng bằng số phần còn lại, phần ô trống
     * còn lại rơi vào mức thấp nhất — nên tỉ lệ trúng quà xịn đầu ngày là 659/3000 ≈ 22%, và
     * giảm dần khi kho vơi đi.
     *
     * Đây KHÔNG phải trần số lượt điểm danh mỗi ngày: quá 3000 người bấm thì mức thấp nhất chỉ
     * còn đúng 1 ô ăn chắc (xem draw()), không ai bị từ chối.
     */
    public const DAILY_SLOTS = 3000;

    /**
     * Mốc chuỗi ngày → tiền thưởng thêm. Lặp lại: chuỗi 14 ngày ăn mốc 7 lần nữa, chuỗi 60 ngày
     * ăn mốc 30 lần nữa. Không lặp thì người đi được 30 ngày hết hẳn lý do bấm tiếp.
     *
     * Trùng cả hai mốc (chuỗi 210 ngày) thì lấy mốc lớn, không cộng dồn — xem milestoneFor().
     *
     * ĐÂY LÀ HAI CON SỐ ĐẮT NHẤT CỦA CẢ TÍNH NĂNG, không phải bảng quà ở trên — ai sắp sửa hai
     * số này cần biết trước con toán này. Vì chúng lặp lại vô hạn nên một khách đi đủ 30 ngày
     * ăn 4 mốc 7 + 1 mốc 30 MỖI THÁNG: 4×2.000 + 10.000 = 18.000đ tiền mốc, trong khi tiền quà
     * bốc cả tháng chỉ ~3.600đ. Tức ~83% chi phí của một khách trung thành nằm ở đây, và con số
     * đó tăng theo số khách SIÊNG BẤM chứ không theo số khách mua hàng. Để so: thưởng người mới
     * là 5.000đ MỘT LẦN (WelcomeBonusService::DEFAULT_AMOUNT).
     *
     * Chủ dự án đã biết và chọn giữ mức này.
     *
     * @var array<int, int>
     */
    private const MILESTONES = [30 => 10000, 7 => 2000];

    public function enabled(): bool
    {
        return Setting::getBool(self::ENABLED_KEY, true);
    }

    /** Ngày hôm nay theo giờ Việt Nam (config app.timezone), không có phần giờ. */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfDay();
    }

    /** Mức quà thấp nhất — mức ai điểm danh cũng chắc chắn có. */
    public function baseAmount(): float
    {
        return (float) self::PRIZES[array_key_last(self::PRIZES)]['amount'];
    }

    /**
     * Toàn bộ dữ liệu cho thẻ "Điểm danh nhận quà". $user = null là khách vãng lai: vẫn trả đủ
     * kho quà và lịch tuần để thẻ hiện được (nó là mồi đăng ký), chỉ không có chuỗi ngày.
     *
     * @return array<string, mixed>
     */
    public function state(?User $user): array
    {
        $today = $this->today();
        $remaining = $this->remainingPrizes($today);

        // Ba truy vấn cho cả thẻ, không hơn: kho quà, tuần này, lượt gần nhất. Hàm này chạy trên
        // MỌI lượt vào trang chủ nên mỗi truy vấn thừa ở đây là một truy vấn thừa cho toàn bộ
        // lượng truy cập. Lượt hôm nay lấy ra từ chính dữ liệu tuần — hôm nay luôn nằm trong tuần.
        $weekCheckIns = $user ? $this->weekCheckIns($user, $today) : collect();
        $claimedToday = $weekCheckIns->get($today->toDateString());
        $last = $user ? $this->lastCheckIn($user) : null;

        return [
            'today' => $today->toDateString(),
            // Mệnh giá cao nhất CÒN HÀNG, không phải mệnh giá cao nhất của bảng: hết giải nhất
            // từ sáng mà trưa vẫn rao "giải cao nhất 1.000đ" là nói dối khách một cách có hệ thống.
            'top_prize' => $this->topPrize($remaining),
            'gifts_left' => array_sum($remaining),
            'base_amount' => $this->baseAmount(),
            'prizes' => $this->prizeTable($remaining),
            'milestones' => $this->milestoneTable(),
            'streak' => $this->streakFrom($last, $today),
            // Chuỗi sẽ đạt được nếu bấm điểm danh ngay bây giờ — dùng cho câu "hôm nay là ngày
            // thứ N" và để biết còn mấy ngày nữa tới mốc.
            'next_streak' => $this->nextStreakFrom($last, $today),
            'can_claim' => $user !== null && $this->enabled() && $claimedToday === null,
            'claimed_today' => $claimedToday ? $this->reward($claimedToday) : null,
            'week' => $this->week($weekCheckIns, $today),
        ];
    }

    /**
     * Điểm danh cho hôm nay.
     *
     * @throws CheckInException khi chương trình đang tắt hoặc hôm nay đã điểm danh rồi
     */
    public function claim(User $user): CheckIn
    {
        if (! $this->enabled()) {
            throw new CheckInException('Chương trình điểm danh đang tạm dừng.');
        }

        $today = $this->today();

        // Câu kiểm tra này chỉ để trả về lời nhắn tử tế cho trường hợp thường gặp (khách mở hai
        // tab, hoặc bấm lại sau khi đã nhận). Chặn thật nằm ở unique index bên dưới.
        if ($this->checkInOn($user, $today) !== null) {
            throw new CheckInException('Hôm nay bạn đã điểm danh rồi — quay lại vào ngày mai nhé.');
        }

        $streak = $this->nextStreak($user);
        $milestone = $this->milestoneFor($streak);
        $prize = $this->draw($today);

        try {
            return DB::transaction(function () use ($user, $today, $streak, $milestone, $prize) {
                $checkIn = CheckIn::create([
                    'user_id' => $user->id,
                    'checked_on' => $today->toDateString(),
                    'prize_amount' => $prize,
                    'bonus_amount' => $milestone['amount'],
                    'amount' => $prize + $milestone['amount'],
                    'streak' => $streak,
                ]);

                Commission::create([
                    'user_id' => $user->id,
                    'affiliate_link_id' => null,
                    'type' => Commission::TYPE_CHECKIN,
                    'amount' => $checkIn->amount,
                    'status' => 'approved',
                    // Cột order_id là unique — lớp chặn thứ hai, phòng khi sau này có ai đó ghi
                    // thẳng vào commissions mà quên mất bảng check_ins.
                    'order_id' => 'CHECKIN-'.$user->id.'-'.$today->format('Ymd'),
                    'confirmed_at' => now(),
                ]);

                $user->notify(new CheckInRewardNotification(
                    (float) $checkIn->amount,
                    $streak,
                    $milestone['every'],
                ));

                return $checkIn;
            });
        } catch (UniqueConstraintViolationException) {
            // Hai request cùng lúc: một cái đã ghi xong, cái này thua. Không phải lỗi hệ thống —
            // khách chỉ bấm hai lần, và đúng một phần quà đã vào ví.
            throw new CheckInException('Hôm nay bạn đã điểm danh rồi — quay lại vào ngày mai nhé.');
        }
    }

    /**
     * Phần thưởng của một lượt điểm danh, dạng gọn cho giao diện.
     *
     * @return array{amount: float, prize: float, bonus: float, streak: int, milestone: int|null}
     */
    public function reward(CheckIn $checkIn): array
    {
        return [
            'amount' => (float) $checkIn->amount,
            'prize' => (float) $checkIn->prize_amount,
            'bonus' => (float) $checkIn->bonus_amount,
            'streak' => (int) $checkIn->streak,
            'milestone' => ((float) $checkIn->bonus_amount) > 0
                ? $this->milestoneFor((int) $checkIn->streak)['every']
                : null,
        ];
    }

    /**
     * Chuỗi ngày ĐANG giữ. 0 khi chưa điểm danh bao giờ hoặc đã đứt.
     *
     * Điểm danh hôm qua mà hôm nay chưa bấm thì chuỗi vẫn còn nguyên (bấm hôm nay là nối tiếp),
     * nên vẫn trả về số cũ. Nghỉ từ hôm kia trở đi là đứt, về 0.
     */
    public function currentStreak(User $user): int
    {
        return $this->streakFrom($this->lastCheckIn($user), $this->today());
    }

    /** Chuỗi sẽ thành bao nhiêu nếu điểm danh ngay bây giờ. Đã điểm danh rồi thì giữ nguyên. */
    public function nextStreak(User $user): int
    {
        return $this->nextStreakFrom($this->lastCheckIn($user), $this->today());
    }

    private function streakFrom(?CheckIn $last, CarbonImmutable $today): int
    {
        if ($last === null) {
            return 0;
        }

        $on = CarbonImmutable::parse($last->checked_on)->startOfDay();

        return $on->equalTo($today) || $on->equalTo($today->subDay()) ? (int) $last->streak : 0;
    }

    private function nextStreakFrom(?CheckIn $last, CarbonImmutable $today): int
    {
        if ($last === null) {
            return 1;
        }

        $on = CarbonImmutable::parse($last->checked_on)->startOfDay();

        return match (true) {
            $on->equalTo($today) => (int) $last->streak,
            $on->equalTo($today->subDay()) => (int) $last->streak + 1,
            default => 1,
        };
    }

    /**
     * Mốc mà một chuỗi chạm tới, nếu có.
     *
     * @return array{every: int|null, amount: float}
     */
    public function milestoneFor(int $streak): array
    {
        foreach (self::MILESTONES as $every => $amount) {
            if ($streak > 0 && $streak % $every === 0) {
                return ['every' => $every, 'amount' => (float) $amount];
            }
        }

        return ['every' => null, 'amount' => 0.0];
    }

    /**
     * Bốc một phần quà từ kho của ngày.
     *
     * Trọng số = số phần CÒN LẠI của từng mệnh giá; số ô còn thừa trong DAILY_SLOTS dồn hết vào
     * mức thấp nhất. Kho cạn (hoặc hơn 3000 lượt trong ngày) thì mức thấp nhất vẫn giữ 1 ô, nên
     * hàm này không bao giờ trả về 0 — không có lượt điểm danh nào ra về tay trắng.
     */
    public function draw(?CarbonImmutable $on = null): float
    {
        $remaining = $this->remainingPrizes($on ?? $this->today());

        $base = self::PRIZES[array_key_last(self::PRIZES)]['amount'];

        $weights = $remaining;
        $weights[$base] = ($weights[$base] ?? 0) + max(1, self::DAILY_SLOTS - array_sum($remaining));

        $roll = random_int(1, (int) array_sum($weights));

        foreach ($weights as $amount => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return (float) $amount;
            }
        }

        // Không tới được: tổng trọng số luôn > 0 nên vòng lặp trên luôn trả về trước.
        return (float) $base;
    }

    /**
     * Số phần còn lại của từng mệnh giá CÓ HẠN trong ngày, [mệnh giá => số phần]. Mệnh giá đã
     * hết bị loại khỏi mảng. Mức không giới hạn không có mặt ở đây.
     *
     * Đếm tồn bằng cách đếm số lượt đã phát trong ngày: hai người bấm cùng một khoảnh khắc có
     * thể cùng đọc ra "còn 1 phần" rồi cùng trúng, tức phát dư vài phần so với hạn mức. Chấp
     * nhận: lệch về phía TẶNG THÊM cho khách, vài trăm đồng, đổi lại không phải khoá bảng ở mỗi
     * lượt bấm nút. Khoá bảng mới là thứ làm nút này chậm đúng vào giờ cao điểm.
     *
     * @return array<int, int>
     */
    public function remainingPrizes(?CarbonImmutable $on = null): array
    {
        $given = $this->givenOn($on ?? $this->today());

        $remaining = [];

        foreach (self::PRIZES as $prize) {
            if ($prize['quantity'] === null) {
                continue;
            }

            $left = $prize['quantity'] - ($given[$prize['amount']] ?? 0);

            if ($left > 0) {
                $remaining[$prize['amount']] = $left;
            }
        }

        return $remaining;
    }

    /**
     * Bảng quà cho phần "Chi tiết": mệnh giá, số phần mỗi ngày, số phần còn lại.
     *
     * @param  array<int, int>  $remaining
     * @return list<array{amount: float, quantity: int|null, left: int|null}>
     */
    private function prizeTable(array $remaining): array
    {
        return array_map(fn (array $prize) => [
            'amount' => (float) $prize['amount'],
            'quantity' => $prize['quantity'],
            'left' => $prize['quantity'] === null ? null : ($remaining[$prize['amount']] ?? 0),
        ], self::PRIZES);
    }

    /**
     * @return list<array{days: int, amount: float}> mốc nhỏ trước
     */
    private function milestoneTable(): array
    {
        $table = [];

        foreach (self::MILESTONES as $days => $amount) {
            $table[] = ['days' => $days, 'amount' => (float) $amount];
        }

        usort($table, fn (array $a, array $b) => $a['days'] <=> $b['days']);

        return $table;
    }

    /**
     * @param  array<int, int>  $remaining
     */
    private function topPrize(array $remaining): float
    {
        return $remaining === [] ? $this->baseAmount() : (float) max(array_keys($remaining));
    }

    /**
     * Số phần đã phát trong ngày theo từng mệnh giá, [mệnh giá => số lượt].
     *
     * @return array<int, int>
     */
    private function givenOn(CarbonImmutable $on): array
    {
        return CheckIn::query()
            ->where('checked_on', $on->toDateString())
            ->groupBy('prize_amount')
            ->selectRaw('prize_amount, COUNT(*) as given')
            ->pluck('given', 'prize_amount')
            ->mapWithKeys(fn ($given, $amount) => [(int) $amount => (int) $given])
            ->all();
    }

    /**
     * Các lượt điểm danh của tuần đang xem, đánh khoá theo ngày 'Y-m-d'.
     *
     * Tuần bắt đầu từ Thứ Hai — Carbon mặc định đã vậy với locale mặc định, nhưng nói rõ ra
     * bằng startOfWeek(MONDAY) để đổi locale sau này không lặng lẽ đẩy Chủ Nhật lên đầu bảng.
     *
     * @return Collection<string, CheckIn>
     */
    private function weekCheckIns(User $user, CarbonImmutable $today)
    {
        $monday = $today->startOfWeek(CarbonImmutable::MONDAY);

        return CheckIn::query()
            ->where('user_id', $user->id)
            ->whereBetween('checked_on', [$monday->toDateString(), $monday->addDays(6)->toDateString()])
            ->get()
            ->keyBy(fn (CheckIn $c) => CarbonImmutable::parse($c->checked_on)->toDateString());
    }

    /**
     * Bảy ô T2..CN của tuần đang xem.
     *
     * @param  Collection<string, CheckIn>  $claimed
     * @return list<array{label: string, date: string, state: string, amount: float}>
     */
    private function week($claimed, CarbonImmutable $today): array
    {
        $monday = $today->startOfWeek(CarbonImmutable::MONDAY);

        $labels = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];

        $days = [];

        foreach ($labels as $i => $label) {
            $date = $monday->addDays($i);
            $hit = $claimed->get($date->toDateString());

            $days[] = [
                'label' => $label,
                'date' => $date->toDateString(),
                // 'done' = đã nhận, 'today' = hôm nay chưa bấm, 'missed' = ngày đã qua mà bỏ lỡ,
                // 'upcoming' = chưa tới. Bốn trạng thái vì ô bỏ lỡ và ô chưa tới nhìn giống hệt
                // nhau nếu gộp, mà ý nghĩa thì ngược nhau hoàn toàn.
                'state' => match (true) {
                    $hit !== null => 'done',
                    $date->equalTo($today) => 'today',
                    $date->lessThan($today) => 'missed',
                    default => 'upcoming',
                },
                // Ngày đã nhận hiện số thật đã nhận; ngày chưa tới hiện mức ăn chắc thấp nhất —
                // hứa đúng phần chắc chắn có, phần may mắn để dành làm bất ngờ.
                'amount' => $hit !== null ? (float) $hit->amount : $this->baseAmount(),
            ];
        }

        return $days;
    }

    private function checkInOn(User $user, CarbonImmutable $on): ?CheckIn
    {
        return CheckIn::query()
            ->where('user_id', $user->id)
            ->where('checked_on', $on->toDateString())
            ->first();
    }

    private function lastCheckIn(User $user): ?CheckIn
    {
        return CheckIn::query()
            ->where('user_id', $user->id)
            ->orderByDesc('checked_on')
            ->first();
    }
}
