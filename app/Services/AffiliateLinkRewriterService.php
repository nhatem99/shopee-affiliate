<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Đổi mmp_pid trong link voucher lấy từ kieushopee (shp.ee/shope.ee/s.afp.ad/...) sang
 * affiliate ID của mình, để hoa hồng đơn hàng về tài khoản của mình thay vì tài khoản
 * của nguồn cấp mã — trong khi vẫn giữ nguyên encrypted_payload/credential_token nên
 * mã giảm giá vẫn được áp dụng bình thường (mmp_pid là tham số tracking độc lập,
 * không nằm trong payload đã ký).
 */
class AffiliateLinkRewriterService
{
    private const HOPS_TO_FOLLOW = ['s.afp.ad', 'shp.ee', 'shope.ee', 's.shopee.vn', 'kieushopee.com'];

    private const MAX_HOPS = 4;

    public function rewriteToOwnAffiliate(string $url): string
    {
        try {
            $resolved = $this->followToShopee($url);
            $rewritten = $this->swapMmpPid($resolved) ?? $resolved;

            // Không lần được tới shopee.vn thì link đi tới khách VẪN NGUYÊN của nguồn: hoa hồng
            // về túi họ, và định danh của họ nằm luôn trong cột Sub_id của mình. Cố ý vẫn trả
            // link đó (khách còn giữ được mã giảm giá — mất mã đắt hơn mất một lượt hoa hồng),
            // nhưng phải kêu to, nếu không đây là kiểu hỏng âm thầm không bao giờ ai phát hiện.
            if ($rewritten === $url && $this->hostOf($url) !== 'shopee.vn') {
                Log::warning('AffiliateLinkRewriterService: KHÔNG đổi được affiliate — link giữ nguyên của nguồn, lượt này mất hoa hồng', [
                    'input_url' => $url,
                    'resolved_url' => $resolved,
                ]);
            }

            Log::info('AffiliateLinkRewriterService: rewrite hoàn tất', [
                'input_url' => $url,
                'resolved_url' => $resolved,
                'final_url' => $rewritten,
                'mmp_pid_swapped' => $rewritten !== $resolved,
            ]);

            return $rewritten;
        } catch (\Exception $e) {
            Log::warning('AffiliateLinkRewriterService failed: '.$e->getMessage(), ['input_url' => $url]);

            return $url;
        }
    }

    private function followToShopee(string $url): string
    {
        $current = $url;

        for ($i = 0; $i < self::MAX_HOPS; $i++) {
            $host = $this->hostOf($current);

            if ($host === 'shopee.vn') {
                Log::info('AffiliateLinkRewriterService: chạm tới shopee.vn', ['hop' => $i, 'url' => $current]);

                return $current;
            }

            if (! $this->isKnownHop($host)) {
                Log::warning('AffiliateLinkRewriterService: dừng theo dõi — domain lạ, không phải chuỗi redirect biết trước', [
                    'hop' => $i,
                    'host' => $host,
                    'url' => $current,
                ]);

                return $current;
            }

            $response = Http::withOptions(['allow_redirects' => false])->timeout(6)->get($current);
            $location = $response->header('Location');

            Log::info('AffiliateLinkRewriterService: theo dõi 1 hop redirect', [
                'hop' => $i,
                'from' => $current,
                'status' => $response->status(),
                'location' => $location,
            ]);

            if (! $location) {
                return $current;
            }

            $current = $location;
        }

        Log::warning('AffiliateLinkRewriterService: vượt quá MAX_HOPS mà chưa tới shopee.vn', ['last_url' => $current]);

        return $current;
    }

    private function swapMmpPid(string $url): ?string
    {
        if ($this->hostOf($url) !== 'shopee.vn') {
            return null;
        }

        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);

        if (! isset($query['mmp_pid'])) {
            Log::warning('AffiliateLinkRewriterService: URL shopee.vn nhưng không có mmp_pid để đổi', ['url' => $url]);

            return null;
        }

        $mmpPid = config('services.shopee_affiliate.mmp_pid');
        $query['mmp_pid'] = $mmpPid;

        // utm_source đi kèm mmp_pid cho khớp nhau — đây là affiliate ID, thứ Shopee vốn phải
        // biết để trả hoa hồng, không tiết lộ thêm gì về website nguồn.
        if (isset($query['utm_source'])) {
            $query['utm_source'] = $mmpPid;
        }

        // Nhãn nhận diện traffic của mình, lấy từ config chứ KHÔNG hard-code tên website.
        // Trước đây chỗ này đặt cứng 'tietkiemvi', tức mọi đơn hàng đều tự khai với Shopee
        // rằng traffic đến từ tietkiemvi.com — vừa lộ website, vừa nói ngược lại chính thiết
        // kế bọc qua comment Facebook (vốn để lượt click được tính là traffic từ Facebook).
        //
        // Chỉ thay khi link gốc đã có sẵn utm_content — không tự thêm tham số mới vào URL,
        // để không mở rộng thêm bề mặt thông tin gửi đi. Config để rỗng thì xoá hẳn tham số.
        //
        // An toàn: utm_* là tham số tracking độc lập, không nằm trong encrypted_payload /
        // credential_token đã ký — đổi hay bỏ đều không ảnh hưởng việc áp mã giảm giá.
        if (isset($query['utm_content'])) {
            $utmContent = $this->resolveSubId((string) $query['utm_content']);

            if ($utmContent === '') {
                unset($query['utm_content']);
            } else {
                $query['utm_content'] = $utmContent;
            }
        }

        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').($parts['path'] ?? '');

        return $base.'?'.http_build_query($query);
    }

    /**
     * Chọn nhãn sub_id gửi cho Shopee, dựa trên nhãn kênh mà nguồn cấp mã đã gắn sẵn.
     *
     * `utm_content` CHÍNH LÀ ô "Sub_id" trong báo cáo affiliate Shopee — không phải một tham
     * số utm thường. Nó gồm 5 khe nối bằng dấu "-" (đo thật trên production 08-09-2026: tool
     * /22 của kieushopee trả về "Test-22---", tool /20 trả "test-Test---"). kieushopee đặt tên
     * nhóm/tool của họ vào các khe đó, nên đây là chỗ DUY NHẤT trong response phân biệt được
     * mã lấy từ kênh nào — field `groupName` của họ tuy đúng nghĩa hơn nhưng đang bỏ trống ở
     * cả ba tool.
     *
     * Mã thuộc kênh Instagram hoặc YouTube được gắn nhãn riêng để tách khỏi FB trong báo cáo
     * Shopee; mọi kênh khác giữ nhãn mặc định như trước.
     *
     * Nhãn YouTube đến từ nguồn ganma.vn: link an_redir của họ mang `sub_id` dạng "YT3-<token>",
     * và khi đi theo redirect thì chính Shopee đổ nguyên văn giá trị đó sang `utm_content` —
     * nên khe đầu ('yt3') là chỗ nhận ra kênh. Đo thật trên production 08-09-2026.
     *
     * So khớp theo TỪNG KHE và phải bằng đúng marker, không phải "chuỗi có chứa 'ig'/'yt'" —
     * kiểu chứa sẽ khớp nhầm hàng loạt tên nhóm bình thường (Big, Signature, Original...) và âm
     * thầm dồn cả traffic FB sang nhãn khác.
     */
    private function resolveSubId(string $sourceUtmContent): string
    {
        // Tên kênh => [khoá config chứa nhãn gửi đi, khoá config chứa danh sách marker].
        $channels = [
            'Instagram' => ['utm_content_ig', 'ig_markers'],
            'YouTube' => ['utm_content_yt', 'yt_markers'],
        ];

        $slots = array_map(
            fn (string $slot) => mb_strtolower(trim($slot)),
            explode('-', $sourceUtmContent),
        );

        foreach ($channels as $name => [$labelKey, $markerKey]) {
            if (array_intersect($slots, $this->channelMarkers($markerKey)) === []) {
                continue;
            }

            $label = (string) config('services.shopee_affiliate.'.$labelKey);

            // Log để đối chiếu với cột Sub_id bên Shopee — chỗ xác nhận marker khớp đúng khi
            // kênh đó chạy thật.
            Log::info("AffiliateLinkRewriterService: nhận ra mã kênh {$name}", [
                'source_utm_content' => $sourceUtmContent,
                'sub_id' => $label,
            ]);

            return $label;
        }

        return (string) config('services.shopee_affiliate.utm_content');
    }

    /**
     * Chuẩn hoá về chữ thường để config viết 'IG' hay 'ig' đều chạy, và LOẠI GIÁ TRỊ RỖNG:
     * marker rỗng sẽ khớp với các khe trống của "Test-22---" (luôn có), tức mọi link đều bị gắn
     * nhãn kênh — hỏng âm thầm, báo cáo Shopee vẫn có số nên rất lâu mới phát hiện.
     *
     * @return list<string>
     */
    private function channelMarkers(string $configKey): array
    {
        return array_values(array_filter(
            array_map(
                fn ($marker) => mb_strtolower(trim((string) $marker)),
                (array) config('services.shopee_affiliate.'.$configKey),
            ),
            fn (string $marker) => $marker !== '',
        ));
    }

    /**
     * Khớp cả subdomain (vd sansale.kieushopee.com) — nguồn cấp mã hay đổi subdomain theo
     * từng tool, mà chặn nhầm ở đây thì link vẫn chạy nhưng mất luôn phần đổi mmp_pid.
     */
    private function isKnownHop(string $host): bool
    {
        foreach (self::HOPS_TO_FOLLOW as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private function hostOf(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        return preg_replace('/^www\./', '', $host);
    }
}
