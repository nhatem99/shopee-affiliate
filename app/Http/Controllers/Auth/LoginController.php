<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function __construct(private TrackingService $tracking) {}

    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            $this->tracking->logSecurityEvent('login_failed', $request, [
                'metadata' => ['email' => $credentials['email']],
            ]);

            throw ValidationException::withMessages([
                'email' => 'Email hoặc mật khẩu không đúng.',
            ]);
        }

        // Nói thẳng lý do ngay tại form: EnsureUserNotBanned cũng chặn được ca này, nhưng nó chỉ
        // đá về /login trắng trơn — người bị khoá sẽ thử lại mãi vì tưởng gõ sai mật khẩu.
        if (Auth::user()->isBanned()) {
            $reason = Auth::user()->banned_reason;

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $this->tracking->logSecurityEvent('login_banned', $request, [
                'metadata' => ['email' => $credentials['email']],
            ]);

            throw ValidationException::withMessages([
                'email' => 'Tài khoản đã bị khoá.'.($reason ? ' Lý do: '.$reason : ''),
            ]);
        }

        if (Auth::user()->isAdmin()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'Tài khoản quản trị vui lòng đăng nhập tại /admin/login.',
            ]);
        }

        $request->session()->regenerate();
        $this->tracking->logSecurityEvent('login_success', $request);

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
