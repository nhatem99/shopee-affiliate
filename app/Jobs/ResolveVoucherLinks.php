<?php

namespace App\Jobs;

use App\Services\ChannelVoucher\ChannelVoucherMinter;
use App\Services\ShopeeLinkResolverService;
use App\Services\VoucherRefService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Đi tìm link voucher cho một link sản phẩm, chạy nền.
 *
 * Phải chạy nền vì việc tự đúc mã tốn 10-60s (gọi Graph API -> đợi Facebook -> mở Chromium ->
 * đợi Shopee đúc link), vượt xa trần timeout của nginx/php-fpm. Frontend hỏi lại kết quả qua
 * ShopeeVoucherController::status().
 *
 * Với QUEUE_CONNECTION=sync (máy dev, hoặc production chưa dựng worker) job này chạy thẳng trong
 * request — chậm nhưng vẫn đúng: khách chờ lâu hơn chứ tính năng không chết.
 */
class ResolveVoucherLinks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Không thử lại: mỗi lần chạy là một comment mới lên Facebook. Thử lại tự động nghĩa là nhân
    // đôi số comment rác mỗi khi Facebook trục trặc — đúng lúc không nên đụng vào Page nhất.
    public int $tries = 1;

    public int $timeout = 180;

    // Kết quả chỉ cần sống đủ lâu cho frontend poll xong; ref bên trong tự có TTL 7 ngày riêng.
    private const RESULT_TTL_MINUTES = 10;

    public function __construct(
        private readonly string $token,
        private readonly string $canonicalUrl,
    ) {}

    public static function cacheKey(string $token): string
    {
        return "voucher_job:{$token}";
    }

    public function handle(
        ChannelVoucherMinter $minter,
        ShopeeLinkResolverService $resolver,
        VoucherRefService $refs,
    ): void {
        $links = $minter->mintCached($this->canonicalUrl);

        if ($links === []) {
            // Không còn đường dự phòng nào (salesoc.vn đã bị gỡ bỏ hoàn toàn) — nên đây là hết mã
            // thật sự. Vẫn trả 'done' kèm thông tin sản phẩm để khách thấy mình quét đúng hàng,
            // Home.vue tự hiện "Chưa lấy được link voucher cho sản phẩm này".
            Log::warning('ResolveVoucherLinks: không đúc được mã nào', [
                'shopee_url' => $this->canonicalUrl,
                'minter_enabled' => $minter->isEnabled(),
            ]);
        }

        $ids = $resolver->extractIds($this->canonicalUrl);

        $this->store([
            'status' => 'done',
            'result' => [
                'canonical_url' => $this->canonicalUrl,
                'product' => $ids ? $resolver->fetchProductInfo($ids['item_id'], $ids['shop_id']) : null,
                'voucher_labels' => $this->labelsOf($links),
                'voucher_links' => $refs->mask($links),
            ],
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ResolveVoucherLinks: job hỏng', [
            'shopee_url' => $this->canonicalUrl,
            'error' => $e->getMessage(),
        ]);

        $this->store([
            'status' => 'failed',
            'message' => 'Không lấy được mã cho sản phẩm này, thử dán lại link nhé.',
        ]);
    }

    /**
     * @param  array<string, list<array{label: string, url: string}>>  $links
     * @return list<string>
     */
    private function labelsOf(array $links): array
    {
        $labels = [];

        foreach ($links as $options) {
            foreach ($options as $option) {
                $labels[] = $option['label'];
            }
        }

        return array_values(array_unique($labels));
    }

    private function store(array $payload): void
    {
        Cache::put(self::cacheKey($this->token), $payload, now()->addMinutes(self::RESULT_TTL_MINUTES));
    }
}
