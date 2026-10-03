<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một lượt đăng một deal vào một nhóm — đơn vị việc bot trên máy nhà nhận về làm.
 */
#[Fillable(['facebook_group_deal_id', 'facebook_group_id', 'status', 'queued_at', 'claim_key', 'claimed_at', 'finished_at', 'caption', 'buy_url', 'link_kind', 'short_link_id', 'error'])]
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

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** Kết quả bot được phép báo về. */
    public const RESULTS = [
        self::POSTED, self::PENDING_APPROVAL, self::FAILED, self::AMBIGUOUS,
        self::NOT_ALLOWED, self::BLOCKED, self::CHECKPOINT,
    ];

    /** Tính vào trần bài mỗi ngày: mọi lượt có thể đã đưa bài lên Facebook. */
    public const COUNTS_TOWARD_CAP = [self::CLAIMED, self::POSTED, self::PENDING_APPROVAL, self::AMBIGUOUS];

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

    public function shortLink(): BelongsTo
    {
        return $this->belongsTo(ShortLink::class);
    }
}
