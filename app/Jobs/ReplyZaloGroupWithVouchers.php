<?php

namespace App\Jobs;

use App\Services\AffiliateLinkRewriterService;
use App\Services\ChannelVoucher\ChannelVoucherMinter;
use App\Services\ShopeeLinkResolverService;
use App\Services\ShortLinkService;
use App\Services\ZaloOaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Trả lời một nhóm Zalo bằng link đã áp mã, chạy nền.
 *
 * Trước đây việc này làm thẳng trong webhook (gọi salesoc.vn mất 2-8s). Đúc mã của chính mình tốn
 * 10-60s — quá lâu để giữ một webhook: Zalo sẽ coi là timeout rồi gửi lại cùng sự kiện, và mỗi lần
 * gửi lại là thêm một comment lên Facebook. Nên webhook giờ chỉ nhận rồi trả ok ngay, việc nặng
 * nằm ở đây.
 */
class ReplyZaloGroupWithVouchers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Cùng lý do với ResolveVoucherLinks: thử lại = thêm comment rác lên Facebook.
    public int $tries = 1;

    public int $timeout = 180;

    private const DISCLAIMER_NOTE = "⚠️ LƯU Ý KHI SĂN VOUCHER\n• Không thấy mã phù hợp: cập nhật lại link Shopee hoặc thử tài khoản khác.\n• Mỗi tài khoản chỉ nên dùng 1 loại voucher tối đa vài lần/ngày.";

    private const SOURCE_LABELS = [
        'facebook' => '🔵 Mã Facebook',
        'instagram' => '🟣 Mã Instagram',
        'zalo' => '🟢 Mã Zalo',
        'youtube' => '🔴 Mã YouTube',
    ];

    public function __construct(
        private readonly string $groupId,
        private readonly string $canonicalUrl,
    ) {}

    public function handle(
        ChannelVoucherMinter $minter,
        ShopeeLinkResolverService $resolver,
        AffiliateLinkRewriterService $rewriter,
        ShortLinkService $shortLinks,
        ZaloOaService $zalo,
    ): void {
        $links = $minter->mintCached($this->canonicalUrl);

        if ($links === []) {
            // Im lặng có chủ đích: nhắn "không tìm thấy mã" vào nhóm mỗi lần hỏng chỉ làm phiền
            // nhóm và để lộ là bot đang hỏng. Dấu vết vẫn nằm ở /admin/logs.
            Log::warning('ReplyZaloGroupWithVouchers: không đúc được mã nào', [
                'group_id' => $this->groupId,
                'shopee_url' => $this->canonicalUrl,
            ]);

            return;
        }

        $ids = $resolver->extractIds($this->canonicalUrl);
        $product = $ids ? $resolver->fetchProductInfo($ids['item_id'], $ids['shop_id']) : null;

        $reply = $this->buildReply(
            $product['product_name'] ?? 'Sản phẩm',
            $links,
            $rewriter,
            $shortLinks,
        );

        if ($reply) {
            $zalo->sendGroupText($this->groupId, $reply);
        }
    }

    /**
     * @param  array<string, list<array{label: string, url: string}>>  $voucherLinks
     */
    private function buildReply(
        string $productName,
        array $voucherLinks,
        AffiliateLinkRewriterService $rewriter,
        ShortLinkService $shortLinks,
    ): ?string {
        $topLines = [];
        $breakdownBlocks = [];

        foreach (self::SOURCE_LABELS as $source => $label) {
            $options = $voucherLinks[$source] ?? [];
            if (empty($options)) {
                continue;
            }

            // Khác luồng web (link được giấu sau token 'ref' rồi mới đổi mmp_pid lúc khách bấm),
            // ở đây phải gửi thẳng một URL bấm được vào nhóm chat — nên đổi mmp_pid ngay bây giờ.
            $targetUrl = $rewriter->rewriteToOwnAffiliate($options[0]['url']);
            $link = $shortLinks->create($targetUrl, $source, $productName);

            $topLines[] = "► Link áp mã {$this->sourceName($source)}: ".url('/go/'.$link->code);

            $bullets = collect($options)->map(fn ($o) => "• {$o['label']}")->implode("\n");
            $breakdownBlocks[] = "{$label}\n{$bullets}";
        }

        if ($topLines === []) {
            return null;
        }

        return implode("\n", [
            "🛒 {$productName}",
            '',
            ...$topLines,
            '',
            implode("\n\n", $breakdownBlocks),
            '',
            self::DISCLAIMER_NOTE,
        ]);
    }

    private function sourceName(string $source): string
    {
        return match ($source) {
            'facebook' => 'Facebook',
            'youtube' => 'YouTube',
            'instagram' => 'Instagram',
            'zalo' => 'Zalo',
            default => ucfirst($source),
        };
    }
}
