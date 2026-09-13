<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Bảng xếp hạng "ai được hoàn nhiều nhất tháng này" cho trang khách.
 *
 * Nguồn số liệu là bảng `commissions` — cùng sổ tiền mà ví khách và trang Tài khoản đọc — chứ
 * KHÔNG phải shopee_orders. Đơn thô trong báo cáo có thể còn "đang giao" hoặc đã huỷ; lên bảng
 * bằng số đó là khoe một khoản tiền mà hệ thống chưa (hoặc không bao giờ) trả. Số trên bảng phải
 * là số khách thật sự đã nhận vào ví, không thì đây thành một công cụ hứa suông nữa.
 *
 * "Trong tháng" tính theo `confirmed_at` = lúc tiền vào ví, không phải ngày đặt hàng: đơn đặt
 * cuối tháng trước mà hoàn thành + đối soát tháng này thì tính tháng này. Đây cũng là mốc duy
 * nhất khách tự kiểm chứng được — trang Đơn hàng hiện đúng ngày này.
 */
class CashbackLeaderboardService
{
    public const LIMIT = 10;

    /**
     * Công tắc "hiện số liệu minh hoạ khi bảng trống". Mặc định BẬT — prod mới mở chưa có ai
     * đăng ký, deploy xong phải thấy bảng có người ngay chứ không phải nhớ vào admin bật tay.
     *
     * Một cái bảng trống thì không kéo được ai tham gia. Dữ liệu mẫu chỉ hiện khi (a) chưa bị
     * admin tắt và (b) tháng này thật sự chưa có người nào — có người thật đầu tiên là mẫu tự
     * biến mất. Cờ `demo` vẫn trả về frontend để test/đối chiếu; giao diện hiện bảng mẫu y hệt
     * bảng thật, không gắn nhãn (quyết định của admin).
     */
    public const DEMO_KEY = 'cashback_leaderboard_demo';

    public const DEMO_DEFAULT = true;

    /** Tên đã che sẵn + số tiền vừa phải, cùng dạng với dữ liệu thật để bố cục không đổi khi thay. */
    private const DEMO_ENTRIES = [
        ['name' => 'Nguyễn T*** H***', 'amount' => 186500, 'orders' => 7],
        ['name' => 'Trần M*** A***', 'amount' => 142000, 'orders' => 5],
        ['name' => 'Lê H*** P***', 'amount' => 97300, 'orders' => 4],
        ['name' => 'Phạm Q*** N***', 'amount' => 64800, 'orders' => 3],
        ['name' => 'Hoàng B*** L***', 'amount' => 51200, 'orders' => 2],
        ['name' => 'Vũ N*** K***', 'amount' => 38900, 'orders' => 2],
        ['name' => 'Đặng T*** V***', 'amount' => 22400, 'orders' => 1],
    ];

    /** Bảng đổi rất chậm (tiền chỉ vào ví khi admin nhập báo cáo) nên cache thoải mái. */
    private const CACHE_TTL_SECONDS = 600;

    /**
     * @return array{
     *     month: string,
     *     entries: list<array{rank: int, name: string, amount: float, orders: int, is_me: bool}>,
     *     total_users: int,
     *     total_amount: float,
     *     demo: bool,
     *     me: array{rank: int, amount: float, orders: int}|null
     * }
     */
    public function forMonth(?User $viewer = null, ?CarbonImmutable $month = null): array
    {
        $month = ($month ?? CarbonImmutable::now())->startOfMonth();

        $board = Cache::remember(self::cacheKey($month), self::CACHE_TTL_SECONDS, fn () => $this->build($month));

        // Phần theo từng người xem KHÔNG cache chung: cache theo từng user thì vừa phình bộ nhớ
        // vừa dễ hiện số cũ đúng lúc khách vừa nhận tiền. user_id chỉ sống trong cache để đánh
        // dấu dòng "Bạn" — không bao giờ đưa ra frontend, tên đã che rồi mà kèm id thì che vô ích.
        $board['entries'] = array_map(function (array $entry) use ($viewer) {
            $entry['is_me'] = $viewer !== null && $entry['user_id'] === $viewer->id;
            unset($entry['user_id']);

            return $entry;
        }, $board['entries']);

        $board['demo'] = false;

        if ($board['entries'] === [] && Setting::getBool(self::DEMO_KEY, self::DEMO_DEFAULT)) {
            $board = array_merge($board, $this->demoBoard());
        }

        $board['me'] = $viewer ? $this->rankOf($viewer, $month) : null;

        return $board;
    }

    /**
     * @return array{entries: list<array{rank: int, name: string, amount: float, orders: int, is_me: bool}>, total_users: int, total_amount: float, demo: bool}
     */
    private function demoBoard(): array
    {
        $entries = [];

        foreach (self::DEMO_ENTRIES as $i => $row) {
            $entries[] = [
                'rank' => $i + 1,
                'name' => $row['name'],
                'amount' => (float) $row['amount'],
                'orders' => $row['orders'],
                'is_me' => false,
            ];
        }

        return [
            'entries' => $entries,
            'total_users' => count($entries),
            'total_amount' => (float) array_sum(array_column(self::DEMO_ENTRIES, 'amount')),
            'demo' => true,
        ];
    }

    /** Gọi sau mỗi lần ghi tiền vào ví để bảng không hiện số cũ tới 10 phút. */
    public static function forget(): void
    {
        Cache::forget(self::cacheKey(CarbonImmutable::now()->startOfMonth()));
    }

    private static function cacheKey(CarbonImmutable $month): string
    {
        return 'cashback_leaderboard:'.$month->format('Y-m');
    }

    /**
     * @return array{month: string, entries: list<array{rank: int, user_id: int, name: string, amount: float, orders: int}>, total_users: int, total_amount: float}
     */
    private function build(CarbonImmutable $month): array
    {
        $rows = $this->monthlyTotals($month)
            ->orderByDesc('total')
            ->orderBy('users.id')
            ->limit(self::LIMIT)
            ->get();

        $entries = [];

        foreach ($rows as $i => $row) {
            $entries[] = [
                'rank' => $i + 1,
                'user_id' => (int) $row->id,
                'name' => self::maskName((string) $row->name),
                'amount' => (float) $row->total,
                'orders' => (int) $row->orders,
            ];
        }

        // Tổng của CẢ THÁNG (không chỉ top 10) để dòng "tháng này đã hoàn X cho N người" nói
        // đúng — con số này là thứ thuyết phục khách mới hơn cả bảng xếp hạng.
        $totals = DB::query()->fromSub($this->monthlyTotals($month), 't')
            ->selectRaw('COUNT(*) as users, COALESCE(SUM(total), 0) as amount')
            ->first();

        return [
            'month' => $month->format('n/Y'),
            'entries' => $entries,
            'total_users' => (int) ($totals->users ?? 0),
            'total_amount' => (float) ($totals->amount ?? 0),
        ];
    }

    /**
     * @return array{rank: int, amount: float, orders: int}|null
     */
    private function rankOf(User $viewer, CarbonImmutable $month): ?array
    {
        $mine = $this->eligibleCommissions($month)
            ->where('user_id', $viewer->id)
            ->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as orders')
            ->first();

        $amount = (float) ($mine->total ?? 0);

        if ($amount <= 0) {
            return null;
        }

        // Hạng = số người có tổng lớn hơn mình + 1. Bằng nhau thì cùng hạng — dùng đúng luật với
        // danh sách hiển thị (sắp theo total rồi mới tới id), khách so hai nơi không thấy lệch.
        //
        // Ghi số thẳng vào SQL thay vì bind: PDO bind float thành CHUỖI, và SQLite (môi trường
        // test) xếp mọi số nhỏ hơn mọi chuỗi nên `total > '5000'` không bao giờ đúng. sprintf %F
        // chỉ sinh ra chữ số và dấu chấm, không có đường nào cho injection.
        $above = DB::query()->fromSub($this->monthlyTotals($month), 't')
            ->whereRaw('total > '.sprintf('%.2F', $amount))
            ->count();

        return [
            'rank' => $above + 1,
            'amount' => $amount,
            'orders' => (int) ($mine->orders ?? 0),
        ];
    }

    /**
     * Tổng tiền hoàn theo từng khách trong tháng. Admin và tài khoản bị khoá không lên bảng: admin
     * tự mua hàng để test sẽ đứng đầu bảng của chính mình, còn tài khoản bị khoá thì không nên
     * được quảng bá.
     */
    private function monthlyTotals(CarbonImmutable $month): Builder
    {
        return $this->eligibleCommissions($month)
            ->join('users', 'users.id', '=', 'commissions.user_id')
            ->where('users.role', '!=', 'admin')
            ->whereNull('users.banned_at')
            ->groupBy('users.id', 'users.name')
            ->select('users.id', 'users.name')
            ->selectRaw('SUM(commissions.amount) as total')
            ->selectRaw('COUNT(*) as orders');
    }

    /**
     * Chỉ tính tiền đã thật sự vào ví (approved) hoặc đã rút (paid). 'pending' không có trong
     * luồng hoàn tiền hiện tại (CashbackService ghi thẳng 'approved'), nhưng loại tường minh để
     * nếu sau này có, nó cũng không lọt lên bảng.
     */
    private function eligibleCommissions(CarbonImmutable $month): Builder
    {
        return Commission::query()
            ->whereIn('commissions.status', ['approved', 'paid'])
            ->whereBetween('commissions.confirmed_at', [$month, $month->endOfMonth()]);
    }

    /**
     * Che tên trước khi đưa ra công khai: giữ nguyên chữ đầu (họ), các chữ sau chỉ để lại ký tự
     * đầu. "Nguyễn Văn An" → "Nguyễn V*** A***". Tên một chữ thì giữ 2 ký tự đầu.
     *
     * Khách chưa bao giờ đồng ý cho hiện tên đầy đủ trước người lạ; mà bảng này còn cho biết họ
     * đã mua bao nhiêu — ghép hai thứ đó lại là lộ thói quen mua sắm của một người có tên thật.
     */
    public static function maskName(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return 'Ẩn danh';
        }

        if (count($words) === 1) {
            $word = $words[0];
            $keep = mb_strlen($word) > 2 ? 2 : 1;

            return mb_substr($word, 0, $keep).'***';
        }

        $masked = [array_shift($words)];

        foreach ($words as $word) {
            $masked[] = mb_substr($word, 0, 1).'***';
        }

        return implode(' ', $masked);
    }
}
