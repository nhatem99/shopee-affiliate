<?php

namespace App\Services;

use App\Models\ApiConfig;
use Illuminate\Support\Facades\Log;

/**
 * Quyết định nguồn lấy mã nào đang được dùng. Có hai nguồn chạy song song và admin chọn ở
 * /admin/api-config bằng công tắc is_active của từng bản ghi:
 *
 *  • kieushopee — mã Facebook/Instagram, đồng bộ, nhận mọi link Shopee.
 *  • ganma      — mã YouTube, bất đồng bộ (~20 giây), CHỈ nhận link ngắn từ app Shopee.
 *
 * Gom vào một chỗ vì cả ShopeeVoucherController (lúc lấy mã) lẫn ShortLinkController (lúc ghi
 * `source` vào short-link và tracking) đều phải trả lời cùng một câu hỏi — hai nơi tự suy ra
 * độc lập là sớm muộn cũng lệch nhau.
 */
class VoucherSourceResolver
{
    /** Thứ tự cũng là thứ tự ưu tiên khi admin lỡ bật cả hai — xem activeSource(). */
    public const SOURCES = [GanmaService::SOURCE, KieuShopeeService::SOURCE];

    public function activeSource(): string
    {
        $active = ApiConfig::whereIn('platform', self::SOURCES)
            ->where('is_active', true)
            ->pluck('platform')
            ->all();

        if ($active === []) {
            // Chưa cấu hình gì (hoặc admin lỡ tắt hết) thì vẫn phải chạy được: rơi về nguồn
            // vốn có, đang phục vụ khách từ trước khi có ganma.
            return KieuShopeeService::SOURCE;
        }

        foreach (self::SOURCES as $source) {
            if (in_array($source, $active, true)) {
                if (count($active) > 1) {
                    // ApiConfigController tự tắt nguồn kia khi lưu, nên rơi vào đây là dữ liệu
                    // đã lệch (sửa tay trong DB, hoặc seeder chạy đè). Vẫn chạy tiếp bằng lựa
                    // chọn xác định thay vì để hành vi phụ thuộc thứ tự dòng trong bảng.
                    Log::warning('VoucherSourceResolver: có nhiều hơn một nguồn đang bật', [
                        'dang_bat' => $active,
                        'chon' => $source,
                    ]);
                }

                return $source;
            }
        }

        return KieuShopeeService::SOURCE;
    }
}
