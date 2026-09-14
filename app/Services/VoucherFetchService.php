<?php

namespace App\Services;

use App\Exceptions\AffiliateScanException;
use Illuminate\Support\Facades\Log;

/**
 * Lấy mã cho một link Shopee theo nguồn admin đang chọn ở /admin/api-config. Là chỗ DUY NHẤT
 * quyết định gọi nguồn nào với link nào — tách khỏi ShopeeVoucherController để logic hai nguồn
 * (và chế độ gọi cả hai bên dưới) không nằm lẫn trong controller.
 *
 * Hai chế độ:
 *
 *  • kieushopee (mặc định): bung link ngắn rồi hỏi kieushopee, xong.
 *
 *  • ganma — "chế độ mã YTB": gọi CẢ HAI nguồn. Link đưa cho khách — và sau đó đặt vào caption
 *    reel / comment Facebook — là link của kieushopee, đổi affiliate về của mình y như chế độ
 *    mặc định; link YouTube của ganma đi kèm trong ref, và lúc khách bấm mua nó được xâu vào
 *    TRƯỚC link đích để trình duyệt của khách tự đi qua (Shopee ghi nhận mã YTB trên đúng máy
 *    khách — server tự mở hộ đã thử, không có tác dụng). Xem ShortLinkController::store().
 *    Ganma là bước bất đồng bộ ~20-45 giây nên lượt quét ở chế độ này chậm hơn hẳn — đã chấp
 *    nhận. Ganma không ra mã thì khách vẫn nhận link kieushopee bình thường, chỉ thiếu mã YTB.
 *    Chiều ngược lại — kieushopee không ra mã — thì rơi về link ganma, đúng hành vi cũ của chế
 *    độ này, thà có mã còn hơn không.
 */
class VoucherFetchService
{
    public function __construct(
        private VoucherSourceResolver $sources,
        private ShopeeLinkResolverService $resolver,
        private KieuShopeeService $kieuShopee,
        private GanmaService $ganma,
    ) {}

    /**
     * @throws AffiliateScanException Link không dùng được với nguồn đang chọn — thông điệp đã
     *                                viết cho khách đọc, người gọi chỉ việc hiện ra.
     */
    public function fetch(string $url): VoucherFetchResult
    {
        if ($this->sources->activeSource() !== GanmaService::SOURCE) {
            $canonicalUrl = $this->resolver->resolveCanonicalUrl($url);

            return new VoucherFetchResult(
                KieuShopeeService::SOURCE,
                $canonicalUrl,
                $canonicalUrl,
                $this->kieuShopee->fetchProductAndVoucherLink($canonicalUrl),
            );
        }

        // Ganma CHỈ nhận link ngắn từ app Shopee. Kiểm tra trước khi gọi bất cứ gì: báo ngay cho
        // khách dán lại đúng dạng link còn hơn im lặng bỏ bước kích hoạt YTB — admin bật chế độ
        // này là vì muốn mã YTB, khách mất nó mà không biết là mất đúng thứ admin đang cần.
        if (! $this->ganma->canHandle($url)) {
            throw new AffiliateScanException('Nguồn mã đang dùng chỉ nhận link chia sẻ từ ứng dụng Shopee. Vui lòng mở sản phẩm trong app Shopee, bấm Chia sẻ → Sao chép liên kết rồi dán lại.');
        }

        $ytb = $this->ganma->fetchProductAndVoucherLink($url);

        if (! $ytb) {
            Log::warning('VoucherFetchService: ganma không ra mã, khách chỉ nhận link kieushopee', ['url' => $url]);
        }

        $canonicalUrl = $this->resolver->resolveCanonicalUrl($url);
        $data = $this->kieuShopee->fetchProductAndVoucherLink($canonicalUrl);

        if ($data) {
            // Thông tin sản phẩm bên nào có thì dùng, khỏi phải hỏi lại Shopee.
            $data['product'] ??= $ytb['product'] ?? null;

            return new VoucherFetchResult(
                KieuShopeeService::SOURCE,
                $canonicalUrl,
                $canonicalUrl,
                $data,
                ytbUrl: $ytb['voucher_link'] ?? null,
            );
        }

        return new VoucherFetchResult(GanmaService::SOURCE, $canonicalUrl, $url, $ytb);
    }
}
