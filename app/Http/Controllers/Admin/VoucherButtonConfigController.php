<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VoucherButtonConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VoucherButtonConfigController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/VoucherButtonConfig', [
            'configs' => VoucherButtonConfig::orderBy('sort_order')->get(),
        ]);
    }

    public function update(Request $request, VoucherButtonConfig $voucherButtonConfig): RedirectResponse
    {
        $validated = $request->validate([
            // Null/empty string = use default API/fallback label (not an override).
            'label' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:99'],
            // is_featured behaves like a radio group: setting one source as featured
            // automatically removes the featured flag from all other sources.
            // This is intentional — only one source should carry the "Đề xuất" badge at a time.
            'is_featured' => ['required', 'boolean'],
        ]);

        // Treat empty string as null so the frontend placeholder label logic kicks in.
        $validated['label'] = $validated['label'] !== '' ? $validated['label'] : null;

        // Radio-group enforcement: when marking this source as featured, clear all others.
        if ($validated['is_featured']) {
            VoucherButtonConfig::where('id', '!=', $voucherButtonConfig->id)
                ->where('is_featured', true)
                ->update(['is_featured' => false]);
        }

        $voucherButtonConfig->update($validated);

        return back()->with('success', 'Đã lưu cấu hình nút '.ucfirst($voucherButtonConfig->source).'.');
    }
}
