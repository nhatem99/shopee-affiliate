<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserActivity;
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
            'created_at' => $a->created_at->toDateTimeString(),
        ])->withQueryString();

        $recentWindow = now()->subDays(7);

        return Inertia::render('Admin/Activities', [
            'activities' => $activities,
            'filters' => $request->only(['event_type', 'platform', 'device_type']),
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
            ],
        ]);
    }
}
