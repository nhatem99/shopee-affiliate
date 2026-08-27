<?php

namespace App\Services;

use App\Models\ApiConfig;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AccessTradeService
{
    private array $cashbackRates = [
        'shopee' => 0.05,
        'lazada' => 0.07,
        'tiki' => 0.06,
        'tiktok' => 0.04,
    ];

    /** Đăng ký request sinh affiliate link vào pool dùng chung của orchestrator (chạy song song với các API khác). */
    public function registerPoolRequest(Pool $pool, string $originalUrl): bool
    {
        $config = ApiConfig::where('platform', 'accesstrade')
            ->where('is_active', true)
            ->first();

        if (! $config) {
            Log::info('AccessTrade not configured, returning original URL');

            return false;
        }

        $pool->as('accesstrade')->withHeaders([
            'Authorization' => 'Token '.$config->app_secret,
        ])->timeout(10)->post($config->endpoint.'/link_generate', [
            'url' => $originalUrl,
        ]);

        return true;
    }

    /** Đọc kết quả từ pool ở trên; trả về URL gốc nếu không sinh được affiliate link. */
    public function parsePoolResponse(mixed $response, string $originalUrl): string
    {
        if ($response instanceof Response && $response->successful() && $response->json('data.url')) {
            return $response->json('data.url');
        }

        if ($response instanceof \Throwable) {
            Log::warning('AccessTrade link generation failed: '.$response->getMessage());
        }

        return $originalUrl;
    }

    public function getCashbackRate(string $platform): float
    {
        return $this->cashbackRates[$platform] ?? 0.03;
    }

    public function testConnection(ApiConfig $config): bool
    {
        $response = Http::withHeaders([
            'Authorization' => 'Token '.$config->app_secret,
        ])->timeout(10)->get($config->endpoint.'/publishers/me');

        return $response->successful();
    }
}
