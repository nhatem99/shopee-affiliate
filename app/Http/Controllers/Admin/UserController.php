<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $users = $this->filteredQuery($request)
            ->withCount('affiliateLinks')
            // Tính bằng SQL thay vì gọi approvedCommissionTotal()/reservedWithdrawalTotal() trên
            // từng dòng: 20 user/trang x 2 quan hệ = 40 truy vấn thừa cho mỗi lần mở trang.
            ->withSum(['commissions as approved_commission_total' => fn (Builder $q) => $q->where('status', 'approved')], 'amount')
            ->withSum(['withdrawals as reserved_withdrawal_total' => fn (Builder $q) => $q->whereIn('status', ['pending', 'approved', 'completed'])], 'amount')
            ->latest()
            ->paginate(20)
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'sub_id' => $u->sub_id,
                'role' => $u->role,
                'links_count' => $u->affiliate_links_count,
                'approved_commission' => (float) $u->approved_commission_total,
                'available_balance' => (float) $u->approved_commission_total - (float) $u->reserved_withdrawal_total,
                'banned_at' => $u->banned_at?->toDateTimeString(),
                'banned_reason' => $u->banned_reason,
                'created_at' => $u->created_at->toDateString(),
            ])
            ->withQueryString();

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => [
                'q' => $request->input('q', ''),
                'role' => $request->input('role', ''),
                'status' => $request->input('status', ''),
            ],
            'stats' => [
                'total' => User::count(),
                'admins' => User::where('role', 'admin')->count(),
                'banned' => User::whereNotNull('banned_at')->count(),
                'new_30d' => User::where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'currentUserId' => $request->user()->id,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($user->id)],
        ]);

        $user->update($data);

        return back()->with('success', 'Đã cập nhật thông tin tài khoản.');
    }

    /**
     * Đổi quyền. Cập nhật CẢ cột users.role lẫn role của Spatie: AdminMiddleware đọc cột role
     * (qua isAdmin()), còn các chỗ dùng can()/hasRole() đọc bảng của Spatie — lệch nhau là sinh
     * ra tài khoản "nửa admin" rất khó lần ra.
     */
    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'in:user,admin'],
        ]);

        // Tự hạ quyền chính mình là cách nhanh nhất để khoá cả nhà ngoài cửa khi chỉ còn một admin.
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'role' => 'Không thể tự đổi quyền của chính mình.',
            ]);
        }

        $user->forceFill(['role' => $data['role']])->save();
        $user->syncRoles([$data['role']]);

        return back()->with('success', 'Đã đổi quyền tài khoản.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', Password::min(8)->mixedCase()->numbers()],
        ]);

        $user->update(['password' => $data['password']]);

        return back()->with('success', 'Đã đặt lại mật khẩu.');
    }

    public function ban(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'banned_reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'banned_reason' => 'Không thể tự khoá tài khoản của chính mình.',
            ]);
        }

        if ($user->isAdmin()) {
            throw ValidationException::withMessages([
                'banned_reason' => 'Hạ quyền tài khoản quản trị trước khi khoá.',
            ]);
        }

        $user->forceFill([
            'banned_at' => now(),
            'banned_reason' => $data['banned_reason'] ?? null,
        ])->save();

        return back()->with('success', 'Đã khoá tài khoản.');
    }

    public function unban(User $user): RedirectResponse
    {
        $user->forceFill(['banned_at' => null, 'banned_reason' => null])->save();

        return back()->with('success', 'Đã mở khoá tài khoản.');
    }

    private function filteredQuery(Request $request): Builder
    {
        return User::query()
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                $term = '%'.$request->string('q')->trim().'%';

                $query->where(fn (Builder $q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('sub_id', 'like', $term));
            })
            ->when($request->input('role') !== null && $request->input('role') !== '',
                fn (Builder $q) => $q->where('role', $request->input('role')))
            ->when($request->input('status') === 'banned', fn (Builder $q) => $q->whereNotNull('banned_at'))
            ->when($request->input('status') === 'active', fn (Builder $q) => $q->whereNull('banned_at'));
    }
}
