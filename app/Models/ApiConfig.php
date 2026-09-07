<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'endpoint', 'app_id', 'app_secret', 'is_active', 'platform', 'meta'])]
class ApiConfig extends Model
{
    protected function casts(): array
    {
        return [
            'app_secret' => 'encrypted',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    /**
     * (Chỉ dùng cho platform 'facebook') Danh sách bài viết được phép nhận comment chứa link
     * affiliate, đã lọc rỗng và bỏ trùng.
     *
     * Có NHIỀU bài (meta.target_post_ids) thay vì một, để các sản phẩm khác nhau rải comment ra
     * nhiều bài thay vì dồn hết vào một chỗ: nhiều khách bấm cùng lúc thì mỗi sản phẩm rơi vào
     * một bài khác nhau, mỗi bài ít comment nên comment cần tìm không bị đẩy xuống dưới nút
     * "Xem thêm bình luận", và tốc độ đăng trên từng bài cũng thấp hơn nên đỡ bị Facebook đánh
     * dấu spam. Xem ShortLinkController::pickTargetPost.
     *
     * meta.target_post_id (số ít) là cấu hình đời đầu, vẫn đọc để bản đang chạy trên production
     * không mất tác dụng ngay khi deploy.
     *
     * @return list<string>
     */
    public function facebookTargetPostIds(): array
    {
        $pool = array_map('trim', array_filter(
            (array) ($this->meta['target_post_ids'] ?? []),
            'is_string',
        ));

        $pool = array_values(array_unique(array_filter($pool, fn (string $id) => $id !== '')));

        if ($pool) {
            return $pool;
        }

        $single = trim((string) ($this->meta['target_post_id'] ?? ''));

        return $single === '' ? [] : [$single];
    }

    /**
     * (Chỉ dùng cho platform 'facebook') Nhóm reels dùng cho chế độ đổi caption.
     *
     * Tách riêng khỏi facebookTargetPostIds() vì hai chế độ dùng reel/bài theo cách khác hẳn:
     * bài viết chỉ NHẬN THÊM comment (nội dung cũ giữ nguyên, dùng chung được), còn reel bị
     * GHI ĐÈ caption nên mỗi lúc chỉ phục vụ được một sản phẩm. Trộn chung một danh sách là
     * admin sẽ vô tình đưa bài viết vào chỗ cần reel.
     *
     * @return list<string>
     */
    public function facebookTargetReelIds(): array
    {
        $pool = array_map('trim', array_filter(
            (array) ($this->meta['target_reel_ids'] ?? []),
            'is_string',
        ));

        return array_values(array_unique(array_filter($pool, fn (string $id) => $id !== '')));
    }

    /** Chế độ đổi caption reel có đang bật không (và đã cấu hình đủ để chạy chưa). */
    public function facebookReelCaptionEnabled(): bool
    {
        return (bool) ($this->meta['reel_caption_enabled'] ?? false) && $this->facebookTargetReelIds();
    }

    /**
     * Số phút một reel bị giữ cho một sản phẩm. Dài quá thì hết slot khi đông khách; ngắn quá
     * thì khách còn đang xem reel đã bị đổi caption sang sản phẩm khác.
     */
    public function facebookReelLeaseMinutes(): int
    {
        return max(1, (int) ($this->meta['reel_lease_minutes'] ?? 10));
    }
}
