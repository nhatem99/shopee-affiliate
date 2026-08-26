<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Settings', [
            'customerAuthEnabled' => Setting::getBool('customer_auth_enabled', true),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_auth_enabled' => ['required', 'boolean'],
        ]);

        Setting::set('customer_auth_enabled', $validated['customer_auth_enabled'] ? '1' : '0');

        return back()->with('success', 'Đã lưu cài đặt.');
    }
}
