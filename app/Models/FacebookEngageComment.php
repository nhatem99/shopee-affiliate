<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một lượt bình luận dạo trong nhóm follow chéo — xem FacebookEngageQueue.
 */
#[Fillable(['facebook_group_id', 'facebook_profile_id', 'status', 'comment', 'post_url', 'post_key', 'author', 'error', 'claimed_at', 'finished_at'])]
class FacebookEngageComment extends Model
{
    /** Đã giao cho bot, chưa báo kết quả. */
    public const CLAIMED = 'claimed';

    public const COMMENTED = 'commented';

    /** Đã bấm gửi nhưng không thấy bình luận hiện ra. */
    public const AMBIGUOUS = 'ambiguous';

    /** Bảng tin nhóm không còn bài nào chưa bình luận. */
    public const NO_POST = 'no_post';

    /** Page chưa vào nhóm, hoặc nhóm không cho page bình luận. */
    public const NOT_ALLOWED = 'not_allowed';

    /** Chưa gửi gì (trang không tải, không thấy ô bình luận...). */
    public const FAILED = 'failed';

    /** Facebook tạm chặn bình luận. */
    public const BLOCKED = 'blocked';

    public const CHECKPOINT = 'checkpoint';

    /** Bot không báo kết quả — có thể đã bình luận. */
    public const UNKNOWN = 'unknown';

    /** Kết quả bot được phép báo về. */
    public const RESULTS = [self::COMMENTED, self::AMBIGUOUS, self::NO_POST, self::NOT_ALLOWED, self::FAILED, self::BLOCKED, self::CHECKPOINT];

    /** Tính vào trần mỗi ngày: mọi lượt có thể đã để lại bình luận trên Facebook. */
    public const COUNTS_TOWARD_CAP = [self::CLAIMED, self::COMMENTED, self::AMBIGUOUS, self::UNKNOWN];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(FacebookGroup::class, 'facebook_group_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(FacebookProfile::class, 'facebook_profile_id');
    }
}
