<?php

namespace App\Services;

use App\Models\ApiConfig;
use App\Models\FacebookReelSlot;
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
 * Trạng thái slot nằm ở bảng facebook_reel_slots, không phải cache: cache bị xoá mỗi lần deploy,
 * và quan trọng hơn, cache chỉ ghi lại thứ MÌNH ĐÃ LÀM chứ không phải thứ ĐANG CÓ trên Facebook.
 * Job facebook:sync-reels (5 phút/lần) đọc caption thật và ghi đè product_key/target_url cho
 * khớp thực tế — xem FacebookReelSync. Nhờ đó khi reel vẫn còn hiện đúng link (lease hết hạn
 * nhưng chưa ai lấy) thì thuê lại được mà không tốn lời gọi API.
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
        $pool = $this->pool();

        if (! $pool) {
            return null;
        }

        $leaseMinutes = $this->config->facebookReelLeaseMinutes();
        $leasedUntil = now()->addMinutes($leaseMinutes);

        // Sản phẩm này đang giữ reel nào chưa? Trúng thì không gọi API lần nữa — nhiều khách
        // cùng xem một sản phẩm dùng chung reel.
        $held = FacebookReelSlot::whereIn('reel_id', $pool)
            ->where('product_key', $productKey)
            ->where('leased_until', '>', now())
            ->first();

        if ($held) {
            return $held->url();
        }

        // Reel vẫn ĐANG HIỆN đúng link này (lease hết hạn nhưng chưa ai đè, hoặc job đối soát
        // đọc thấy trên Facebook như vậy): thuê lại thẳng, caption không cần đổi.
        $showing = FacebookReelSlot::whereIn('reel_id', $pool)
            ->where('product_key', $productKey)
            ->where('target_url', $targetUrl)
            ->first();

        if ($showing && $this->claim($showing, $leasedUntil)) {
            return $showing->url();
        }

        $service = new FacebookPageService($this->config->app_id, $this->config->app_secret);
        $caption = $this->caption($displayName, $targetUrl);

        foreach ($pool as $reelId) {
            $slot = FacebookReelSlot::where('reel_id', $reelId)->first();

            if (! $this->claim($slot, $leasedUntil, [
                'product_key' => $productKey,
                'product_name' => $displayName,
                'target_url' => $targetUrl,
            ])) {
                continue;
            }

            if (! $service->updateReelCaption($reelId, $caption)) {
                // Caption trên Facebook chưa hề đổi — trả slot về đúng trạng thái trước đó,
                // nếu không reel này vừa bị khoá vô ích suốt thời gian thuê vừa ghi sai là
                // đang hiện sản phẩm này.
                FacebookReelSlot::where('id', $slot->id)->update([
                    'product_key' => $slot->product_key,
                    'product_name' => $slot->product_name,
                    'target_url' => $slot->target_url,
                    'leased_until' => null,
                    'sync_error' => $service->lastError,
                ]);

                Log::warning('FacebookReelSlotService: không đổi được caption reel, thử reel khác', [
                    'reel_id' => $reelId,
                    'error' => $service->lastError,
                ]);

                continue;
            }

            FacebookReelSlot::where('id', $slot->id)->update(['caption' => $caption, 'sync_error' => null]);

            return $slot->url();
        }

        Log::warning('FacebookReelSlotService: hết slot reel, khách đi thẳng Shopee', [
            'pool_size' => count($pool),
            'lease_minutes' => $leaseMinutes,
            'product' => $productKey,
        ]);

        return null;
    }

    /**
     * Giữ slot bằng UPDATE có điều kiện "chưa ai thuê" — thao tác nguyên tử: hai request cùng
     * lúc thì chỉ một bên thấy 1 hàng bị đổi, bên kia thấy 0 và đi thử reel kế tiếp.
     *
     * @param  array<string, mixed>  $attributes  Ghi kèm khi giành được slot (sản phẩm mới).
     */
    private function claim(FacebookReelSlot $slot, \DateTimeInterface $leasedUntil, array $attributes = []): bool
    {
        return FacebookReelSlot::where('id', $slot->id)
            ->where(fn ($q) => $q->whereNull('leased_until')->orWhere('leased_until', '<=', now()))
            ->update($attributes + ['leased_until' => $leasedUntil]) === 1;
    }

    /**
     * Danh sách reel id trần trong nhóm đã cấu hình, và bảo đảm mỗi reel có một hàng trong bảng.
     *
     * Admin dán cả link reel chứ không phải id trần, mà Graph API cần đúng id. Phân giải ngay
     * tại đây rồi dùng id cho mọi thứ phía sau — kể cả khoá hàng, để hai cách nhập cùng một reel
     * (link đầy đủ và id trần) không thành hai slot riêng.
     *
     * @return list<string>
     */
    public function pool(): array
    {
        $ids = array_values(array_unique(array_map(
            fn (string $entry) => FacebookPostTarget::parse($entry)->graphId,
            $this->config->facebookTargetReelIds(),
        )));

        foreach ($ids as $reelId) {
            FacebookReelSlot::firstOrCreate(['reel_id' => $reelId]);
        }

        return $ids;
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
}
