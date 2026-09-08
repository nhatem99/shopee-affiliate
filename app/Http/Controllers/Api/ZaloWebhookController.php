<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AffiliateLinkRewriterService;
use App\Services\GanmaService;
use App\Services\KieuShopeeService;
use App\Services\ShopeeLinkResolverService;
use App\Services\ShortLinkService;
use App\Services\VoucherSourceResolver;
use App\Services\ZaloOaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ZaloWebhookController extends Controller
{
    private const DEFAULT_AFFILIATE_ID = 'an_17332410386';

    private const DISCLAIMER = "Giá dự kiến tính theo voucher đang áp dụng.\nMức giảm thực tế xác nhận tại checkout Shopee.\nWebsite có sử dụng link tiếp thị liên kết.";

    private const GROUP_NOTE = "⚠️ LƯU Ý KHI SĂN VOUCHER\n• Không thấy mã phù hợp: cập nhật lại link Shopee hoặc thử tài khoản khác.\n• Mỗi tài khoản chỉ nên dùng 1 loại voucher tối đa vài lần/ngày.";

    public function __construct(
        private ShopeeLinkResolverService $resolver,
        private KieuShopeeService $kieuShopee,
        private GanmaService $ganma,
        private VoucherSourceResolver $sources,
        private AffiliateLinkRewriterService $rewriter,
        private ShortLinkService $shortLinks,
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

        // Bot Zalo phải đi qua ĐÚNG nguồn mà admin đang chọn ở /admin/api-config. Trước đây nó
        // gọi thẳng kieushopee: bật ganma lên là bản ghi kieushopee bị tắt, KieuShopeeService
        // không còn tìm thấy hàng is_active nên âm thầm rơi về next_action mặc định trong code —
        // đúng cái ID mà migration của nó ghi rõ là hết hạn mỗi lần nguồn deploy lại.
        $source = $this->sources->activeSource();

        if ($source === GanmaService::SOURCE) {
            // Ganma chỉ nhận link ngắn từ app Shopee, nên truyền link GỐC trong tin nhắn chứ
            // không phải link đã bung. Không phải dạng đó thì im lặng bỏ qua — nhóm chat không
            // phải chỗ để giải thích chuyện cấu hình nguồn.
            $data = $this->ganma->canHandle($url) ? $this->ganma->fetchProductAndVoucherLink($url) : null;
        } else {
            $data = $this->kieuShopee->fetchProductAndVoucherLink($this->resolver->resolveCanonicalUrl($url));
        }

        if (! $data) {
            return response()->json(['ok' => true]);
        }

        $productName = $data['product']['product_name'] ?? 'Sản phẩm';

        $this->zalo->sendGroupText($groupId, $this->buildGroupReply(
            $productName,
            $this->buildShortLink($data['voucher_link'], $productName, $source)
        ));

        return response()->json(['ok' => true]);
    }

    private function extractShopeeUrl(string $text): ?string
    {
        if (! str_contains($text, 'shopee.vn') && ! str_contains($text, 'shp.ee')) {
            return null;
        }

        return preg_match('#https?://\S*(?:shopee\.vn|shp\.ee)\S*#i', $text, $m) ? $m[0] : null;
    }

    /**
     * Nguồn mã giờ chỉ trả về đúng một link nên tin nhắn cũng chỉ có một dòng link,
     * không còn phần liệt kê mã theo từng kênh (Mã FB/YTB/IG/Zalo) như thời salesoc.
     */
    private function buildGroupReply(string $productName, string $shortUrl): string
    {
        return implode("\n", [
            "🛒 {$productName}",
            '',
            "► Link đã áp mã: {$shortUrl}",
            '',
            self::GROUP_NOTE,
        ]);
    }

    private function buildShortLink(string $voucherUrl, string $productName, string $source): string
    {
        $targetUrl = $this->rewriter->rewriteToOwnAffiliate($voucherUrl);
        $link = $this->shortLinks->create($targetUrl, $source, $productName);

        return url('/go/'.$link->code);
    }
}
