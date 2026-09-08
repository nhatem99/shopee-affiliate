<?php

namespace App\Services;

use App\Models\ShortLink;
use Illuminate\Support\Str;

class ShortLinkService
{
    /**
     * Short-link cũ hơn khoảng này thì tạo hàng mới thay vì dùng lại. Giữ lại để thống kê click
     * theo giai đoạn còn ý nghĩa — không thì một sản phẩm bán quanh năm chỉ có đúng một hàng với
     * số click cộng dồn từ đầu, không biết lượt nào của tháng nào.
     *
     * 7 ngày để khớp với vòng đời của voucher_ref (xem ShopeeVoucherController::maskVoucherLink),
     * tức là khoảng thời gian một lần "lấy mã" còn bấm lại được.
     */
    private const REUSE_WINDOW_DAYS = 7;

    /**
     * Tạo short-link cho $targetUrl, hoặc dùng lại hàng còn hạn đang trỏ tới đúng URL đó.
     *
     * Trước đây mỗi lượt bấm "Mua ngay" đều INSERT một hàng mới, nên bảng short_links phình theo
     * SỐ LƯỢT BẤM chứ không theo số sản phẩm: một sản phẩm hot bấm 200 lần là 200 hàng cùng
     * target_url, mỗi hàng đúng 1 click. Cùng target_url nghĩa là cùng đích đến nên dùng lại được
     * — khách vẫn tới đúng chỗ, và click của cùng một sản phẩm được cộng dồn vào một hàng thay vì
     * rải ra.
     *
     * Dùng lại còn làm short-code ỔN ĐỊNH giữa các lượt bấm, thứ mà lớp Facebook cần: comment đã
     * đăng và reel đang thuê đều được nhớ theo sản phẩm (xem ShortLinkController::productKey).
     */
    public function create(string $targetUrl, ?string $source = null, ?string $productName = null, ?string $productImage = null): ShortLink
    {
        $hash = sha1($targetUrl);

        $existing = ShortLink::where('target_hash', $hash)
            ->where('source', $source)
            ->where('created_at', '>=', now()->subDays(self::REUSE_WINDOW_DAYS))
            ->latest('id')
            ->first();

        if ($existing) {
            // Lượt bấm trước có thể chưa đọc được tên/ảnh sản phẩm. Bổ sung khi lượt này có, vì
            // card preview khi chia sẻ link đọc thẳng từ hàng này (short-link-preview.blade.php).
            // Không ghi đè giá trị đã có — dữ liệu cũ đã qua kiểm chứng thực tế rồi.
            $existing->fill([
                'product_name' => $existing->product_name ?: $productName,
                'product_image' => $existing->product_image ?: $productImage,
            ])->save();

            return $existing;
        }

        do {
            $code = Str::random(7);
        } while (ShortLink::where('code', $code)->exists());

        return ShortLink::create([
            'code' => $code,
            'target_url' => $targetUrl,
            'target_hash' => $hash,
            'source' => $source,
            'product_name' => $productName,
            'product_image' => $productImage,
        ]);
    }

    public function find(string $code): ?ShortLink
    {
        return ShortLink::where('code', $code)->first();
    }

    public function trackClick(ShortLink $link): void
    {
        $link->increment('clicks');
    }
}
