<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserActivity;
use App\Services\TrackingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityController extends Controller
{
    /** Các cột xuất ra CSV, theo đúng thứ tự cột trong file. */
    private const EXPORT_HEADERS = [
        'Thời gian', 'Sự kiện', 'Người dùng', 'Sản phẩm', 'Nguồn', 'Mã', 'Sàn',
        'Thiết bị', 'Trình duyệt', 'Hệ điều hành', 'IP', 'Tỉnh/Thành', 'Quốc gia',
        'Nguồn truy cập', 'Referrer', 'URL',
    ];

    public function index(Request $request): Response
    {
        $activities = $this->filteredQuery($request)
            ->with('user')
            ->latest()
            ->paginate(30)
            ->through(fn (UserActivity $a) => [
                'id' => $a->id,
                'user' => $a->user?->name,
                'event_type' => $a->event_type,
                'platform' => $a->platform,
                'product_name' => $a->product_name,
                'voucher_code' => $a->voucher_code,
                'source' => $a->source,
                'device_type' => $a->device_type,
                'browser' => $a->browser,
                'os_name' => $a->os_name,
                'ip_address' => $a->ip_address,
                'country' => $a->country,
                'city' => $a->city,
                'traffic_source' => $a->traffic_source,
                'referrer_host' => $a->referrer_host,
                'created_at' => $a->created_at->toDateTimeString(),
            ])
            ->withQueryString();

        $recentWindow = now()->subDays(7);

        return Inertia::render('Admin/Activities', [
            'activities' => $activities,
            'filters' => $this->activeFilters($request),
            'daily' => $this->dailyCounts(14),
            'summary' => [
                'total' => UserActivity::where('created_at', '>=', $recentWindow)->count(),
                'by_device' => UserActivity::where('created_at', '>=', $recentWindow)
                    ->whereNotNull('device_type')
                    ->selectRaw('device_type, COUNT(*) as total')
                    ->groupBy('device_type')
                    ->pluck('total', 'device_type'),
                'by_platform' => UserActivity::where('created_at', '>=', $recentWindow)
                    ->whereNotNull('platform')
                    ->selectRaw('platform, COUNT(*) as total')
                    ->groupBy('platform')
                    ->orderByDesc('total')
                    ->limit(5)
                    ->pluck('total', 'platform'),
                'top_vouchers' => UserActivity::where('created_at', '>=', $recentWindow)
                    ->whereNotNull('voucher_code')
                    ->selectRaw('voucher_code, COUNT(*) as total')
                    ->groupBy('voucher_code')
                    ->orderByDesc('total')
                    ->limit(5)
                    ->pluck('total', 'voucher_code'),
                'top_countries' => UserActivity::where('created_at', '>=', $recentWindow)
                    ->whereNotNull('country')
                    ->selectRaw('country, COUNT(*) as total')
                    ->groupBy('country')
                    ->orderByDesc('total')
                    ->limit(5)
                    ->pluck('total', 'country'),
                // Chỉ tính trên 'page_view' — đây mới là lượt GHÉ THĂM mới, các event khác
                // (bấm mã, copy...) là hành động trong cùng lượt nên không tính là 1 nguồn mới.
                'top_traffic_sources' => UserActivity::where('created_at', '>=', $recentWindow)
                    ->where('event_type', 'page_view')
                    ->whereNotNull('traffic_source')
                    ->selectRaw('traffic_source, COUNT(*) as total')
                    ->groupBy('traffic_source')
                    ->orderByDesc('total')
                    ->limit(6)
                    ->pluck('total', 'traffic_source'),
                // Top IP để admin thấy ngay máy nào vào nhiều bất thường; bấm vào lọc luôn.
                'top_ips' => UserActivity::where('created_at', '>=', $recentWindow)
                    ->whereNotNull('ip_address')
                    ->selectRaw('ip_address, COUNT(*) as total')
                    ->groupBy('ip_address')
                    ->orderByDesc('total')
                    ->limit(5)
                    ->pluck('total', 'ip_address'),
                // Chuyển đổi = khách bấm "Mở Facebook ngay" (bước cuối của luồng lấy mã qua FB).
                // source phân biệt link nằm trong bình luận (fb_comment) hay mô tả reel (fb_reel).
                'conversions' => [
                    'total' => UserActivity::where('created_at', '>=', $recentWindow)
                        ->where('event_type', 'facebook_open')
                        ->count(),
                    'by_mode' => UserActivity::where('created_at', '>=', $recentWindow)
                        ->where('event_type', 'facebook_open')
                        ->whereNotNull('source')
                        ->selectRaw('source, COUNT(*) as total')
                        ->groupBy('source')
                        ->pluck('total', 'source'),
                    'top_products' => UserActivity::where('created_at', '>=', $recentWindow)
                        ->where('event_type', 'facebook_open')
                        ->whereNotNull('product_name')
                        ->selectRaw('product_name, COUNT(*) as total')
                        ->groupBy('product_name')
                        ->orderByDesc('total')
                        ->limit(8)
                        ->pluck('total', 'product_name'),
                ],
                // Đếm các dấu hiệu tấn công (brute-force login/OTP, cố vào admin trái phép,
                // bị chặn bởi rate-limit) để admin thấy ngay khi vào trang theo dõi.
                'security_events' => UserActivity::where('created_at', '>=', $recentWindow)
                    ->whereIn('event_type', [
                        'login_failed', 'login_success', 'otp_verify_failed',
                        'admin_access_denied', 'rate_limited',
                    ])
                    ->selectRaw('event_type, COUNT(*) as total')
                    ->groupBy('event_type')
                    ->pluck('total', 'event_type'),
            ],
        ]);
    }

    /**
     * Số lượt xem trang và tổng sự kiện của từng ngày trong N ngày gần nhất (cả hôm
     * nay). Ngày không có log vẫn trả về 0 để biểu đồ không bị "mất cột".
     *
     * @return list<array{date: string, page_views: int, events: int}>
     */
    private function dailyCounts(int $days): array
    {
        $start = now()->subDays($days - 1)->startOfDay();

        $rows = UserActivity::where('created_at', '>=', $start)
            ->selectRaw("DATE(created_at) as day, COUNT(*) as events, SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) as page_views")
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $daily = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $daily[] = [
                'date' => $date,
                'page_views' => (int) ($rows[$date]->page_views ?? 0),
                'events' => (int) ($rows[$date]->events ?? 0),
            ];
        }

        return $daily;
    }

    /**
     * Xuất CSV theo đúng bộ lọc đang xem (gồm cả khoảng ngày). Stream + chunk để
     * không nạp cả bảng log vào RAM khi dữ liệu đã lớn.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->filteredQuery($request)->with('user');
        $filters = $this->activeFilters($request);

        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $name = 'hoat-dong';
        if ($from || $to) {
            $name .= '_'.($from ?: 'dau').'_den_'.($to ?: now()->format('Y-m-d'));
        } else {
            $name .= '_tat-ca_'.now()->format('Y-m-d');
        }

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            // BOM để Excel nhận đúng UTF-8, không thì tiếng Việt bị vỡ dấu.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::EXPORT_HEADERS);

            $query->chunkById(500, function ($rows) use ($out) {
                foreach ($rows as $a) {
                    fputcsv($out, [
                        $a->created_at?->toDateTimeString(),
                        $a->event_type,
                        $a->user?->name ?? 'Khách',
                        $a->product_name,
                        $a->source,
                        $a->voucher_code,
                        $a->platform,
                        $a->device_type,
                        $a->browser,
                        $a->os_name,
                        $a->ip_address,
                        $a->city,
                        $a->country,
                        $a->traffic_source,
                        $a->referrer_host,
                        $a->url,
                    ]);
                }
                flush();
            });

            fclose($out);
        }, $name.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Bộ lọc dùng chung cho bảng trên web lẫn file CSV, để cái nhìn thấy và cái
     * tải về luôn khớp nhau.
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = UserActivity::query();

        foreach (['event_type', 'platform', 'device_type', 'traffic_source'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('ip')) {
            $ip = trim((string) $request->input('ip'));
            // Gõ đủ IP thì so khớp chính xác; gõ một phần (vd '113.161.') thì tìm gần
            // đúng để tra được cả một dải máy.
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $query->where('ip_address', $ip);
            } else {
                $query->where('ip_address', 'like', '%'.addcslashes($ip, '%_\\').'%');
            }
        }

        if ($from = $this->date($request->input('from'))) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $this->date($request->input('to'))) {
            $query->whereDate('created_at', '<=', $to);
        }

        return $query;
    }

    /** @return array<string, string> */
    private function activeFilters(Request $request): array
    {
        $filters = $request->only(['event_type', 'platform', 'device_type', 'traffic_source', 'ip']);
        $filters['ip'] = isset($filters['ip']) ? trim((string) $filters['ip']) : null;
        $filters['from'] = $this->date($request->input('from'));
        $filters['to'] = $this->date($request->input('to'));

        return array_filter($filters, fn ($v) => $v !== null && $v !== '');
    }

    /** Chỉ nhận đúng dạng Y-m-d; giá trị lạ bị bỏ qua thay vì làm hỏng truy vấn. */
    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }

    /**
     * Dọn các bản ghi ghi trước khi có bộ lọc bot (2026-08-27) — hoặc lọt lưới do UA
     * mới chưa nằm trong danh sách crawler. Đọc từng dòng vì tiêu chí nhận diện bot
     * áp lên user_agent chứ không map thẳng sang SQL WHERE được.
     */
    public function pruneBots(): RedirectResponse
    {
        $deleted = 0;

        UserActivity::whereNull('user_agent')
            ->orWhere('user_agent', '')
            ->select('id')
            ->chunkById(500, function ($rows) use (&$deleted) {
                $deleted += UserActivity::whereIn('id', $rows->pluck('id'))->delete();
            });

        UserActivity::whereNotNull('user_agent')
            ->where('user_agent', '!=', '')
            ->select('id', 'user_agent')
            ->chunkById(500, function ($rows) use (&$deleted) {
                $botIds = $rows->filter(fn (UserActivity $a) => TrackingService::isBot($a->user_agent))->pluck('id');
                if ($botIds->isNotEmpty()) {
                    $deleted += UserActivity::whereIn('id', $botIds)->delete();
                }
            });

        return back()->with('success', "Đã xoá {$deleted} log bot.");
    }
}
