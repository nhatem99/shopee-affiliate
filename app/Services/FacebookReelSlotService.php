<?php

namespace App\Services;

use App\Models\ApiConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Chế độ "đổi caption reel": thay vì đăng comment, mỗi sản phẩm được THUÊ một reel trong nhóm
 * reels tạo sẵn; caption của reel đó bị đổi thành link sản phẩm, rồi khách được đưa tới chính
 * reel đó để bấm link ngay trong phần mô tả.
 *
 * Vì sao đi đường này (tất cả đều đo trên máy thật ngày 2026-09-08, không phải suy đoán):
 *  • Link /reel/ MỞ THẲNG ứng dụng Facebook — link bài viết thường thì ở lại trình duyệt.
 *  • Link trong CAPTION reel BẤM ĐƯỢC — link trong BÌNH LUẬN reel thì không, Facebook hiển
 *    thị nó thành chuỗi text thường.
 *  • Graph API cho sửa caption reel sau khi đăng (POST /{reel_id} với field `description`),
 *    dù Meta không tài liệu hoá — xem FacebookPageService::updateReelCaption.
 * => Đặt link vào caption là con đường duy nhất vừa mở được app vừa bấm được link.
 *
 * MỘT REEL CHỈ HIỂN THỊ ĐƯỢC MỘT LINK TẠI MỘT THỜI ĐIỂM, nên đây là bài toán chia slot: mỗi
 * reel là một slot, thuê trong $leaseMinutes phút. Số reel trong nhóm chính là số sản phẩm
 * khác nhau có thể phục vụ đồng thời — đây là lý do phải tạo sẵn 5–10 reel chứ không phải một.
 *
 * Hết slot thì trả null để người gọi đưa khách đi thẳng Shopee. Thà mất một lượt đi qua
 * Facebook còn hơn đưa khách tới reel đang hiện link của SẢN PHẨM KHÁC — khách bấm nhầm là
 * mua nhầm hàng.
 */
class FacebookReelSlotService
{
    public function __construct(private ApiConfig $config) {}

    /**
     * Thuê một reel cho sản phẩm này và trả về URL reel, hoặc null nếu không thu xếp được
     * (chưa cấu hình reel nào, hết slot, hoặc Graph API từ chối đổi caption).
     */
    public function reelUrlFor(string $productKey, string $displayName, string $targetUrl): ?string
    {
        $pool = $this->config->facebookTargetReelIds();

        if (! $pool) {
            return null;
        }

        $leaseMinutes = $this->config->facebookReelLeaseMinutes();

        // Sản phẩm này đã thuê reel nào chưa? Kiểm tra hai chiều: bản ghi sản phẩm→reel còn
        // sống, VÀ reel đó vẫn đang thuộc về đúng sản phẩm này (slot chưa bị người khác lấy).
        // Trúng thì không gọi API lần nữa — nhiều khách cùng xem một sản phẩm dùng chung reel.
        $leased = Cache::get($this->productKey($productKey));

        if ($leased && Cache::get($this->leaseKey($leased)) === $productKey) {
            return $this->reelUrl($leased);
        }

        $service = new FacebookPageService($this->config->app_id, $this->config->app_secret);
        $caption = $this->caption($displayName, $targetUrl);

        foreach ($pool as $entry) {
            // Admin dán cả link reel chứ không phải id trần, mà Graph API cần đúng id. Phân giải
            // ngay tại đây rồi dùng id cho mọi thứ phía sau — kể cả khoá cache, để hai cách nhập
            // cùng một reel (link đầy đủ và id trần) không thành hai slot riêng.
            $reelId = FacebookPostTarget::parse($entry)->graphId;

            // Cache::add là thao tác nguyên tử: chỉ một request giành được slot trống, các
            // request cùng lúc khác nhận false và đi thử reel tiếp theo.
            if (! Cache::add($this->leaseKey($reelId), $productKey, now()->addMinutes($leaseMinutes))) {
                continue;
            }

            if (! $service->updateReelCaption($reelId, $caption)) {
                // Trả slot lại ngay, nếu không reel này bị khoá vô ích suốt thời gian thuê
                // dù caption chưa hề đổi.
                Cache::forget($this->leaseKey($reelId));

                Log::warning('FacebookReelSlotService: không đổi được caption reel, thử reel khác', [
                    'reel_id' => $reelId,
                    'error' => $service->lastError,
                ]);

                continue;
            }

            Cache::put($this->productKey($productKey), $reelId, now()->addMinutes($leaseMinutes));

            return $this->reelUrl($reelId);
        }

        Log::warning('FacebookReelSlotService: hết slot reel, khách đi thẳng Shopee', [
            'pool_size' => count($pool),
            'lease_minutes' => $leaseMinutes,
            'product' => $productKey,
        ]);

        return null;
    }

    /**
     * Caption đặt vào reel. Khách vừa từ web nhảy sang app Facebook nên đang mất phương hướng —
     * link phải nằm ngay dòng đầu, trước cả tên sản phẩm, để không bị phần "xem thêm" của
     * Facebook cắt mất.
     */
    private function caption(string $displayName, string $targetUrl): string
    {
        return implode("\n", [
            $targetUrl,
            '',
            "🔥 {$displayName}",
            '🎟️ Bấm link ở ngay trên để nhận giá đã giảm.',
            '',
            '⚠️ Phải bấm đúng link này mới được giảm — tự tìm sản phẩm trên Shopee thì mã không áp được.',
        ]);
    }

    /** $reelId ở đây luôn là id trần (đã qua FacebookPostTarget), nên ghép thẳng. */
    private function reelUrl(string $reelId): string
    {
        return "https://www.facebook.com/reel/{$reelId}";
    }

    /** Slot đang thuộc về sản phẩm nào. */
    private function leaseKey(string $reelId): string
    {
        return "fb_reel_lease:{$reelId}";
    }

    /** Sản phẩm đang giữ reel nào. */
    private function productKey(string $productKey): string
    {
        return "fb_reel_for_product:{$productKey}";
    }
}
