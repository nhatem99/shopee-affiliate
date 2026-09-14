<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Khoá nhận diện sản phẩm cho lớp Facebook: comment đã đăng và reel đang thuê đều được tra theo
 * khoá này, và job đối soát reel (facebook:sync-reels) cũng phải tính ra ĐÚNG khoá đó từ link
 * đọc được trong caption — nên logic nằm một chỗ, hai bên gọi chung.
 *
 * Khoá BẮT BUỘC phải ổn định giữa các lượt bấm của cùng một sản phẩm. Trước đây nó rơi về
 * short-code khi thiếu tên sản phẩm — mà short-code sinh mới mỗi lượt bấm, nên đúng lúc không
 * đọc được tên sản phẩm thì mỗi cú bấm lại đăng thêm một comment và thuê thêm một reel, hỏng
 * đúng thứ mà cache sinh ra để chặn.
 *
 * Ưu tiên shop_id/item_id trong URL đích: đó là danh tính thật của sản phẩm, không phụ thuộc
 * việc có đọc được tên hay không, và gộp đúng các lượt bấm của cùng một sản phẩm kể cả khi
 * nguồn trả về tên khác nhau giữa hai lần.
 */
class ProductKeyService
{
    public function __construct(private UrlValidationService $urlValidator) {}

    public function fromUrl(string $targetUrl, ?string $productName): string
    {
        if ($ids = $this->urlValidator->extractShopeeIds($targetUrl)) {
            return "{$ids['shop_id']}-{$ids['item_id']}";
        }

        // Không đọc được id (Shopee đổi dạng đường dẫn, hoặc chuỗi redirect dừng giữa chừng):
        // tên sản phẩm là lựa chọn tiếp theo, cuối cùng mới tới băm của URL đích — vẫn giống
        // nhau giữa các lượt bấm của cùng một sản phẩm, khác hẳn short-code.
        return Str::slug($productName ?: '') ?: 'url-'.substr(sha1($targetUrl), 0, 16);
    }
}
