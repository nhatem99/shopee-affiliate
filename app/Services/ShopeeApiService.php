<?php

namespace App\Services;

use App\Models\ApiConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopeeApiService
{
    private function getConfig(): ?ApiConfig
    {
        return ApiConfig::where('platform', 'shopee')
            ->where('is_active', true)
            ->first();
    }

    private function buildSignature(string $path, int $timestamp, ApiConfig $config): string
    {
        $partnerId = $config->app_id;
        $partnerKey = $config->app_secret;
        $base = "{$partnerId}{$path}{$timestamp}";
        return hash_hmac('sha256', $base, $partnerKey);
    }

    public function getProductInfo(string $itemId, string $shopId): array
    {
        $config = $this->getConfig();
        if (!$config) {
            return $this->getMockProductInfo($itemId);
        }

        $path = '/api/v2/item/get';
        $timestamp = time();
        $sign = $this->buildSignature($path, $timestamp, $config);

        try {
            $response = Http::timeout(10)->get($config->endpoint . $path, [
                'partner_id' => (int) $config->app_id,
                'timestamp' => $timestamp,
                'sign' => $sign,
                'shop_id' => (int) $shopId,
                'item_id' => (int) $itemId,
            ]);

            if ($response->successful()) {
                $item = $response->json('response.item');
                return [
                    'product_name' => $item['item_name'] ?? null,
                    'product_image' => $item['images'][0] ?? null,
                    'original_price' => ($item['price_info'][0]['original_price'] ?? 0) / 100000,
                    'discounted_price' => ($item['price_info'][0]['current_price'] ?? 0) / 100000,
                    'discount_percent' => $item['price_info'][0]['discount_percentage'] ?? 0,
                    'sold_count' => $item['sold'] ?? 0,
                    'rating' => $item['item_rating']['rating_star'] ?? 0,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Shopee API error: ' . $e->getMessage());
        }

        return $this->getMockProductInfo($itemId);
    }

    public function getVouchers(string $shopId): array
    {
        $config = $this->getConfig();
        if (!$config) {
            return $this->getMockVouchers();
        }

        $path = '/api/v2/voucher/list';
        $timestamp = time();
        $sign = $this->buildSignature($path, $timestamp, $config);

        try {
            $response = Http::timeout(10)->get($config->endpoint . $path, [
                'partner_id' => (int) $config->app_id,
                'timestamp' => $timestamp,
                'sign' => $sign,
                'shop_id' => (int) $shopId,
                'status' => 1,
            ]);

            if ($response->successful()) {
                $vouchers = $response->json('response.voucher_list') ?? [];
                return array_map(fn($v) => [
                    'code' => $v['voucher_code'],
                    'discount_type' => $v['discount_type'] === 2 ? 'percent' : 'flat',
                    'discount_value' => $v['discount_amount'] ?? $v['discount_percentage'] ?? 0,
                    'minimum_order' => $v['min_basket_price'] ?? 0,
                    'expires_at' => $v['end_time'] ? date('Y-m-d H:i:s', $v['end_time']) : null,
                    'is_freeship' => false,
                    'source' => 'shopee',
                ], $vouchers);
            }
        } catch (\Exception $e) {
            Log::warning('Shopee voucher API error: ' . $e->getMessage());
        }

        return $this->getMockVouchers();
    }

    public function testConnection(ApiConfig $config): bool
    {
        $path = '/api/v2/shop/get_shop_info';
        $timestamp = time();
        $sign = $this->buildSignature($path, $timestamp, $config);

        $response = Http::timeout(10)->get($config->endpoint . $path, [
            'partner_id' => (int) $config->app_id,
            'timestamp' => $timestamp,
            'sign' => $sign,
        ]);

        return $response->successful();
    }

    private function getMockProductInfo(string $itemId): array
    {
        return [
            'product_name' => "Sản phẩm #{$itemId}",
            'product_image' => null,
            'original_price' => 299000,
            'discounted_price' => 199000,
            'discount_percent' => 33,
            'sold_count' => 1240,
            'rating' => 4.8,
        ];
    }

    private function getMockVouchers(): array
    {
        return [
            [
                'code' => 'SHOPEE50K',
                'discount_type' => 'flat',
                'discount_value' => 50000,
                'minimum_order' => 200000,
                'expires_at' => now()->addDays(7)->toDateTimeString(),
                'is_freeship' => false,
                'source' => 'shopee',
            ],
            [
                'code' => 'FREESHIP',
                'discount_type' => 'freeship',
                'discount_value' => 30000,
                'minimum_order' => 0,
                'expires_at' => now()->addDays(3)->toDateTimeString(),
                'is_freeship' => true,
                'source' => 'shopee',
            ],
        ];
    }
}
