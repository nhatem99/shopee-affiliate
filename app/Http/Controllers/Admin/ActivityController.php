<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserActivity;
use App\Services\TrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $query = UserActivity::with('user')->latest();

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }

        if ($request->filled('platform')) {
            $query->where('platform', $request->input('platform'));
        }

        if ($request->filled('device_type')) {
            $query->where('device_type', $request->input('device_type'));
        }

        if ($request->filled('traffic_source')) {
            $query->where('traffic_source', $request->input('traffic_source'));
        }

        $activities = $query->paginate(30)->through(fn (UserActivity $a) => [
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
        ])->withQueryString();

        $recentWindow = now()->subDays(7);

        return Inertia::render('Admin/Activities', [
            'activities' => $activities,
            'filters' => $request->only(['event_type', 'platform', 'device_type', 'traffic_source']),
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
