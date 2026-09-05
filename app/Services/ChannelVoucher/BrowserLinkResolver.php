<?php

namespace App\Services\ChannelVoucher;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gọi Node service chạy Playwright (nguồn: deploy/browser-resolver/) để mở bài đăng
 * Facebook/Instagram bằng Chromium thật, bấm vào link của mình rồi trả về URL đích.
 *
 * Vì sao phải là trình duyệt thật chứ không phải Http::get() theo redirect:
 *  1. Trang comment của Facebook chỉ xem được khi đã đăng nhập — service giữ sẵn cookie phiên.
 *  2. Shopee chỉ đúc link có mã khi cú bấm đến TỪ TRONG nền tảng (referer/ngữ cảnh Facebook).
 *     Tự đi theo chuỗi redirect từ server là mất đúng ngữ cảnh đó, và mất luôn cái mã.
 */
class BrowserLinkResolver
{
    /**
     * @param  string  $pageUrl  permalink của comment (FB) hoặc của media (IG)
     * @param  string  $marker  chuỗi duy nhất có trong link cần bấm, để không bấm nhầm link của
     *                          comment người khác trên cùng bài đăng
     * @return array{final_url: string, matched_href: ?string, duration_ms: int}|null
     */
    public function resolve(string $pageUrl, string $marker, int $timeoutSeconds): ?array
    {
        $base = rtrim((string) config('services.browser_resolver.url'), '/');

        try {
            // Cộng thêm 5s so với hạn của trình duyệt: để service kịp trả về lỗi "hết giờ" tử tế
            // thay vì bị PHP cắt kết nối trước và mình chỉ thấy một ConnectionException mù mờ.
            $response = Http::withHeaders([
                'X-Resolver-Secret' => (string) config('services.browser_resolver.secret'),
            ])->timeout($timeoutSeconds + 5)->post($base.'/resolve', [
                'url' => $pageUrl,
                'marker' => $marker,
                'timeout_ms' => $timeoutSeconds * 1000,
            ]);
        } catch (\Exception $e) {
            Log::error('BrowserLinkResolver: không gọi được browser service', [
                'url' => $base,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful() || ! $response->json('final_url')) {
            Log::error('BrowserLinkResolver: browser service không lấy được link', [
                'status' => $response->status(),
                'page_url' => $pageUrl,
                'marker' => $marker,
                'reason' => $response->json('error') ?? $response->body(),
            ]);

            return null;
        }

        return [
            'final_url' => (string) $response->json('final_url'),
            'matched_href' => $response->json('matched_href'),
            'duration_ms' => (int) $response->json('duration_ms', 0),
        ];
    }

    /**
     * Service còn sống không và phiên Facebook còn đăng nhập không — hai thứ hỏng thầm lặng
     * nhất của cơ chế này, nên phải hỏi được trực tiếp thay vì suy đoán từ log.
     *
     * @return array{ok: bool, logged_in: bool, detail: string}|null
     */
    public function health(): ?array
    {
        $base = rtrim((string) config('services.browser_resolver.url'), '/');

        try {
            $response = Http::withHeaders([
                'X-Resolver-Secret' => (string) config('services.browser_resolver.secret'),
            ])->timeout(10)->get($base.'/health');
        } catch (\Exception $e) {
            return ['ok' => false, 'logged_in' => false, 'detail' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'logged_in' => false, 'detail' => 'HTTP '.$response->status()];
        }

        return [
            'ok' => true,
            'logged_in' => (bool) $response->json('logged_in'),
            'detail' => (string) $response->json('detail', ''),
        ];
    }
}
