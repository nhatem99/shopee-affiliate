<?php

namespace App\Http\Controllers;

use App\Models\PayoutAccount;
use App\Models\User;
use App\Services\MembershipTierService;
use App\Services\WalletHistoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public const MIN_WITHDRAWAL = 10000;

    public function show(Request $request, MembershipTierService $tiers): Response
    {
        $this->ensureNotAdmin($request);

        $user = $request->user();

        return Inertia::render('Profile', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                // "Thành viên từ Tháng X" ở đầu /profile. Ghép chuỗi tay thay vì
                // translatedFormat('F Y') — locale mặc định của app là 'en' (config/app.php),
                // đổi locale ảnh hưởng toàn app nên không đáng chỉ để có mỗi dòng này.
                'member_since' => 'Tháng '.$user->created_at->format('m/Y'),
            ],
            // Tổng quan không có form sửa ví, chỉ cần đọc để hiện trong modal rút tiền — cùng
            // dữ liệu với trang Thông tin cá nhân (info()) nên gộp lại một hàm.
            'payoutAccounts' => $this->payoutAccountsFor($user),
            'balance' => [
                'earned' => $user->approvedCommissionTotal(),
                'reserved' => $user->reservedWithdrawalTotal(),
                'available' => $user->availableBalance(),
                // Điều kiện rút thứ ba (ngoài mức tối thiểu và ví nhận tiền): thưởng người mới
                // không rút được một mình — xem WithdrawalController.
                'hasRealCashback' => $user->hasRealCashback(),
            ],
            // Hạng thành viên + tiến độ lên hạng của quý này. null = chương trình đang tắt,
            // thẻ hạng ở Tổng quan tự biến mất. Đây là nơi trang /hoan-tien hứa là sẽ "theo dõi
            // được tiến độ nâng hạng", nên nó phải có thật ở đây.
            'tier' => $tiers->enabled() ? $tiers->progressFor($user) : null,
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

    /**
     * Trang "Thông tin cá nhân" trong sidebar Tài khoản: hồ sơ + ví MoMo/ZaloPay nhận tiền.
     * Tách khỏi show() vì Tổng quan không cần form sửa hai thứ này, chỉ cần đọc payoutAccounts
     * để hiện trong modal rút tiền.
     */
    public function info(Request $request): Response
    {
        $this->ensureNotAdmin($request);

        $user = $request->user();

        return Inertia::render('Profile/PersonalInfo', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'payoutAccounts' => $this->payoutAccountsFor($user),
        ]);
    }

    /**
     * Trang "Mật khẩu & Bảo mật" trong sidebar Tài khoản. Chỉ cần biết tài khoản đã có mật khẩu
     * thật hay chưa để form ẩn/hiện ô "mật khẩu hiện tại" — xem updatePassword() bên dưới.
     */
    public function password(Request $request): Response
    {
        $this->ensureNotAdmin($request);

        return Inertia::render('Profile/Password', [
            'hasPassword' => $request->user()->hasUsablePassword(),
        ]);
    }

    public function walletHistory(Request $request, WalletHistoryService $history): Response
    {
        $this->ensureNotAdmin($request);

        $user = $request->user();

        return Inertia::render('WalletHistory', [
            'available' => $user->availableBalance(),
            'events' => $history->timeline($user),
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
     * Đổi mật khẩu, hoặc đặt mật khẩu lần đầu cho tài khoản chỉ đăng nhập qua Google.
     *
     * Chỉ bắt nhập mật khẩu cũ khi tài khoản đã có mật khẩu thật — khách Google không có gì
     * để nhập vào đó (xem User::hasUsablePassword()). 'current_password' là rule có sẵn của
     * Laravel, tự so với mật khẩu đang đăng nhập nên không cần tự viết so sánh.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $this->ensureNotAdmin($request);

        $user = $request->user();
        $isFirstTime = ! $user->hasUsablePassword();

        $data = $request->validate([
            'current_password' => $isFirstTime ? [] : ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $user->update(['password' => $data['password']]);

        return back()->with('success', $isFirstTime ? 'Đã đặt mật khẩu.' : 'Đã đổi mật khẩu.');
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    private function payoutAccountsFor(User $user): Collection
    {
        return $user->payoutAccounts()
            ->get()
            ->keyBy('provider')
            ->map(fn ($a) => [
                'provider' => $a->provider,
                'account_number' => $a->account_number,
                'account_name' => $a->account_name,
            ]);
    }

    private function ensureNotAdmin(Request $request): void
    {
        abort_if($request->user()->isAdmin(), 403, 'Trang này dành cho khách hàng.');
    }
}
