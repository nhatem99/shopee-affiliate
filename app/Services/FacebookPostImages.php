<?php

namespace App\Services;

use App\Models\FacebookGroupDeal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Ảnh admin tự tải lên cho bài đăng nhóm Facebook (/admin/fb-posts).
 *
 * Nằm ở disk 'local' (storage/app/private), không công khai: chỉ admin xem được, và bot trên
 * điện thoại tải về qua /runner/fb/images/{name} kèm token. Tên file luôn do server sinh — tên
 * file của người tải lên không bao giờ thành tên file trên server.
 *
 * Trang admin tải từng ảnh lên NGAY lúc chọn (mỗi request một ảnh nhỏ, đã nén ở trình duyệt),
 * bài lưu sau chỉ mang tên file — nên có ảnh tải lên rồi bỏ, prune() dọn sau một ngày.
 */
class FacebookPostImages
{
    public const DIR = 'fb-post-images';

    /** Tổng số ảnh một bài, kể cả ảnh sản phẩm Shopee. */
    public const MAX_PER_POST = 5;

    /** Trình duyệt đã thu ảnh về cạnh dài 2048px nên thường dưới 1 MB — trần này chỉ để chặn bậy. */
    public const MAX_KB = 5120;

    public const NAME_PATTERN = '[a-f0-9]{32}\.(?:jpg|png|webp)';

    /** Ảnh tải lên mà không bài nào dùng quá chừng này thì xoá. */
    private const ORPHAN_HOURS = 24;

    public function store(UploadedFile $file): string
    {
        $extension = match ($file->extension()) {
            'png' => 'png',
            'webp' => 'webp',
            default => 'jpg',
        };
        $name = bin2hex(random_bytes(16)).'.'.$extension;

        Storage::disk('local')->putFileAs(self::DIR, $file, $name);

        return $name;
    }

    public function exists(string $name): bool
    {
        return self::validName($name) && Storage::disk('local')->exists(self::DIR.'/'.$name);
    }

    /** Đường dẫn tuyệt đối để trả file — null nếu tên lạ hoặc file không còn. */
    public function path(string $name): ?string
    {
        return $this->exists($name) ? Storage::disk('local')->path(self::DIR.'/'.$name) : null;
    }

    public static function validName(string $name): bool
    {
        return preg_match('/^'.self::NAME_PATTERN.'$/', $name) === 1;
    }

    /** Ảnh sản phẩm Shopee của bài — null nếu không có hoặc admin đã bỏ. */
    public function productImage(FacebookGroupDeal $deal): ?string
    {
        return $deal->with_product_image ? ($deal->product['product_image'] ?? null) : null;
    }

    /**
     * Ảnh bot phải đính kèm, theo thứ tự đăng: ảnh sản phẩm (link CDN Shopee) trước, rồi ảnh tải
     * lên dưới dạng đường dẫn trên chính server — bot ghép với base_url của nó và gửi kèm token.
     *
     * @return list<string>
     */
    public function refsFor(FacebookGroupDeal $deal): array
    {
        $refs = array_filter([$this->productImage($deal)]);
        foreach ($deal->images ?? [] as $name) {
            $refs[] = route('runner.fb.image', $name, false);
        }

        return array_slice(array_values($refs), 0, self::MAX_PER_POST);
    }

    /**
     * Ảnh cho trang admin xem — cùng thứ tự với refsFor().
     *
     * @return list<string>
     */
    public function adminUrlsFor(FacebookGroupDeal $deal): array
    {
        $urls = array_filter([$this->productImage($deal)]);
        foreach ($deal->images ?? [] as $name) {
            $urls[] = route('admin.fb-posts.image', $name);
        }

        return array_values($urls);
    }

    /** Xoá ảnh tải lên đã lâu mà không bài nào dùng (admin chọn rồi bỏ, hoặc không lưu bài). */
    public function prune(): int
    {
        $disk = Storage::disk('local');
        $used = FacebookGroupDeal::whereNotNull('images')->pluck('images')->flatten()->flip();
        $cutoff = now()->subHours(self::ORPHAN_HOURS)->getTimestamp();

        $deleted = 0;
        foreach ($disk->files(self::DIR) as $file) {
            $name = basename($file);
            if (! isset($used[$name]) && $disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
