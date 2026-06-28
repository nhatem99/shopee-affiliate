<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WithdrawalController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Withdrawal::with('user')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $withdrawals = $query->paginate(20)->through(fn ($w) => [
            'id' => $w->id,
            'user' => $w->user?->name,
            'provider' => $w->provider,
            'account_number' => $w->account_number,
            'account_name' => $w->account_name,
            'amount' => $w->amount,
            'status' => $w->status,
            'transaction_ref' => $w->transaction_ref,
            'admin_note' => $w->admin_note,
            'created_at' => $w->created_at->toDateString(),
        ]);

        return Inertia::render('Admin/Withdrawals', [
            'withdrawals' => $withdrawals,
            'filters' => $request->only('status'),
        ]);
    }

    public function update(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:approved,completed,rejected'],
            'transaction_ref' => ['required_if:status,completed', 'nullable', 'string', 'max:255'],
            'admin_note' => ['nullable', 'string', 'max:255'],
        ]);

        $allowed = [
            'pending' => ['approved', 'rejected'],
            'approved' => ['completed', 'rejected'],
        ];

        if (! in_array($data['status'], $allowed[$withdrawal->status] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => 'Không thể chuyển trạng thái từ "'.$withdrawal->status.'" sang "'.$data['status'].'".',
            ]);
        }

        $withdrawal->update([
            'status' => $data['status'],
            'transaction_ref' => $data['status'] === 'completed' ? $data['transaction_ref'] : $withdrawal->transaction_ref,
            'admin_note' => $data['status'] === 'rejected' ? ($data['admin_note'] ?? null) : $withdrawal->admin_note,
            'approved_at' => $data['status'] === 'approved' ? now() : $withdrawal->approved_at,
            'completed_at' => $data['status'] === 'completed' ? now() : $withdrawal->completed_at,
            'rejected_at' => $data['status'] === 'rejected' ? now() : $withdrawal->rejected_at,
        ]);

        return back()->with('success', 'Cập nhật yêu cầu rút tiền thành công.');
    }
}
