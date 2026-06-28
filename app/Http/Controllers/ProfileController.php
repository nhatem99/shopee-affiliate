<?php

namespace App\Http\Controllers;

use App\Models\PayoutAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public const MIN_WITHDRAWAL = 50000;

    public function show(Request $request): Response
    {
        $this->ensureNotAdmin($request);

        $user = $request->user();

        $payoutAccounts = $user->payoutAccounts()
            ->get()
            ->keyBy('provider')
            ->map(fn ($a) => [
                'provider' => $a->provider,
                'account_number' => $a->account_number,
                'account_name' => $a->account_name,
            ]);

        return Inertia::render('Profile', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'payoutAccounts' => $payoutAccounts,
            'balance' => [
                'earned' => $user->approvedCommissionTotal(),
                'reserved' => $user->reservedWithdrawalTotal(),
                'available' => $user->availableBalance(),
            ],
            'withdrawals' => $user->withdrawals()->latest()->get()->map(fn ($w) => [
                'id' => $w->id,
                'provider' => $w->provider,
                'account_number' => $w->account_number,
                'account_name' => $w->account_name,
                'amount' => $w->amount,
                'status' => $w->status,
                'transaction_ref' => $w->transaction_ref,
                'admin_note' => $w->admin_note,
                'created_at' => $w->created_at->toDateTimeString(),
            ]),
            'minWithdrawal' => self::MIN_WITHDRAWAL,
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $this->ensureNotAdmin($request);

        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($user->id)],
        ]);

        $user->update($data);

        return back()->with('success', 'Cập nhật thông tin thành công.');
    }

    public function storePayoutAccount(Request $request): RedirectResponse
    {
        $this->ensureNotAdmin($request);

        $data = $request->validate([
            'provider' => ['required', 'in:momo,zalopay'],
            'account_number' => ['required', 'string', 'regex:/^[0-9]{9,11}$/'],
            'account_name' => ['required', 'string', 'max:255'],
        ]);

        PayoutAccount::updateOrCreate(
            ['user_id' => $request->user()->id, 'provider' => $data['provider']],
            ['account_number' => $data['account_number'], 'account_name' => $data['account_name']],
        );

        return back()->with('success', 'Đã lưu thông tin ví nhận tiền.');
    }

    /**
     * Trang tài khoản người dùng dành cho khách hàng — admin không có quyền truy cập.
     */
    private function ensureNotAdmin(Request $request): void
    {
        abort_if($request->user()->isAdmin(), 403, 'Trang này dành cho khách hàng.');
    }
}
