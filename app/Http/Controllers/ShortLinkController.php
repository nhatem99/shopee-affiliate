<?php

namespace App\Http\Controllers;

use App\Exceptions\AffiliateScanException;
use App\Services\AffiliateLinkRewriterService;
use App\Services\ShortLinkService;
use App\Services\TrackingService;
use App\Services\UrlValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ShortLinkController extends Controller
{
    public function __construct(
        private UrlValidationService $urlValidator,
        private ShortLinkService $shortLinks,
        private AffiliateLinkRewriterService $rewriter,
        private TrackingService $tracking,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2000'],
            'source' => ['nullable', 'string', 'in:facebook,instagram,zalo,youtube'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'product_image' => ['nullable', 'url', 'max:2000'],
            'voucher_label' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $this->urlValidator->validateAffiliateRedirectUrl($validated['url']);
        } catch (AffiliateScanException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Log::info('ShortLinkController: người dùng bấm "Mua ngay"', [
            'source' => $validated['source'] ?? null,
            'product_name' => $validated['product_name'] ?? null,
            'salesoc_url' => $validated['url'],
        ]);

        $targetUrl = $this->rewriter->rewriteToOwnAffiliate($validated['url']);

        $link = $this->shortLinks->create(
            $targetUrl,
            $validated['source'] ?? null,
            $validated['product_name'] ?? null,
            $validated['product_image'] ?? null,
        );

        Log::info('ShortLinkController: đã tạo short-link', [
            'code' => $link->code,
            'target_url' => $targetUrl,
        ]);

        $this->tracking->log('voucher_select', $request, [
            'url' => $targetUrl,
            'source' => $validated['source'] ?? null,
            'product_name' => $validated['product_name'] ?? null,
            'voucher_code' => $validated['voucher_label'] ?? null,
        ]);

        return response()->json([
            'code' => $link->code,
            'short_url' => url('/go/'.$link->code),
        ]);
    }

    public function redirect(Request $request, string $code): RedirectResponse|Response
    {
        $link = $this->shortLinks->find($code);

        abort_if($link === null, 404);

        // Facebook/Zalo/Telegram... không hiển thị preview bằng link mình gửi — bot của họ tự
        // ghé thăm target_url (Shopee), đọc thẻ og:url CANONICAL (không có mmp_pid/mã giảm giá)
        // của Shopee rồi lấy chính URL đó thay cho link gốc khi hiển thị/redirect cho người dùng
        // cuối, khiến affiliate ID + mã giảm giá bị "bóc" mất trước khi người dùng kịp bấm.
        // => Với bot: trả HTML có og:url tự trỏ về chính /go/{code} (không đi theo target_url)
        // để bot không "thấy" được URL sạch của Shopee. Với người dùng thật: 302 như cũ.
        if (TrackingService::isBot($request->userAgent())) {
            return response()->view('short-link-preview', ['link' => $link]);
        }

        $this->shortLinks->trackClick($link);

        Log::info('ShortLinkController: redirect thật khi bấm link', [
            'code' => $code,
            'target_url' => $link->target_url,
        ]);

        $this->tracking->log('short_link_click', $request, ['url' => $link->target_url]);

        return redirect()->away($link->target_url, 302);
    }
}
