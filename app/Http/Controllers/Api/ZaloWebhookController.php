<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AffiliateScanException;
use App\Http\Controllers\Controller;
use App\Jobs\ReplyZaloGroupWithVouchers;
use App\Services\ShopeeLinkResolverService;
use App\Services\UrlValidationService;
use App\Services\ZaloOaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ZaloWebhookController extends Controller
{
    private const DEFAULT_AFFILIATE_ID = 'an_17332410386';

    private const DISCLAIMER = "Giá dự kiến tính theo voucher đang áp dụng.\nMức giảm thực tế xác nhận tại checkout Shopee.\nWebsite có sử dụng link tiếp thị liên kết.";

    public function __construct(
        private ShopeeLinkResolverService $resolver,
        private UrlValidationService $urlValidator,
        private ZaloOaService $zalo,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        if (! $this->zalo->verifyWebhookSignature(
            $request->getContent(),
            (string) $request->input('timestamp'),
            $request->header('X-ZEvent-Signature')
        )) {
            return response()->json(['ok' => false], 403);
        }

        $eventName = $request->input('event_name');

        return match ($eventName) {
            'user_send_text' => $this->handleDirectMessage($request),
            'user_send_group_text' => $this->handleGroupMessage($request),
            default => response()->json(['ok' => true]),
        };
    }

    private function handleDirectMessage(Request $request): JsonResponse
    {
        $text = (string) $request->input('message.text', '');
        $senderId = (string) $request->input('sender.id', '');

        $url = $this->extractShopeeUrl($text);
        if (! $senderId || ! $url) {
            return response()->json(['ok' => true]);
        }

        $canonicalUrl = $this->resolver->resolveCanonicalUrl($url);
        $voucherUrl = $this->resolver->buildVoucherUrl($canonicalUrl, self::DEFAULT_AFFILIATE_ID);

        $this->zalo->sendText($senderId, "Link voucher của bạn:\n{$voucherUrl}\n\n".self::DISCLAIMER);

        return response()->json(['ok' => true]);
    }

    private function handleGroupMessage(Request $request): JsonResponse
    {
        $text = (string) $request->input('message.text', '');
        $msgId = (string) $request->input('message.msg_id', '');
        $groupId = (string) $request->input('recipient.id', '');

        $url = $this->extractShopeeUrl($text);
        if (! $groupId || ! $msgId || ! $url) {
            return response()->json(['ok' => true]);
        }

        // Zalo có thể gửi lại cùng một webhook (retry) — chặn trả lời trùng lặp vào nhóm.
        // msg_id bắt buộc phải có (guard ở trên) nên request thiếu msg_id không thể lách qua đây.
        if (! Cache::add("zalo:group_msg:{$msgId}", true, now()->addMinutes(10))) {
            return response()->json(['ok' => true]);
        }

        // Đúc mã tốn 10-60s — quá lâu để giữ một webhook. Trả ok ngay, việc nặng đẩy sang job;
        // nếu không, Zalo coi là timeout rồi gửi lại sự kiện, mỗi lần gửi lại là thêm một comment
        // lên Facebook.
        ReplyZaloGroupWithVouchers::dispatch($groupId, $this->resolver->resolveCanonicalUrl($url));

        return response()->json(['ok' => true]);
    }

    /**
     * Rút link Shopee ra khỏi tin nhắn.
     *
     * Domain phải nằm ĐÚNG ở vị trí host. Regex cũ là `https?://\S*(?:shopee\.vn|shp\.ee)\S*`,
     * khớp luôn cả `https://evil.com/?ref=shopee.vn` — và bất kỳ ai nhắn vào nhóm Zalo một link
     * như vậy là link đó được đem COMMENT LÊN PAGE FACEBOOK của mình rồi MỞ BẰNG CHROMIUM ĐANG
     * ĐĂNG NHẬP FACEBOOK. Đó là trao cho người lạ quyền điều khiển cả hai thứ, nên vị trí của
     * domain trong chuỗi là chuyện bảo mật chứ không phải chuyện gọn gàng.
     */
    private function extractShopeeUrl(string $text): ?string
    {
        if (! str_contains($text, 'shopee.vn') && ! str_contains($text, 'shp.ee')) {
            return null;
        }

        // (?=\s|$) là bắt buộc: thiếu nó, regex khớp TIỀN TỐ `https://shopee.vn` của
        // `https://shopee.vn.evil.com/product` và trả về một link cụt trông rất hợp lệ.
        if (! preg_match('#https?://(?:[\w-]+\.)*(?:shopee\.vn|shp\.ee)(?:[/?\#]\S*)?(?=\s|$)#i', $text, $m)) {
            return null;
        }

        // Kiểm tra lại bằng parse_url thay vì chỉ tin regex: hai tầng, vì hậu quả của một lần
        // lọt là comment rác trên Page cộng với một phiên Facebook bị đem đi mở link người lạ.
        try {
            $this->urlValidator->validateShopeeOnly($m[0]);
        } catch (AffiliateScanException) {
            return null;
        }

        return $m[0];
    }
}
