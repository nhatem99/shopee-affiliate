<?php

namespace App\Services;

use App\Models\ApiConfig;
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

    public function generateLink(string $originalUrl): string
    {
        $config = ApiConfig::where('platform', 'accesstrade')
            ->where('is_active', true)
            ->first();

        if (!$config) {
            Log::info('AccessTrade not configured, returning original URL');
            return $originalUrl;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $config->app_secret,
            ])->timeout(10)->post($config->endpoint . '/link_generate', [
                'url' => $originalUrl,
            ]);

            if ($response->successful() && $response->json('data.url')) {
                return $response->json('data.url');
            }
        } catch (\Exception $e) {
            Log::warning('AccessTrade link generation failed: ' . $e->getMessage());
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
            'Authorization' => 'Token ' . $config->app_secret,
        ])->timeout(10)->get($config->endpoint . '/publishers/me');

        return $response->successful();
    }
}
