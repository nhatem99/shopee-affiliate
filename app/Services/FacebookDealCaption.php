<?php

namespace App\Services;

use Illuminate\Support\Str;
use Random\Randomizer;

/**
 * Nội dung bài deal trong nhóm Facebook.
 *
 * Mẫu bài có hai thứ đặc biệt:
 *  • {link}   — chỗ đặt link mua, phải đứng riêng một dòng (chế độ mã YTB nó thành 4 dòng);
 *  • {a|b|c}  — mỗi nhóm nhận ngẫu nhiên một lựa chọn, lồng nhau được: {Deal {hời|ngon}|Giá tốt}.
 * Cùng một câu chữ y hệt đăng vào nhiều nhóm là dấu hiệu spam rõ nhất với Facebook.
 */
class FacebookDealCaption
{
    /** Ngoặc nhọn có dấu | bên trong và không chứa ngoặc khác — tức lựa chọn trong cùng. */
    private const SPIN_PATTERN = '/\{([^{}]*\|[^{}]*)\}/u';

    /**
     * @param  bool  $withVoucher  false khi bài chỉ có link affiliate không mã (công tắc ở
     *                             DirectAffiliateLinkService) — khỏi hứa "mã có hạn" khi chẳng có mã.
     */
    public function defaultTemplate(?array $product, bool $withVoucher = true): string
    {
        $name = $this->plain($product['product_name'] ?? '') ?: 'Sản phẩm Shopee đang giảm giá';

        $lines = ['{🔥|⚡|💥} {Deal hời|Deal ngon|Giá tốt} {hôm nay|đang chạy}: '.Str::limit($name, 150)];

        $price = $product['discounted_price'] ?? null;
        $original = $product['original_price'] ?? null;
        $percent = $product['discount_percent'] ?? null;
        if (is_numeric($price) && $price > 0) {
            $priceLine = '💰 Giá còn '.$this->vnd($price);
            if (is_numeric($original) && $original > $price) {
                $priceLine .= ' (giá gốc '.$this->vnd($original).')';
            }
            if (is_numeric($percent) && $percent > 0) {
                $priceLine .= ', giảm '.round($percent).'%';
            }
            $lines[] = $priceLine;
        }

        $lines[] = '';
        $lines[] = '{link}';
        $lines[] = '';
        $lines[] = $withVoucher
            ? '{Mã có giới hạn lượt|Số lượng mã có hạn}, {nhanh tay kẻo hết|tranh thủ nhé} {👇|🛒|⏰}'
            : '{Giá có thể đổi bất cứ lúc nào|Giá tốt không chờ ai}, {nhanh tay kẻo lỡ|tranh thủ nhé} {👇|🛒|⏰}';

        return implode("\n", $lines);
    }

    /**
     * Caption Reel đăng lên page (/admin/fb-videos). Link lên ngay dòng thứ hai: caption Reel chỉ
     * hiện 1–2 dòng, còn lại nằm sau "Xem thêm" — y như lý do của FacebookReelSlotService::caption.
     * Link trong caption Reel bấm được (đo 08-09-2026), link trong bình luận Reel thì không.
     */
    public function reelTemplate(?array $product, bool $withVoucher = true): string
    {
        $name = $this->plain($product['product_name'] ?? '') ?: 'Sản phẩm Shopee đang giảm giá';

        $lines = ['{🔥|⚡|💥} '.Str::limit($name, 120), '{link}', ''];

        $price = $product['discounted_price'] ?? null;
        $original = $product['original_price'] ?? null;
        if (is_numeric($price) && $price > 0) {
            $lines[] = '💰 Giá còn '.$this->vnd($price).(is_numeric($original) && $original > $price ? ' (giá gốc '.$this->vnd($original).')' : '');
        }

        $lines[] = $withVoucher
            ? '{Mã có giới hạn lượt|Số lượng mã có hạn}, {nhanh tay kẻo hết|tranh thủ nhé} {🛒|⏰}'
            : '{Giá có thể đổi bất cứ lúc nào|Giá tốt không chờ ai}, {nhanh tay kẻo lỡ|tranh thủ nhé} {🛒|⏰}';

        return implode("\n", $lines);
    }

    /**
     * Hai dòng chữ mặc định chèn lên video (FacebookVideoEditor). Không emoji — font trên VPS không
     * có hình emoji, ffmpeg sẽ vẽ ô vuông.
     *
     * @return array{top: string, bottom: ?string}
     */
    public function videoOverlay(?array $product): array
    {
        $name = trim(preg_replace('/[^\p{L}\p{N}\p{P}\p{Zs}]/u', '', (string) ($product['product_name'] ?? '')));

        $price = $product['discounted_price'] ?? null;
        $percent = $product['discount_percent'] ?? null;
        $bottom = null;
        if (is_numeric($price) && $price > 0) {
            $bottom = 'Chỉ còn '.number_format($price, 0, ',', '.').'đ'
                .(is_numeric($percent) && $percent > 0 ? ' - giảm '.round($percent).'%' : '');
        }

        return ['top' => Str::limit($name, 90, '…'), 'bottom' => $bottom];
    }

    /**
     * Thay cho {link} khi link để ở bình luận (FacebookGroupCommentQueue) — bài không còn link nào.
     * Cũng bốc {a|b} như mẫu bài.
     */
    public function commentHint(bool $withVoucher = true): string
    {
        return $withVoucher
            ? '{👇|💬} {Link mua có mã giảm giá|Link có mã giảm giá|Mã giảm giá + link mua} {mình để ở bình luận|ở bình luận bên dưới|trong phần bình luận} {nhé|nha|}'
            : '{👇|💬} {Link mua|Link sản phẩm} {mình để ở bình luận|ở bình luận bên dưới|trong phần bình luận} {nhé|nha|}';
    }

    public function render(string $template, string $linkBlock, ?Randomizer $rng = null): string
    {
        $rng ??= new Randomizer;
        $text = str_replace("\r\n", "\n", $template);

        // Thay từ trong ra ngoài cho tới khi hết lựa chọn — {link} không có dấu | nên không khớp.
        do {
            $text = preg_replace_callback(self::SPIN_PATTERN, function (array $match) use ($rng) {
                $options = explode('|', $match[1]);

                return $options[$rng->getInt(0, count($options) - 1)];
            }, $text, -1, $count);
        } while ($count > 0);

        $text = str_replace('{link}', $linkBlock, $text);

        // Lựa chọn rỗng ({👇|}) để lại khoảng trắng thừa cuối dòng.
        return trim(preg_replace('/[ \t]+$/m', '', $text));
    }

    public function vnd(int|float $amount): string
    {
        return number_format($amount, 0, ',', '.').'₫';
    }

    /** Tên sản phẩm có ngoặc nhọn thì vỡ cú pháp mẫu bài — đổi sang ngoặc tròn. */
    private function plain(string $text): string
    {
        return trim(strtr($text, ['{' => '(', '}' => ')']));
    }
}
