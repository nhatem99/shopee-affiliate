<?php

namespace App\Services;

use App\Models\VoucherRef;
use Illuminate\Support\Str;

/**
 * Phát và tra token mờ (ref) thay cho URL affiliate thật.
 *
 * URL thật không bao giờ đi qua response /voucher/resolve hay request body /voucher/shorten
 * — frontend chỉ cầm ref, server mới giải mã lại lúc khách thực sự bấm "Mua ngay". Lưu ở
 * bảng voucher_refs chứ không phải cache, vì cache bị xoá mỗi lần deploy (optimize:clear)
 * còn ref thì phải sống trọn vòng đời để nút "mua lại" trong lịch sử của khách còn dùng được.
 */
class VoucherRefService
{
    /**
     * Khớp với ShortLinkService::REUSE_WINDOW_DAYS — khoảng thời gian một lần "lấy mã" còn
     * bấm lại được. Mã có thể hết lượt trước đó, nhưng đó vốn là giới hạn có sẵn (nguồn không
     * báo trạng thái còn/hết lượt).
     */
    public const TTL_DAYS = 7;

    /**
     * $sourceUrl là URL Shopee đã dùng để lấy $url (canonical với kieushopee, link ngắn gốc với
     * ganma) — ShortLinkController::store() cần nó để gọi lại đúng nguồn khi ref đã cũ. Để trống
     * thì ref chỉ dùng lại được $url đã lưu, không refetch được.
     *
     * $ytbUrl là link YouTube của ganma ở chế độ mã YTB — lúc khách bấm mua nó được xâu vào
     * trước link đích để trình duyệt khách đi qua (xem ShortLinkController::store()).
     */
    public function issue(string $url, string $source, ?string $sourceUrl = null, ?string $ytbUrl = null): string
    {
        do {
            $ref = Str::random(32);
        } while (VoucherRef::where('ref', $ref)->exists());

        VoucherRef::create([
            'ref' => $ref,
            'url' => $url,
            'source_url' => $sourceUrl,
            'ytb_url' => $ytbUrl,
            'source' => $source,
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        return $ref;
    }

    /** null khi ref không tồn tại hoặc đã hết hạn — với người gọi thì hai trường hợp như nhau. */
    public function resolve(string $ref): ?VoucherRef
    {
        $found = VoucherRef::where('ref', $ref)->first();

        if ($found === null || $found->isExpired()) {
            return null;
        }

        return $found;
    }
}
