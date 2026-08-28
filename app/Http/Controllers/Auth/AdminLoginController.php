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

class AdminLoginController extends Controller
{
    public function __construct(private TrackingService $tracking) {}

    public function show(): Response
    {
        return Inertia::render('Auth/AdminLogin');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            $this->tracking->logSecurityEvent('admin_login_failed', $request, [
                'metadata' => ['email' => $credentials['email']],
            ]);

            throw ValidationException::withMessages([
                'email' => 'Email hoặc mật khẩu không đúng.',
            ]);
        }

        if (! Auth::user()->isAdmin()) {
            $this->tracking->logSecurityEvent('admin_login_denied', $request, [
                'metadata' => ['email' => $credentials['email']],
            ]);

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'Tài khoản này không có quyền quản trị.',
            ]);
        }

        $request->session()->regenerate();
        $this->tracking->logSecurityEvent('login_success', $request);

        return redirect()->intended(route('admin.dashboard'));
    }
}
