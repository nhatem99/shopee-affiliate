<?php

namespace App\Services;

use App\Models\AffiliateLink;
use App\Models\Commission;
use App\Models\PlatformVoucher;
use App\Models\User;
use App\Models\Voucher;

class AffiliateScanOrchestrator
{
    public function __construct(
        private UrlValidationService $urlValidator,
        private ShopeeApiService $shopeeApi,
        private AccessTradeService $accessTrade,
        private ProductMetadataService $productMetadata,
    ) {}

    public function scan(string $url, ?User $user): array
    {
        // 1. Validate URL and detect platform
        $platform = $this->urlValidator->validate($url);

        // 2. Fetch product info
        $productInfo = $this->fetchProductInfo($url, $platform);

        // 3. Generate affiliate link
        $affiliateLink = $this->accessTrade->generateLink($url);

        // 4. Get vouchers
        $vouchers = $this->fetchVouchers($url, $platform);

        // 5. Calculate savings
        $original = (float) ($productInfo['original_price'] ?? 0);
        $discounted = (float) ($productInfo['discounted_price'] ?? $original);
        $productDiscount = $original - $discounted;
        $voucherDiscount = collect($vouchers)
            ->where('discount_type', 'flat')
            ->sum('discount_value');
        $finalPrice = max(0, $discounted - $voucherDiscount);
        $totalSaved = $original - $finalPrice;
        $pctSaved = $original > 0 ? round($totalSaved / $original * 100) : 0;
        $cashback = round($finalPrice * $this->accessTrade->getCashbackRate($platform));

        // 6. Save to DB and track commission only for logged-in users
        if ($user) {
            $record = AffiliateLink::updateOrCreate(
                ['user_id' => $user->id, 'original_url' => $url],
                [
                    'short_url' => $affiliateLink,
                    'platform' => $platform,
                    ...$productInfo,
                ]
            );

            foreach ($vouchers as $v) {
                Voucher::firstOrCreate(
                    ['affiliate_link_id' => $record->id, 'code' => $v['code']],
                    $v
                );
            }

            $record->load('vouchers');
            $savedVouchers = $record->vouchers->toArray();

            if ($cashback > 0) {
                Commission::create([
                    'user_id' => $user->id,
                    'affiliate_link_id' => $record->id,
                    'amount' => $cashback,
                    'status' => 'pending',
                ]);
            }
        } else {
            // Guest: no DB save, return raw vouchers
            $savedVouchers = $vouchers;
        }

        // Fetch platform-wide vouchers (Facebook/YouTube) for this platform
        $platformVouchers = PlatformVoucher::active()->forPlatform($platform)->get()->toArray();

        return [
            'product' => [
                'name' => $productInfo['product_name'],
                'image' => $productInfo['product_image'],
                'platform' => $platform,
                'original_price' => $original,
                'discounted_price' => $discounted,
                'discount_percent' => $productInfo['discount_percent'] ?? 0,
                'rating' => $productInfo['rating'] ?? 0,
                'sold_count' => $productInfo['sold_count'] ?? 0,
            ],
            'vouchers' => $savedVouchers,
            'platformVouchers' => $platformVouchers,
            'affiliateLink' => $affiliateLink,
            'cashback' => $cashback,
            'savings' => [
                'original' => $original,
                'product_discount' => $productDiscount,
                'voucher_discount' => $voucherDiscount,
                'final_price' => $finalPrice,
                'total_saved' => $totalSaved,
                'pct_saved' => $pctSaved,
            ],
        ];
    }

    private function fetchProductInfo(string $url, string $platform): array
    {
        // 1. Best-effort: đọc dữ liệu thật từ trang sản phẩm (JSON-LD / OG / API Tiki)
        $meta = $this->productMetadata->fetch($url, $platform);
        if ($meta && ($meta['product_name'] || $meta['product_image'])) {
            return [
                'product_name' => $meta['product_name'] ?? 'Sản phẩm',
                'product_image' => $meta['product_image'],
                'original_price' => $meta['original_price'],
                'discounted_price' => $meta['discounted_price'],
                'discount_percent' => $meta['discount_percent'],
                'sold_count' => $meta['sold_count'],
                'rating' => $meta['rating'],
            ];
        }

        // 2. Fallback: Shopee Open API nếu đã cấu hình credentials
        if ($platform === 'shopee') {
            $ids = $this->urlValidator->extractShopeeIds($url);
            if ($ids) {
                return $this->shopeeApi->getProductInfo($ids['item_id'], $ids['shop_id']);
            }
        }

        // 3. Fallback cuối: placeholder
        return [
            'product_name' => 'Sản phẩm',
            'product_image' => null,
            'original_price' => 0,
            'discounted_price' => 0,
            'discount_percent' => 0,
            'sold_count' => 0,
            'rating' => 0,
        ];
    }

    private function fetchVouchers(string $url, string $platform): array
    {
        if ($platform === 'shopee') {
            $ids = $this->urlValidator->extractShopeeIds($url);
            if ($ids) {
                return $this->shopeeApi->getVouchers($ids['shop_id']);
            }
        }

        return [];
    }
}
