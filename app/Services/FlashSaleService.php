<?php

namespace App\Services;

use App\Models\FlashSaleItem;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Phía ĐỌC của kho Flash Sale (/flashsale). Đồng bộ nằm ở FlashSaleSyncService.
 *
 * Cùng lý do với VoucherCatalogService: bảng chỉ giữ MỘT dòng cho mỗi suất, dùng chung
 * cho mọi người xem, nên sub_id của khách đang xem phải ghép vào link ngay lúc trả
 * response, không lưu sẵn trong bảng.
 */
class FlashSaleService
{
    public const PAGE_SIZE = 20;

    private const MAX_PAGE_SIZE = 60;

    public function __construct(private AffiliateLinkRewriterService $rewriter) {}

    /**
     * @return array{items: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function list(string $slot, string $keyword, int $page, int $pageSize, ?User $user): array
    {
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $page = max(1, $page);

        $query = FlashSaleItem::query()
            ->forSlot($slot)
            ->search($keyword)
            // % giảm cao nhất lên trước — đây là thứ khách vào trang Flash Sale để tìm.
            // `id` chốt cuối để thứ tự không đổi giữa hai lượt cuộn.
            ->orderByDesc('percent')
            ->orderBy('id');

        $total = (clone $query)->count();

        $items = $query->forPage($page, $pageSize)->get()
            ->map(fn (FlashSaleItem $item) => $this->toPublicArray($item, $user?->sub_id))
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
     * Danh sách khung giờ đang có hàng, sắp theo thời gian trong ngày — để trang biết
     * tab nào đáng hiện và hiện theo đúng thứ tự 09:00 → 12:00 → 15:00 → 17:00 chứ
     * không phải thứ tự bảng chữ cái hay thứ tự chèn vào DB.
     *
     * Cache 2 phút: ngắn hơn hẳn voucher (5 phút) vì flash sale đổi suất/hết hàng nhanh
     * hơn nhiều, và đồng bộ của trang này cũng chạy dày hơn.
     *
     * @return list<string>
     */
    public function activeSlots(): array
    {
        return Cache::remember('flash_sale_items:slots', 120, fn () => FlashSaleItem::query()
            ->distinct()
            ->orderBy('time_slot')
            ->pluck('time_slot')
            ->all());
    }

    public function lastSyncedAt(): ?string
    {
        $at = Cache::remember(
            'flash_sale_items:last_synced',
            120,
            fn () => FlashSaleItem::max('synced_at'),
        );

        return $at ? (string) $at : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function toPublicArray(FlashSaleItem $item, ?string $userSubId): array
    {
        return [
            'id' => $item->id,
            'timeSlot' => $item->time_slot,
            'title' => $item->title,
            'image' => $item->image,
            'price' => $item->price,
            'originalPrice' => $item->original_price,
            'percent' => $item->percent,
            'amount' => $item->amount,
            'claimUrl' => $this->publicUrlFor($item, $userSubId),
        ];
    }

    /**
     * Link cuối cùng gửi cho khách: link tự dựng lúc đồng bộ (đã mang mmp_pid của mình)
     * + sub_id của chính người đang xem, ghép vào `utm_content` cùng quy ước 5 khe với
     * AffiliateLinkRewriterService::buildSubId() — xem VoucherCatalogService::publicUrlFor()
     * cho lý do đầy đủ, ở đây áp dụng y hệt.
     */
    public function publicUrlFor(FlashSaleItem $item, ?string $userSubId): string
    {
        $label = (string) config('services.flash_sale.utm_content');

        if ($label === '' || $userSubId === null || $userSubId === '') {
            return $item->claim_url;
        }

        $parts = parse_url($item->claim_url);

        if (! isset($parts['query'])) {
            return $item->claim_url;
        }

        parse_str($parts['query'], $query);

        if (! isset($query['utm_content'])) {
            return $item->claim_url;
        }

        $query['utm_content'] = $this->rewriter->buildSubId($label, $userSubId);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').($parts['path'] ?? '').'?'.http_build_query($query);
    }
}
