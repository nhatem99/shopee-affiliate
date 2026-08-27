<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lấy giá/tên/ảnh sản phẩm Shopee thật qua API proxy bên thứ 3 (data.addlivetag.com).
 * Gọi thẳng shopee.vn/api/v4/item/get từ server luôn bị anti-bot chặn (error 90309999) —
 * dịch vụ này đã xử lý sẵn phần đó. Không chính thống, tự giới hạn phi thương mại
 * trong response (legalNotice.nonCommercialOnly) — dùng có ý thức về rủi ro, không có SLA.
 */
class ShopeeProductLookupService
{
    public const BASE_URL = 'https://data.addlivetag.com/product-data/product-data.php';

    public function getByItemId(string $itemId): ?array
    {
        try {
            return $this->parseResponse(Http::timeout(10)->get(self::BASE_URL, ['item_id' => $itemId]));
        } catch (\Exception $e) {
            Log::warning('ShopeeProductLookupService error: '.$e->getMessage());

            return null;
        }
    }

    /** Đọc response (đồng bộ hoặc lấy ra từ pool song song) thành metadata sản phẩm. */
    public function parseResponse(mixed $response): ?array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        $info = $response->json('productInfo');
        if (! $info || empty($info['productName'])) {
            return null;
        }

        $discounted = (float) ($info['price'] ?? 0);
        $original = (float) ($info['latestPriceHistory']['originalPrice'] ?? $discounted);

        return [
            'product_name' => $info['productName'],
            'product_image' => $info['imageUrl'] ?? null,
            'original_price' => max($original, $discounted),
            'discounted_price' => $discounted,
            'discount_percent' => (int) ($info['latestPriceHistory']['discountPercent'] ?? 0),
            'sold_count' => (int) ($info['sales'] ?? 0),
            'rating' => (float) ($info['rating'] ?? 0),
            // Tỷ lệ hoa hồng thật của shop này (seller + Shopee) — dùng để ước tính
            // hoàn tiền thay vì tỷ lệ cứng mặc định của AccessTradeService.
            'cashback_rate' => (float) ($info['sellerRate'] ?? 0) + (float) ($info['shopeeRate'] ?? 0),
        ];
    }
}
