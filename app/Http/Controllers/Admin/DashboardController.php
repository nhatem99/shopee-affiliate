<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffiliateLink;
use App\Models\Commission;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'total_codes' => AffiliateLink::count(),
                'pending_orders' => Commission::where('status', 'pending')->count(),
                'weekly_commission' => Commission::where('status', 'approved')
                    ->where('created_at', '>=', now()->startOfWeek())
                    ->sum('amount'),
                'total_users' => User::where('role', 'user')->count(),
            ],
            'daily_revenue' => Commission::selectRaw("strftime('%Y-%m-%d', created_at) as date, SUM(amount) as total")
                ->where('created_at', '>=', now()->subDays(7))
                ->groupBy('date')
                ->orderBy('date')
                ->get(),
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
