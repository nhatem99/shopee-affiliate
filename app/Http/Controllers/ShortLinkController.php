<?php

namespace App\Http\Controllers;

use App\Exceptions\AffiliateScanException;
use App\Services\AffiliateLinkRewriterService;
use App\Services\ShortLinkService;
use App\Services\TrackingService;
use App\Services\UrlValidationService;
use App\Services\VoucherRefService;
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
        private VoucherRefService $refs,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // 'ref' là token mờ do VoucherRefService::mask() phát ra — URL affiliate thật (link
            // đã đúc mã) không bao giờ đi qua request body, tránh lộ URL gốc cho ai xem được
            // request này (Network tab, log trung gian...).
            'ref' => ['required', 'string', 'size:32'],
            'source' => ['nullable', 'string', 'in:facebook,instagram,zalo,youtube'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'product_image' => ['nullable', 'url', 'max:2000'],
            'voucher_label' => ['nullable', 'string', 'max:100'],
        ]);

        $url = $this->refs->resolve($validated['ref']);

        if (! $url) {
            return response()->json(['message' => 'Link đã hết hạn, vui lòng tải lại trang và thử lại.'], 422);
        }

        try {
            $this->urlValidator->validateAffiliateRedirectUrl($url);
        } catch (AffiliateScanException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Log::info('ShortLinkController: người dùng bấm "Mua ngay"', [
            'source' => $validated['source'] ?? null,
            'product_name' => $validated['product_name'] ?? null,
            // Link nguồn do App\Services\ChannelVoucher đúc ra — vẫn mang mmp_pid của KOL.
            'source_url' => $url,
        ]);

        $targetUrl = $this->rewriter->rewriteToOwnAffiliate($url);

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

        // Đã tắt tạm bọc intent:// (mở thẳng app Shopee trên Android) — gây lỗi 502 thật trên
        // production ngay sau khi bấm link. Nguyên nhân đang được điều tra (nghi do hạ tầng
        // proxy/CDN phía trước xử lý Location header khác với local); quay lại redirect thẳng
        // như trước cho tới khi tìm ra và kiểm chứng được cách làm đúng.
        return redirect()->away($link->target_url, 302);
    }
}
