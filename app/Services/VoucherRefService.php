<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Thay URL affiliate thật bằng token mờ ('ref') trước khi gửi xuống frontend.
 *
 * URL thật (link đã đúc mã) chỉ được giải mã lại phía server khi khách
 * thực sự bấm chọn — xem ShortLinkController::store(). Nhờ vậy nó không nằm sẵn trong response
 * của /voucher/resolve, nơi ai mở tab Network cũng đọc được dù chưa bấm nút nào.
 */
class VoucherRefService
{
    // TTL dài để nút "mua lại" trong lịch sử chuyển đổi (lưu ở localStorage, xem Home.vue) còn
    // dùng được sau vài ngày. Mã có thể hết lượt trước đó, nhưng đó vốn là giới hạn có sẵn.
    private const TTL_DAYS = 7;

    /**
     * @param  array<string, list<array{label: string, url: string}>>  $voucherLinks
     * @return array<string, list<array{label: string, ref: string}>>
     */
    public function mask(array $voucherLinks): array
    {
        $masked = [];

        foreach ($voucherLinks as $source => $options) {
            foreach ($options as $option) {
                $ref = Str::random(32);
                Cache::put("voucher_ref:{$ref}", $option['url'], now()->addDays(self::TTL_DAYS));
                $masked[$source][] = ['label' => $option['label'], 'ref' => $ref];
            }
        }

        return $masked;
    }

    public function resolve(string $ref): ?string
    {
        return Cache::get("voucher_ref:{$ref}");
    }
}
