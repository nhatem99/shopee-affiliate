<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một lượt đăng một deal vào một nhóm — đơn vị việc bot trên máy nhà nhận về làm.
 */
#[Fillable(['facebook_group_deal_id', 'facebook_group_id', 'facebook_profile_id', 'status', 'queued_at', 'claim_key', 'claimed_at', 'finished_at', 'caption', 'buy_url', 'link_kind', 'short_link_id', 'error', 'review_state', 'reviewed_at', 'post_url', 'comment', 'comment_status', 'comment_attempts', 'comment_claimed_at', 'commented_at', 'comment_error'])]
class FacebookGroupPost extends Model
{
    public const PENDING = 'pending';

    public const CLAIMED = 'claimed';

    public const POSTED = 'posted';

    /** Nhóm bắt admin duyệt bài — đã gửi, chưa chắc hiện. */
    public const PENDING_APPROVAL = 'pending_approval';

    /** Chưa bấm Đăng thì hỏng (trang không tải, không thấy ô soạn bài...) — không có gì lên nhóm. */
    public const FAILED = 'failed';

    /** Đã bấm Đăng nhưng không xác nhận được kết quả — có thể đã lên. Không bao giờ tự đăng lại. */
    public const AMBIGUOUS = 'ambiguous';

    /** Nhóm không cho nick này đăng (chưa được duyệt vào, chỉ admin được đăng...). */
    public const NOT_ALLOWED = 'not_allowed';

    /** Facebook báo tạm chặn tính năng đăng bài. */
    public const BLOCKED = 'blocked';

    /** Nick bị đăng xuất hoặc bắt xác minh. */
    public const CHECKPOINT = 'checkpoint';

    /**
     * Bot không chuyển được sang page được giao — chưa mở nhóm, chưa có gì lên Facebook. Chỉ là
     * kết quả bot báo về: server đưa bài về hàng chờ cho page khác và cho page đó nghỉ.
     */
    public const SWITCH_FAILED = 'switch_failed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** Kết quả bot được phép báo về. */
    public const RESULTS = [
        self::POSTED, self::PENDING_APPROVAL, self::FAILED, self::AMBIGUOUS,
        self::NOT_ALLOWED, self::BLOCKED, self::CHECKPOINT, self::SWITCH_FAILED,
    ];

    /** Tính vào trần bài mỗi ngày: mọi lượt có thể đã đưa bài lên Facebook. */
    public const COUNTS_TOWARD_CAP = [self::CLAIMED, self::POSTED, self::PENDING_APPROVAL, self::AMBIGUOUS];

    /**
     * Bài nằm ở đâu trong "Nội dung của bạn" của nhóm — bot kiểm tra sau khi đăng
     * (FacebookGroupReviewChecker). Khác status: status là điều bot thấy lúc bấm Đăng.
     */
    public const REVIEW_PUBLISHED = 'published';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_DECLINED = 'declined';

    public const REVIEW_REMOVED = 'removed';

    /** Tab đang chờ và đã đăng đều mở được mà không thấy bài — có thể đã bị xoá, hoặc chưa từng lên. */
    public const REVIEW_MISSING = 'missing';

    /**
     * Bình luận link mua vào bài (bài "link ở bình luận" — FacebookGroupCommentQueue). Chờ bài lên
     * nhóm (đăng thẳng, hoặc admin nhóm đã duyệt) rồi bot mới bình luận.
     */
    public const COMMENT_PENDING = 'pending';

    public const COMMENT_DONE = 'done';

    /** Bot đã bấm gửi nhưng không thấy bình luận hiện ra — không tự bình luận lại kẻo trùng. */
    public const COMMENT_UNKNOWN = 'unknown';

    /** Thử đủ lượt vẫn không bình luận được, hoặc bài không còn trên nhóm. */
    public const COMMENT_FAILED = 'failed';

    /** Admin được bấm "đăng lại". AMBIGUOUS cũng được, nhưng trang admin hỏi lại vì có thể trùng. */
    public const RETRYABLE = [
        self::FAILED, self::AMBIGUOUS, self::NOT_ALLOWED, self::BLOCKED, self::CHECKPOINT,
        self::EXPIRED, self::CANCELLED,
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'claimed_at' => 'datetime',
            'finished_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'comment_claimed_at' => 'datetime',
            'commented_at' => 'datetime',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(FacebookGroupDeal::class, 'facebook_group_deal_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(FacebookGroup::class, 'facebook_group_id');
    }

    /** Page (hoặc nick chính) bot dùng để đăng bài này — gán lúc bot nhận bài. */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(FacebookProfile::class, 'facebook_profile_id');
    }

    public function shortLink(): BelongsTo
    {
        return $this->belongsTo(ShortLink::class);
    }
}
