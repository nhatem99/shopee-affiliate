<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WelcomeBonusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function __construct(private readonly WelcomeBonusService $welcome) {}

    public function show(): Response
    {
        return Inertia::render('Auth/Register', [
            // 0 khi đang tắt — trang chỉ nhắc tới thưởng khi thật sự có.
            'welcomeBonus' => $this->welcome->promisedAmount(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users'],
            'password' => ['required', Password::min(8)->mixedCase()->numbers(), 'confirmed'],
        ]);

        // 'role' không nằm trong $fillable của User — cột DB có default 'user' sẵn,
        // không cần gán tay, và không route nào có thể tự ý set role qua mass-assignment.
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => $validated['password'],
        ]);

        $user->assignRole('user');
        $this->welcome->welcome($user, 'email');

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('home');
    }
}
