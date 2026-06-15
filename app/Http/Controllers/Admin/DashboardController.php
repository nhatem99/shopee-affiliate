<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffiliateLink;
use App\Models\Commission;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $range = (int) $request->query('range', 7);
        $range = in_array($range, [7, 30], true) ? $range : 7;

        // Doanh thu + số đơn theo ngày, key theo ngày để zero-fill dải liên tục
        $rows = Commission::selectRaw('DATE(created_at) as date, SUM(amount) as total, COUNT(*) as orders')
            ->where('created_at', '>=', now()->subDays($range - 1)->startOfDay())
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $dailyHistory = collect(range($range - 1, 0))->map(function ($i) use ($rows) {
            $day = now()->subDays($i);
            $row = $rows->get($day->toDateString());

            return [
                'date' => $day->toDateString(),
                'label' => $day->format('d/m'),
                'total' => (float) ($row->total ?? 0),
                'orders' => (int) ($row->orders ?? 0),
            ];
        });

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'total_codes' => AffiliateLink::count(),
                'pending_orders' => Commission::where('status', 'pending')->count(),
                'weekly_commission' => Commission::where('status', 'approved')
                    ->where('created_at', '>=', now()->startOfWeek())
                    ->sum('amount'),
                'total_users' => User::where('role', 'user')->count(),
            ],
            'daily_history' => $dailyHistory,
            'range' => $range,
            'recent_orders' => Commission::with(['affiliateLink', 'user'])
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn($c) => [
                    'id' => $c->id,
                    'user' => $c->user?->name,
                    'product' => $c->affiliateLink?->product_name,
                    'amount' => $c->amount,
                    'status' => $c->status,
                    'created_at' => $c->created_at->toDateString(),
                ]),
        ]);
    }
}
