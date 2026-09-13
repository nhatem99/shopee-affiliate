<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class GoogleController extends Controller
{
    public function __construct(private TrackingService $tracking) {}

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException $e) {
            // Thường do mở trang ở host này (127.0.0.1) nhưng Google trả về host khác (localhost)
            // nên cookie session không khớp → state không hợp lệ.
            Log::warning('Google login: invalid state', ['host' => $request->getHost()]);

            return redirect()->route('login')->with('error', 'Phiên đăng nhập Google không hợp lệ. Hãy mở lại trang đăng nhập rồi thử lại.');
        } catch (\Exception $e) {
            Log::error('Google login failed', ['error' => $e->getMessage(), 'class' => get_class($e)]);

            return redirect()->route('login')->with('error', 'Đăng nhập Google thất bại. Vui lòng thử lại.');
        }

        // Ưu tiên khớp theo google_id (đã liên kết trước đó); nếu chưa, khớp theo email đã xác
        // minh để gộp vào tài khoản email/mật khẩu sẵn có thay vì tạo trùng tài khoản.
        $user = User::where('google_id', $googleUser->getId())->first();

        if (! $user) {
            $user = User::where('email', $googleUser->getEmail())->first();

            if ($user) {
                $user->update(['google_id' => $googleUser->getId()]);
            }
        }

        if (! $user) {
            // 'role' không nằm trong $fillable của User — cột DB có default 'user' sẵn.
            $user = User::create([
                'name' => $googleUser->getName() ?: $googleUser->getEmail(),
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'password' => '',
            ]);

            $user->assignRole('user');
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        $this->tracking->logSecurityEvent('login_success', $request, ['source' => 'google']);

        $intended = $user->isAdmin() ? route('admin.dashboard') : route('home');

        return redirect()->intended($intended);
    }
}
