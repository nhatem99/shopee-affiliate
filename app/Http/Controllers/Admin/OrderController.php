<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Commission::with(['affiliateLink', 'user'])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $orders = $query->paginate(20)->through(fn($c) => [
            'id' => $c->id,
            'user' => $c->user?->name,
            'product' => $c->affiliateLink?->product_name,
            'platform' => $c->affiliateLink?->platform,
            'amount' => $c->amount,
            'status' => $c->status,
            'order_id' => $c->order_id,
            'created_at' => $c->created_at->toDateString(),
        ]);

        return Inertia::render('Admin/Orders', [
            'orders' => $orders,
            'filters' => $request->only('status'),
        ]);
    }

    public function update(Request $request, Commission $commission): RedirectResponse
    {
        $request->validate([
            'status' => ['required', 'in:pending,approved,paid'],
        ]);

        $commission->update([
            'status' => $request->input('status'),
            'confirmed_at' => $request->input('status') === 'approved' ? now() : $commission->confirmed_at,
            'paid_at' => $request->input('status') === 'paid' ? now() : $commission->paid_at,
        ]);

        return back()->with('success', 'Cập nhật trạng thái thành công.');
    }
}
