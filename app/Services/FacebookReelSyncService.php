<?php

namespace App\Services;

use App\Models\ApiConfig;
use App\Models\FacebookReelSlot;
use App\Models\ShortLink;
use Illuminate\Support\Facades\Log;

/**
 * Đối soát bảng facebook_reel_slots với caption THẬT trên Facebook.
 *
 * FacebookReelSlotService chỉ ghi lại thứ mình đã làm (đổi caption reel X sang link sản phẩm Y).
 * Giữa hai lần bấm của khách, thực tế trên Facebook có thể đã khác: admin sửa tay caption, xoá
 * link, Graph API trả success mà không đổi gì, hay deploy làm mất trạng thái. Không đối soát thì
 * bảng nói reel đang hiện sản phẩm Y trong khi khách mở ra thấy link sản phẩm khác — đúng thứ
 * chế độ này tuyệt đối không được để xảy ra.
 *
 * Job đọc caption từng reel trong nhóm, tìm short-link /go/{code} trong đó, suy ra sản phẩm
 * đang hiện, rồi ghi đè product_key/target_url theo thực tế:
 *  - caption có link của mình → reel đang hiện đúng sản phẩm đó. Nếu khác với bản ghi (ai đó
 *    đổi tay) thì thả lease, vì lease cũ đang bảo vệ một sản phẩm không còn trên reel.
 *  - caption không có link → slot trống hẳn, thả lease luôn.
 *  - Graph API lỗi → giữ nguyên bản ghi, chỉ ghi sync_error để admin thấy.
 *
 * Chạy theo lịch 10 phút/lần (routes/console.php), dùng chung cấu hình Facebook ở /admin/api-config.
 */
class FacebookReelSyncService
{
    public function __construct(private ProductKeyService $productKeys) {}

    /**
     * @return list<array{reel_id: string, status: string, product_key: ?string, error: ?string}>
     */
    public function sync(): array
    {
        $config = ApiConfig::where('platform', 'facebook')->where('is_active', true)->first();

        if (! $config || ! $config->app_id || ! $config->app_secret || ! $config->facebookTargetReelIds()) {
            return [];
        }

        $service = new FacebookPageService($config->app_id, $config->app_secret);
        $results = [];

        foreach ((new FacebookReelSlotService($config))->pool() as $reelId) {
            $results[] = $this->syncOne($service, FacebookReelSlot::where('reel_id', $reelId)->first());
        }

        return $results;
    }

    /** @return array{reel_id: string, status: string, product_key: ?string, error: ?string} */
    private function syncOne(FacebookPageService $service, FacebookReelSlot $slot): array
    {
        $caption = $service->fetchReelCaption($slot->reel_id);

        if ($caption === null && $service->lastError !== null) {
            $slot->update(['sync_error' => $service->lastError, 'synced_at' => now()]);

            Log::warning('FacebookReelSyncService: không đọc được caption reel', [
                'reel_id' => $slot->reel_id,
                'error' => $service->lastError,
            ]);

            return $this->result($slot, 'error', $service->lastError);
        }

        $link = $this->shortLinkIn((string) $caption);
        $productKey = $link ? $this->productKeys->fromUrl($link->target_url, $link->product_name) : null;
        $targetUrl = $link ? url('/go/'.$link->code) : null;

        $was = $slot->product_key;
        $changed = $productKey !== $was || $targetUrl !== $slot->target_url;

        $slot->update([
            'product_key' => $productKey,
            'product_name' => $link?->product_name ?? ($changed ? null : $slot->product_name),
            'target_url' => $targetUrl,
            // Thực tế khác bản ghi → lease cũ đang giữ chỗ cho sản phẩm không còn trên reel,
            // thả ra. Khớp thì giữ nguyên lease, khách đang xem không bị đổi caption dưới chân.
            'leased_until' => $changed ? null : $slot->leased_until,
            'caption' => $caption,
            'synced_at' => now(),
            'sync_error' => null,
        ]);

        if ($changed) {
            Log::info('FacebookReelSyncService: caption reel khác bản ghi, đã cập nhật theo Facebook', [
                'reel_id' => $slot->reel_id,
                'was' => $was,
                'now' => $productKey,
            ]);
        }

        return $this->result($slot->refresh(), $changed ? 'updated' : 'ok', null);
    }

    /**
     * Short-link /go/{code} của mình trong caption, nếu có. Chỉ nhận link đúng domain của app —
     * caption có thể chứa link Shopee hay link khác do admin gõ tay, không phải của mình.
     */
    private function shortLinkIn(string $caption): ?ShortLink
    {
        // url() cắt dấu "/" cuối, nên ghép lại thủ công.
        $prefix = preg_quote(url('/go'), '#');

        if (! preg_match("#{$prefix}/([A-Za-z0-9]+)#", $caption, $m)) {
            return null;
        }

        return ShortLink::where('code', $m[1])->first();
    }

    /** @return array{reel_id: string, status: string, product_key: ?string, error: ?string} */
    private function result(FacebookReelSlot $slot, string $status, ?string $error): array
    {
        return [
            'reel_id' => $slot->reel_id,
            'status' => $status,
            'product_key' => $slot->product_key,
            'error' => $error,
        ];
    }
}
