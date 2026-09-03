<?php

namespace App\Services;

use App\Models\AffiliateLink;
use App\Models\Commission;
use App\Models\PlatformVoucher;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

class AffiliateScanOrchestrator
{
    public function __construct(
        private UrlValidationService $urlValidator,
        private ShopeeApiService $shopeeApi,
        private AccessTradeService $accessTrade,
        private ProductMetadataService $productMetadata,
        private ShortLinkService $shortLinks,
    ) {}

    public function scan(string $url, ?User $user): array
    {
        // 1. Validate URL and detect platform
        $platform = $this->urlValidator->validate($url);
        $shopeeIds = $platform === 'shopee' ? $this->urlValidator->extractShopeeIds($url) : [];

        // 2-4. Lấy thông tin sản phẩm, sinh affiliate link và lấy voucher SONG SONG —
        // trước đây 3 việc này gọi API tuần tự (mỗi API timeout 8-10s) nên cộng dồn rất chậm.
        $responses = Http::pool(function (Pool $pool) use ($url, $platform, $shopeeIds) {
            $this->productMetadata->registerPoolRequests($pool, $url, $platform);
            $this->accessTrade->registerPoolRequest($pool, $url);
            if ($shopeeIds) {
                $this->shopeeApi->registerVouchersPoolRequest($pool, $shopeeIds['shop_id']);
            }
        });

        $productInfo = $this->resolveProductInfo($url, $platform, $responses);
        $rawAffiliateLink = $this->accessTrade->parsePoolResponse($responses['accesstrade'] ?? null, $url);
        // Bọc link affiliate gốc qua /go/{code} để không lộ URL AccessTrade/Shopee thật ra frontend.
        $shortLink = $this->shortLinks->create(
            $rawAffiliateLink,
            'scan',
            $productInfo['product_name'] ?? null,
            $productInfo['product_image'] ?? null,
        );
        $affiliateLink = url('/go/'.$shortLink->code);
        $vouchers = $shopeeIds
            ? $this->shopeeApi->parseVouchersPoolResponse($responses['vouchers'] ?? null)
            : [];

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
        $cashbackRate = $productInfo['cashback_rate'] ?? $this->accessTrade->getCashbackRate($platform);
        $cashback = round($finalPrice * $cashbackRate);
        unset($productInfo['cashback_rate']); // không phải cột DB — chỉ dùng để tính cashback ở trên

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

    private function resolveProductInfo(string $url, string $platform, array $responses): array
    {
        // 1. Best-effort: đọc dữ liệu thật từ trang sản phẩm (JSON-LD / OG / API Tiki), lấy từ pool ở trên
        $meta = $this->productMetadata->resolveFromPool($responses, $url, $platform);
        if ($meta && ($meta['product_name'] || $meta['product_image'])) {
            return [
                'product_name' => $meta['product_name'] ?? 'Sản phẩm',
                'product_image' => $meta['product_image'],
                'original_price' => $meta['original_price'],
                'discounted_price' => $meta['discounted_price'],
                'discount_percent' => $meta['discount_percent'],
                'sold_count' => $meta['sold_count'],
                'rating' => $meta['rating'],
                'cashback_rate' => $meta['cashback_rate'] ?? null,
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
}
