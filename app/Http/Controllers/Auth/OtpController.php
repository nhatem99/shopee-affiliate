<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class OtpController extends Controller
{
    private const MAX_VERIFY_ATTEMPTS = 5;

    public function __construct(private TrackingService $tracking) {}

    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $phone = $request->input('phone');
        $otp = (string) random_int(100000, 999999);

        // 'role' không nằm trong $fillable của User — cột DB có default 'user' sẵn.
        $user = User::firstOrCreate(
            ['phone' => $phone],
            ['name' => $phone, 'email' => $phone.'@otp.local', 'password' => '']
        );

        $user->update([
            'otp' => Hash::make($otp),
            'otp_expires_at' => now()->addMinutes(5),
        ]);

        Cache::forget($this->attemptsKey($user->id));

        // In production, replace this with Zalo OA or SMS API
        if (app()->environment(['local', 'testing'])) {
            Log::info("OTP for {$phone}: {$otp}");
        }

        return response()->json(['message' => 'Mã OTP đã được gửi.']);
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'phone' => ['required', 'string'],
            'otp' => ['required', 'string', 'size:6'],
        ]);

        $user = User::where('phone', $request->input('phone'))->first();

        if (! $user || ! $user->otp_expires_at || $user->otp_expires_at->isPast()) {
            return back()->withErrors(['otp' => 'Mã OTP đã hết hạn. Vui lòng yêu cầu mã mới.']);
        }

        $attemptsKey = $this->attemptsKey($user->id);

        if ((int) Cache::get($attemptsKey, 0) >= self::MAX_VERIFY_ATTEMPTS) {
            $this->tracking->logSecurityEvent('otp_verify_failed', $request, [
                'metadata' => ['phone' => $user->phone, 'reason' => 'locked'],
            ]);

            return back()->withErrors(['otp' => 'Bạn đã nhập sai quá nhiều lần. Vui lòng yêu cầu mã OTP mới.']);
        }

        if (! Hash::check($request->input('otp'), $user->otp)) {
            Cache::put($attemptsKey, (int) Cache::get($attemptsKey, 0) + 1, now()->addMinutes(5));
            $this->tracking->logSecurityEvent('otp_verify_failed', $request, [
                'metadata' => ['phone' => $user->phone],
            ]);

            return back()->withErrors(['otp' => 'Mã OTP không đúng.']);
        }

        Cache::forget($attemptsKey);
        $user->update(['otp' => null, 'otp_expires_at' => null]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('home');
    }

    private function attemptsKey(int $userId): string
    {
        return "otp_attempts:{$userId}";
    }
}
