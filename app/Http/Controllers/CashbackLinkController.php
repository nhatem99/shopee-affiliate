<?php

namespace App\Http\Controllers;

use App\Exceptions\AffiliateScanException;
use App\Services\CashbackLinkService;
use App\Services\TrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ô dán link trên trang chủ khi web tắt lấy mã: trả link hoàn tiền (CashbackLinkService), render
 * thẳng trang chủ hoàn tiền (Pages/HomeCashback.vue) với prop `cashbackResult`.
 */
class CashbackLinkController extends Controller
{
    public function __construct(
        private CashbackLinkService $links,
        private HomeController $home,
        private TrackingService $tracking,
    ) {}

    public function store(Request $request): Response|RedirectResponse
    {
        $request->validate([
            'url' => ['required', 'string', 'max:2048'],
        ]);

        // App và Zalo hay copy kèm lời dẫn ("Xem sản phẩm ... https://s.shopee.vn/...").
        $raw = (string) $request->input('url');
        $url = preg_match('#https?://\S+#i', $raw, $m) ? rtrim($m[0], '.,;!?)') : trim($raw);

        // Lỗi thì về THẲNG trang chủ, không back() — cùng lý do với ShopeeVoucherController::resolve.
        try {
            $result = $this->links->forShopee($url, $request->user());
        } catch (AffiliateScanException $e) {
            return redirect()->route('home')->withErrors(['link_url' => $e->getMessage()]);
        }

        // Cùng sự kiện với lượt dán link lấy mã để thống kê "Dán link sản phẩm" không đứt khi đổi
        // chế độ; `source` cho biết lượt này ra link hoàn tiền.
        $this->tracking->log('url_paste', $request, [
            'url' => $url,
            'platform' => 'shopee',
            'product_name' => $result['product']['product_name'] ?? null,
            'source' => 'cashback',
        ]);

        return Inertia::render('HomeCashback', [
            ...$this->home->props($request),
            'cashbackResult' => $result,
        ]);
    }
}
