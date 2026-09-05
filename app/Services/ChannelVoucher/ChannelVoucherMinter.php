<?php

namespace App\Services\ChannelVoucher;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tự đúc link Shopee CÓ MÃ. Đây là nguồn mã duy nhất của hệ thống (nguồn cũ salesoc.vn đã gỡ 2026-09-05).
 *
 * Chuỗi việc cho mỗi kênh (fb/ig):
 *   1. Dựng link affiliate trơn mang mmp_pid của KOL          (ChannelVoucherLinkBuilder)
 *   2. Comment link đó vào một bài đăng cố định               (FacebookGraphClient)
 *   3. Đọc permalink của comment vừa đăng                     (FacebookGraphClient)
 *   4. Mở permalink bằng Chromium thật và bấm vào link        (BrowserLinkResolver)
 *   5. URL đích thu được chính là link Shopee đã đúc mã       <- sản phẩm của service này
 *   6. Xoá comment cho sạch bài đăng
 *
 * Link trả về vẫn mang mmp_pid của KOL. CỐ Ý không đổi ở đây: việc đổi sang tài khoản của mình
 * (KOL -> KOC) đã có AffiliateLinkRewriterService làm đúng một chỗ, ngay trước lúc khách bấm mua.
 */
class ChannelVoucherMinter
{
    /**
     * Bảng kênh. 'source' phải trùng key mà frontend đang dùng để chọn màu/icon nút
     * (xem SOURCE_STYLES trong resources/js/Pages/Home.vue) — thêm kênh mới phải thêm cả bên đó.
     */
    private const CHANNELS = [
        'fb' => [
            'source' => 'facebook',
            'label' => 'Mã FB',
            'object' => 'services.facebook.post_id',
            'token' => 'services.facebook.page_access_token',
        ],
        'ig' => [
            'source' => 'instagram',
            'label' => 'Mã IG',
            'object' => 'services.facebook.ig_media_id',
            'token' => 'services.facebook.ig_access_token',
        ],
    ];

    // Dấu hiệu link ĐÃ được Shopee đúc mã. Thiếu cả hai nghĩa là chỉ thu về link trơn của chính
    // mình — nghĩa là chuỗi trên chạy nhưng không sinh ra mã nào, phải coi là thất bại chứ không
    // được trả cho khách một nút "Mã FB" bấm vào chẳng giảm đồng nào.
    private const SIGNATURE_PARAMS = ['credential_token', 'encrypted_payload'];

    public function __construct(
        private ChannelVoucherLinkBuilder $builder,
        private FacebookGraphClient $graph,
        private BrowserLinkResolver $browser,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('services.channel_voucher.enabled');
    }

    /**
     * Đúc mã, có cache theo SẢN PHẨM. Đây là cửa vào mà mọi nơi nên dùng (web lẫn bot Zalo).
     *
     * Cache ở tầng này chứ không phải tầng gọi, vì lý do cache không phải là tốc độ mà là KIỀM
     * CHẾ SỐ COMMENT: mỗi lần đúc là một comment thật lên Facebook. Hai khách cùng xem một sản
     * phẩm hot, hoặc một người dán lại link, đều phải dùng chung một lần đúc.
     *
     * Kết quả RỖNG (đúc hỏng) cũng được cache, và đó là chủ ý: hỏng thường là hỏng ở tầng
     * Facebook/Chromium, thử lại ngay chỉ tổ đẻ thêm comment rác đúng lúc không nên đụng vào
     * Page nhất.
     *
     * @return array<string, list<array{label: string, url: string}>>
     */
    public function mintCached(string $canonicalUrl): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        // Khoá băm theo URL ĐÃ CHUẨN HOÁ (chỉ shop_id + item_id), không phải URL thô. Link khách
        // dán vào luôn kèm tham số tracking riêng của họ, nên băm URL thô thì hai người xem cùng
        // một sản phẩm ra hai khoá khác nhau: cache gần như không bao giờ trúng và mỗi lượt quét
        // lại đẻ thêm comment thật lên Facebook.
        return Cache::remember(
            'channel_voucher:'.md5($this->builder->productUrl($canonicalUrl)),
            now()->addMinutes((int) config('services.channel_voucher.cache_minutes', 15)),
            fn () => $this->mint($canonicalUrl),
        );
    }

    /**
     * Đúc mã cho tất cả kênh đang bật, trả về đúng shape voucher_links mà frontend đang dùng.
     *
     * @return array<string, list<array{label: string, url: string}>>
     */
    public function mint(string $canonicalUrl): array
    {
        $links = [];

        foreach ((array) config('services.channel_voucher.channels', []) as $channel) {
            try {
                $option = $this->mintChannel($canonicalUrl, $channel);
            } catch (\Exception $e) {
                // Một kênh nổ không được kéo theo kênh còn lại: mã FB và mã IG đi qua hai tài khoản,
                // hai API khác nhau, hỏng cái này không có nghĩa cái kia cũng hỏng.
                Log::error("ChannelVoucherMinter: kênh '{$channel}' nổ giữa chừng: ".$e->getMessage(), [
                    'shopee_url' => $canonicalUrl,
                ]);

                continue;
            }

            if ($option) {
                $links[self::CHANNELS[$channel]['source']] = [$option];
            }
        }

        return $links;
    }

    /**
     * @return array{label: string, url: string}|null
     */
    public function mintChannel(string $canonicalUrl, string $channel): ?array
    {
        $steps = $this->run($canonicalUrl, $channel);
        $last = end($steps);

        if (! $last || ! $last['ok'] || ! isset($last['url'])) {
            return null;
        }

        return ['label' => self::CHANNELS[$channel]['label'], 'url' => $last['url']];
    }

    /**
     * Chạy chuỗi đúc và trả lại TỪNG bước kèm kết quả — `php artisan voucher:mint-check` in
     * thẳng mảng này ra. Cơ chế có 5 mắt xích ở 3 hệ thống khác nhau (Graph API, Chromium,
     * Shopee); khi hỏng, câu hỏi luôn là "gãy ở mắt nào", nên phải trả lời được bằng một lệnh.
     *
     * $keepComment / $skipBrowser chỉ dùng cho việc KIỂM CHỨNG BẰNG TAY (`voucher:mint-check`):
     * dừng lại sau khi có permalink và để nguyên comment trên bài đăng, để người thật mở comment
     * đó trên điện thoại và tự xem Shopee có đúc mã hay không. Luồng chạy cho khách không bao giờ
     * dùng hai cờ này — comment luôn phải được dọn.
     *
     * @return list<array{step: string, ok: bool, detail: string, duration_ms: int, url?: string}>
     */
    public function run(
        string $canonicalUrl,
        string $channel,
        bool $keepComment = false,
        bool $skipBrowser = false,
    ): array {
        if (! isset(self::CHANNELS[$channel])) {
            return [$this->step('kênh', false, "Kênh '{$channel}' không có trong bảng kênh", 0)];
        }

        $config = self::CHANNELS[$channel];
        $objectId = (string) config($config['object']);
        $accessToken = (string) config($config['token']);

        if ($objectId === '' || $accessToken === '') {
            return [$this->step('cấu hình', false, "Thiếu {$config['object']} hoặc {$config['token']}", 0)];
        }

        $marker = 'tkv'.Str::lower(Str::random(9));
        $link = $this->builder->build($canonicalUrl, $channel, $marker);

        $steps = [$this->step('dựng link KOL', true, $link, 0)];
        $commentId = null;

        try {
            $startedAt = microtime(true);
            $commentId = $this->graph->comment($objectId, $link, $accessToken);
            $steps[] = $this->step(
                'comment lên '.$channel,
                $commentId !== null,
                $commentId ?? 'Graph API từ chối — xem /admin/logs để biết mã lỗi',
                $this->msSince($startedAt),
            );

            if ($commentId === null) {
                return $steps;
            }

            $startedAt = microtime(true);
            $permalink = $this->permalinkFor($channel, $commentId, $objectId, $accessToken);
            $steps[] = $this->step(
                'lấy permalink',
                $permalink !== null,
                $permalink ?? 'Không đọc được permalink của comment',
                $this->msSince($startedAt),
            );

            if ($permalink === null || $skipBrowser) {
                return $steps;
            }

            $timeout = (int) config('services.channel_voucher.timeout', 60);
            $resolved = $this->browser->resolve($permalink, $marker, $timeout);
            $steps[] = $this->step(
                'mở bằng trình duyệt',
                $resolved !== null,
                $resolved['final_url'] ?? 'Trình duyệt không tìm/bấm được link (xem log browser-resolver)',
                $resolved['duration_ms'] ?? 0,
            );

            if ($resolved === null) {
                return $steps;
            }

            $hasCode = $this->carriesVoucherSignature($resolved['final_url']);
            $steps[] = $this->step(
                'kiểm tra chữ ký mã',
                $hasCode,
                $hasCode
                    ? 'Có credential_token/encrypted_payload — link này CÓ mã'
                    : 'URL đích không có credential_token/encrypted_payload — chỉ là link trơn, KHÔNG có mã',
                0,
                $hasCode ? $resolved['final_url'] : null,
            );

            if (! $hasCode) {
                Log::warning('ChannelVoucherMinter: chuỗi chạy xong nhưng không ra mã', [
                    'channel' => $channel,
                    'shopee_url' => $canonicalUrl,
                    'final_url' => $resolved['final_url'],
                ]);
            }

            return $steps;
        } finally {
            // Dọn comment kể cả khi hỏng giữa chừng — thất bại mà không dọn thì bài đăng vẫn đầy
            // rác y như thành công, chỉ khác là không ai để ý cho tới lúc Page bị Facebook khoá.
            if ($commentId !== null && ! $keepComment && config('services.channel_voucher.delete_comment_after')) {
                $this->graph->deleteComment($commentId, $accessToken);
            }
        }
    }

    /**
     * Comment Facebook có permalink_url riêng. Comment Instagram thì KHÔNG (Graph API không trả
     * field này cho IG comment), nên phải lùi về permalink của cả media rồi để trình duyệt tự tìm
     * comment của mình trong đó bằng marker.
     */
    private function permalinkFor(string $channel, string $commentId, string $objectId, string $accessToken): ?string
    {
        $details = $this->graph->commentDetails($commentId, $accessToken);

        if ($details && $details['permalink_url']) {
            return $details['permalink_url'];
        }

        return $channel === 'ig'
            ? $this->graph->mediaPermalink($objectId, $accessToken)
            : null;
    }

    private function carriesVoucherSignature(string $url): bool
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        foreach (self::SIGNATURE_PARAMS as $param) {
            if (! empty($query[$param])) {
                return true;
            }
        }

        return false;
    }

    private function step(string $name, bool $ok, string $detail, int $durationMs, ?string $url = null): array
    {
        $step = ['step' => $name, 'ok' => $ok, 'detail' => $detail, 'duration_ms' => $durationMs];

        if ($url !== null) {
            $step['url'] = $url;
        }

        return $step;
    }

    private function msSince(float $startedAt): int
    {
        return (int) ((microtime(true) - $startedAt) * 1000);
    }
}
