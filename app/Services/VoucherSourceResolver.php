<?php

namespace App\Services;

use App\Models\ApiConfig;
use App\Models\Setting;
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
 *
 * Trên lựa chọn của admin còn một lớp GHI ĐÈ THEO GIỜ: xem activeSource().
 */
class VoucherSourceResolver
{
    public function __construct(
        private RestockScheduleService $restock,
    ) {}

    /**
     * Thứ tự cũng là thứ tự ưu tiên khi dữ liệu lệch (bật cả hai) — xem activeSource().
     *
     * kieushopee đứng trước vì nó là nguồn đang phục vụ khách và nhận MỌI dạng link Shopee.
     * Để ganma trước thì một lần lệch dữ liệu sẽ đẩy toàn bộ khách sang nguồn chỉ nhận link
     * ngắn từ app — tức phần lớn khách dán link thường sẽ bị từ chối thẳng.
     */
    public const SOURCES = [KieuShopeeService::SOURCE, GanmaService::SOURCE];

    /** Setting bật/tắt lớp ghi đè theo khung giờ — xem activeSource(). */
    public const AUTO_SWITCH_KEY = 'fbig_window_auto_switch';

    /**
     * Nguồn THẬT SỰ phục vụ khách ngay lúc này.
     *
     * Khi admin bật công tắc AUTO_SWITCH_KEY: tới khung giờ back mã FB-IG (RestockScheduleService)
     * mà đang để ganma thì tự sang kieushopee — đó đúng là lúc mã FB-IG vừa được nạp lại lượt,
     * còn ganma thì mỗi lượt mất ~20 giây và chỉ nhận link ngắn từ app Shopee. Ngoài khung giờ,
     * hoặc admin vốn đã để kieushopee, thì giữ nguyên.
     *
     * Ghi đè này KHÔNG đụng vào `is_active` trong DB — hết khung giờ là tự quay lại đúng nguồn
     * admin đã chọn, không cần ai bật lại và không phụ thuộc cron còn sống. Đổi lại, trang
     * /admin/api-config phải tự nói ra lúc đang ghi đè, nếu không admin nhìn thấy ganma "đang
     * phục vụ khách" trong khi thực tế mọi lượt quét đi qua kieushopee.
     */
    public function activeSource(): string
    {
        $configured = $this->configuredSource();

        if ($configured !== GanmaService::SOURCE || ! self::autoSwitchEnabled()) {
            return $configured;
        }

        return $this->restock->inFbIgWindow() ? KieuShopeeService::SOURCE : $configured;
    }

    /**
     * Công tắc ở Admin > Cài đặt. Mặc định TẮT: đây là lớp ghi đè lặng lẽ lên lựa chọn nguồn của
     * admin, bật sẵn cho mọi cài đặt là đổi hành vi của người không hề yêu cầu nó.
     */
    public static function autoSwitchEnabled(): bool
    {
        return Setting::getBool(self::AUTO_SWITCH_KEY, false);
    }

    /**
     * Nguồn admin đã chọn ở /admin/api-config, chưa tính lớp ghi đè theo khung giờ. Đây là thứ
     * hệ thống quay về khi hết khung giờ FB-IG.
     */
    public function configuredSource(): string
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
