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

    public function redirect(Request $request, string $code): RedirectResponse
    {
        $targetUrl = $this->shortLinks->resolveAndTrack($code);

        abort_if($targetUrl === null, 404);

        Log::info('ShortLinkController: redirect thật khi bấm link', [
            'code' => $code,
            'target_url' => $targetUrl,
        ]);

        $this->tracking->log('short_link_click', $request, ['url' => $targetUrl]);

        return redirect()->away($targetUrl, 302);
    }
}
