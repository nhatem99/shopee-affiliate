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
    /**
     * Thứ tự cũng là thứ tự ưu tiên khi dữ liệu lệch (bật cả hai) — xem activeSource().
     *
     * kieushopee đứng trước vì nó là nguồn đang phục vụ khách và nhận MỌI dạng link Shopee.
     * Để ganma trước thì một lần lệch dữ liệu sẽ đẩy toàn bộ khách sang nguồn chỉ nhận link
     * ngắn từ app — tức phần lớn khách dán link thường sẽ bị từ chối thẳng.
     */
    public const SOURCES = [KieuShopeeService::SOURCE, GanmaService::SOURCE];

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

        // Không có nhánh return nào sau vòng lặp: truy vấn đã lọc whereIn(SOURCES) nên $active
        // khác rỗng thì chắc chắn khớp một phần tử của SOURCES và đã return ở trên.
        return KieuShopeeService::SOURCE;
    }

    /**
     * Bật một nguồn thì tắt mọi nguồn còn lại — nửa GHI của cùng cái bất biến mà activeSource()
     * đọc. Để ở đây thay vì trong ApiConfigController vì đây là quy tắc nghiệp vụ (CLAUDE.md:
     * "Services handle all logic"), và vì tách hai nửa ra hai file là chúng sớm muộn lệch nhau.
     */
    public function makeExclusive(ApiConfig $config): void
    {
        if (! $config->is_active || ! in_array($config->platform, self::SOURCES, true)) {
            return;
        }

        ApiConfig::whereIn('platform', self::SOURCES)
            ->where('id', '!=', $config->id)
            ->update(['is_active' => false]);
    }
}
