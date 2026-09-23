<?php

namespace App\Services;

use App\Models\User;
use App\Models\VoucherOffer;
use Illuminate\Support\Facades\Cache;

/**
 * Phía ĐỌC của kho mã giảm giá (/ma-giam-gia). Đồng bộ nằm ở VoucherCatalogSyncService.
 *
 * Việc quan trọng nhất ở đây là ghép sub_id của khách đang xem vào link ngay lúc trả
 * response: bảng chỉ giữ MỘT dòng cho mỗi mã, dùng chung cho mọi người xem, nên link
 * trong bảng không thể mang sẵn định danh của ai cả. Không ghép ở đây thì đơn vẫn về
 * tài khoản affiliate của mình nhưng KHÔNG quy được về khách nào — tức là mã đó vĩnh
 * viễn không hoàn tiền cho ai.
 */
class VoucherCatalogService
{
    /** Số mã mỗi lượt cuộn. Bằng nguồn để nhịp cuộn quen thuộc với người đã dùng site khác. */
    public const PAGE_SIZE = 15;

    private const MAX_PAGE_SIZE = 50;

    public function __construct(private AffiliateLinkRewriterService $rewriter) {}

    /**
     * Một trang mã, đã sẵn sàng trả thẳng cho frontend.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function list(string $platform, string $keyword, int $page, int $pageSize, ?User $user): array
    {
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $page = max(1, $page);

        $query = VoucherOffer::query()
            ->active()
            ->forPlatform($platform)
            ->search($keyword)
            // Mã còn nhiều lượt lên trước (dùng được thật), rồi tới mã sắp hết hạn.
            // `id` chốt ở cuối để thứ tự KHÔNG đổi giữa hai lượt cuộn — thiếu nó là
            // khách gặp mã lặp hoặc mã nhảy mất khi kéo xuống.
            ->orderBy('usage_percent')
            ->orderByRaw('ends_at is null, ends_at')
            ->orderBy('id');

        $total = (clone $query)->count();

        $items = $query->forPage($page, $pageSize)->get()
            ->map(fn (VoucherOffer $offer) => $this->toPublicArray($offer, $user?->sub_id))
            ->all();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'totalPages' => (int) ceil($total / $pageSize),
                'hasMore' => $page * $pageSize < $total,
            ],
        ];
    }

    /**
     * Số mã đang còn hiệu lực của từng sàn, để trang biết tab nào đáng hiện.
     *
     * Cache 5 phút: giống hệt nhau với mọi người xem và chỉ đổi sau mỗi lần đồng bộ,
     * không đáng chạy một truy vấn gom nhóm cho mỗi lượt vào trang.
     *
     * @return array<string, int>
     */
    public function countsByPlatform(): array
    {
        return Cache::remember('voucher_offers:counts', 300, fn () => VoucherOffer::query()
            ->active()
            ->selectRaw('platform, count(*) as total')
            ->groupBy('platform')
            ->pluck('total', 'platform')
            ->map(fn ($n) => (int) $n)
            ->all());
    }

    /** Lần đồng bộ gần nhất — hiện trên trang để khách biết mã mới tới đâu. */
    public function lastSyncedAt(): ?string
    {
        $at = Cache::remember(
            'voucher_offers:last_synced',
            300,
            fn () => VoucherOffer::max('synced_at'),
        );

        return $at ? (string) $at : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function toPublicArray(VoucherOffer $offer, ?string $userSubId): array
    {
        return [
            'id' => $offer->id,
            'platform' => $offer->platform,
            'code' => $offer->code,
            'title' => $offer->title,
            'subtitle' => $offer->subtitle,
            'appliesText' => $offer->applies_text,
            'shopName' => $offer->shop_name,
            'voucherImage' => $offer->voucher_image,
            'category' => $offer->category,
            'usagePercent' => $offer->usage_percent,
            'usageText' => $offer->usage_text,
            'endsAt' => $offer->ends_at?->toIso8601String(),
            'claimUrl' => $this->publicUrlFor($offer, $userSubId),
        ];
    }

    /**
     * Link cuối cùng gửi cho khách: affiliate của mình (đã đổi lúc đồng bộ) + sub_id của
     * chính người đang xem.
     *
     * Khách vãng lai (`$userSubId` null) vẫn nhận link chạy được, chỉ thiếu định danh —
     * hoa hồng về tài khoản mình nhưng không hoàn tiền được cho ai. Đúng hành vi đang có
     * ở luồng dán link, không phát minh thêm quy tắc mới.
     */
    public function publicUrlFor(VoucherOffer $offer, ?string $userSubId): string
    {
        $label = (string) config('services.voucher_catalog.utm_content');

        if ($label === '' || $userSubId === null || $userSubId === '') {
            return $offer->claim_url;
        }

        $parts = parse_url($offer->claim_url);

        if (! isset($parts['query'])) {
            return $offer->claim_url;
        }

        parse_str($parts['query'], $query);

        // Chỉ thay nhãn đã có sẵn, không thêm tham số mới — cùng kỷ luật với
        // AffiliateLinkRewriterService::swapMmpPid().
        if (! isset($query['utm_content'])) {
            return $offer->claim_url;
        }

        $query['utm_content'] = $this->rewriter->buildSubId($label, $userSubId);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').($parts['path'] ?? '').'?'.http_build_query($query);
    }
}
