<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class OtpController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $phone = $request->input('phone');
        $otp = (string) random_int(100000, 999999);

        $user = User::firstOrCreate(
            ['phone' => $phone],
            ['name' => $phone, 'email' => $phone . '@otp.local', 'password' => '', 'role' => 'user']
        );

        $user->update([
            'otp' => Hash::make($otp),
            'otp_expires_at' => now()->addMinutes(5),
        ]);

        // In production, replace this with Zalo OA or SMS API
        Log::info("OTP for {$phone}: {$otp}");

        return response()->json(['message' => 'Mã OTP đã được gửi.']);
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'phone' => ['required', 'string'],
            'otp' => ['required', 'string', 'size:6'],
        ]);

        $user = User::where('phone', $request->input('phone'))->first();

        if (!$user || !$user->otp_expires_at || $user->otp_expires_at->isPast()) {
            return back()->withErrors(['otp' => 'Mã OTP đã hết hạn. Vui lòng yêu cầu mã mới.']);
        }

        if (!Hash::check($request->input('otp'), $user->otp)) {
            return back()->withErrors(['otp' => 'Mã OTP không đúng.']);
        }

        $user->update(['otp' => null, 'otp_expires_at' => null]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('home');
    }
}
