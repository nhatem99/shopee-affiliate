<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithdrawalController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_if($request->user()->isAdmin(), 403, 'Trang này dành cho khách hàng.');

        $data = $request->validate([
            'provider' => ['required', 'in:momo,zalopay'],
            'amount' => ['required', 'numeric', 'min:'.ProfileController::MIN_WITHDRAWAL],
        ]);

        DB::transaction(function () use ($request, $data) {
            /** @var User $user */
            $user = User::whereKey($request->user()->id)->lockForUpdate()->first();

            $account = $user->payoutAccounts()->where('provider', $data['provider'])->first();
            if (! $account) {
                throw ValidationException::withMessages([
                    'provider' => 'Bạn chưa thiết lập ví '.($data['provider'] === 'momo' ? 'MoMo' : 'ZaloPay').'.',
                ]);
            }

            if ($data['amount'] > $user->availableBalance()) {
                throw ValidationException::withMessages([
                    'amount' => 'Số tiền rút vượt quá số dư khả dụng.',
                ]);
            }

            Withdrawal::create([
                'user_id' => $user->id,
                'provider' => $account->provider,
                'account_number' => $account->account_number,
                'account_name' => $account->account_name,
                'amount' => $data['amount'],
                'status' => 'pending',
            ]);
        });

        return back()->with('success', 'Đã gửi yêu cầu rút tiền. Vui lòng chờ admin duyệt.');
    }
}
