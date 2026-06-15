<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformVoucher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VoucherController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Vouchers', [
            'vouchers' => PlatformVoucher::latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'platform'      => ['required', 'in:shopee,lazada,tiki,tiktok,all'],
            'source'        => ['required', 'in:facebook,youtube,manual'],
            'code'          => ['required', 'string', 'max:100'],
            'title'         => ['nullable', 'string', 'max:200'],
            'discount_type' => ['required', 'in:flat,percent,freeship'],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'minimum_order' => ['nullable', 'numeric', 'min:0'],
            'expires_at'    => ['nullable', 'date'],
            'is_active'     => ['boolean'],
        ]);

        PlatformVoucher::create($data);

        return back();
    }

    public function update(Request $request, PlatformVoucher $voucher): RedirectResponse
    {
        $data = $request->validate([
            'platform'      => ['required', 'in:shopee,lazada,tiki,tiktok,all'],
            'source'        => ['required', 'in:facebook,youtube,manual'],
            'code'          => ['required', 'string', 'max:100'],
            'title'         => ['nullable', 'string', 'max:200'],
            'discount_type' => ['required', 'in:flat,percent,freeship'],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'minimum_order' => ['nullable', 'numeric', 'min:0'],
            'expires_at'    => ['nullable', 'date'],
            'is_active'     => ['boolean'],
        ]);

        $voucher->update($data);

        return back();
    }

    public function destroy(PlatformVoucher $voucher): RedirectResponse
    {
        $voucher->delete();

        return back();
    }
}
