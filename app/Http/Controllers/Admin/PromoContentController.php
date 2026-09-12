<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\PromoContentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PromoContentController extends Controller
{
    public function __construct(private PromoContentService $promo) {}

    public function index(): Response
    {
        return Inertia::render('Admin/PromoContent', [
            'templates' => $this->promo->templates(),
            'cashbackOn' => $this->promo->cashbackOn(),
            'cashbackRate' => $this->promo->tokens()['cashbackRate'],
            'topPercent' => $this->promo->topPercent(),
            'secondPercent' => $this->promo->secondPercent(),
            'siteUrl' => $this->promo->tokens()['siteUrl'],
        ]);
    }

    /**
     * Hai mức phần trăm mã giảm giá dùng trong bài đăng.
     *
     * Đây là con số của NGUỒN CẤP MÃ, không phải thứ hệ thống này tự tính ra được — nên admin
     * phải tự đặt và tự chịu trách nhiệm giữ nó đúng. Sửa ở đây là mọi mẫu đổi theo ngay, thay
     * vì phải sửa từng bài đăng đã copy đi.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'top_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'second_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]);

        if (array_key_exists('top_percent', $validated)) {
            Setting::set(PromoContentService::TOP_PERCENT_KEY, (string) $validated['top_percent']);
        }

        if (array_key_exists('second_percent', $validated)) {
            Setting::set(PromoContentService::SECOND_PERCENT_KEY, (string) $validated['second_percent']);
        }

        return back()->with('success', 'Đã lưu mức giảm dùng trong bài đăng.');
    }
}
