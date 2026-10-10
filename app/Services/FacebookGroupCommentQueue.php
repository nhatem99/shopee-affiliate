<?php

namespace App\Services;

use App\Models\FacebookGroupPost;
use App\Models\FacebookProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bình luận link mua vào bài đã đăng. Bài có link dễ bị admin nhóm/Facebook coi là spam, nên
 * bài "link ở bình luận" (FacebookGroupDeal::link_in_comment) chỉ ghi "link ở bình luận"; đăng
 * xong, server giao bot việc "comment": chuyển sang đúng page đã đăng, mở bài, bình luận link.
 *
 * Chỉ bình luận khi bài đã hiện trên nhóm:
 *  • bot báo "đã đăng" mà chưa kiểm tra duyệt bài: thử ngay một lần — bài đăng thẳng thì khách
 *    thấy link luôn. Bot có thể đọc sót câu "chờ duyệt" của Facebook, nên hỏng thì thôi, không
 *    thử dồn;
 *  • FacebookGroupReviewChecker thấy bài ở tab "Đã đăng" (kể cả bài chờ duyệt nay đã được duyệt):
 *    thử tiếp tới MAX_ATTEMPTS lượt.
 * Bình luận chen trước bài mới và không chờ khoảng nghỉ giữa hai bài: đọc bài thấy "link ở bình
 * luận" mà chưa có link thì mất khách.
 */
class FacebookGroupCommentQueue
{
    /** Bot từ bản này mới biết làm việc "comment" — bài "link ở bình luận" chỉ giao cho bot này. */
    public const MIN_VERSION = '1.4.0';

    /** Kết quả bot được phép báo về. */
    public const RESULTS = ['commented', 'ambiguous', 'not_found', 'failed', 'blocked', 'checkpoint'];

    /** Bài về hàng chờ (đăng lại, chuyển page khác) — bình luận dựng lại lúc bot nhận bài. */
    public const RESET = [
        'comment' => null,
        'comment_status' => null,
        'comment_attempts' => 0,
        'comment_claimed_at' => null,
        'commented_at' => null,
        'comment_error' => null,
    ];

    private const MAX_ATTEMPTS = 3;

    /** Giữa hai lượt thử một bài — cũng là hạn bot phải báo kết quả, quá thì coi như hỏng. */
    private const RETRY_MINUTES = 20;

    private const WATCH_DAYS = 3;

    public static function runnerSupports(?string $version): bool
    {
        return FacebookGroupPostScheduler::runnerAtLeast($version, self::MIN_VERSION);
    }

    /**
     * Việc bình luận kế tiếp, hoặc null. Giao việc là tính một lượt thử: bot chết giữa chừng thì
     * sau RETRY_MINUTES bài được giao lại (bot thấy bình luận đã có thì không bình luận nữa).
     */
    public function nextJob(?string $runnerVersion): ?array
    {
        if (! self::runnerSupports($runnerVersion)) {
            return null;
        }

        $this->giveUpSilentClaims();

        $post = $this->due()->orderBy('finished_at')->first();
        if (! $post) {
            return null;
        }

        // Chỉ nhận nếu chưa ai nhận lại trong lúc này — hai lượt hỏi cùng lúc không bình luận trùng.
        $claimed = FacebookGroupPost::whereKey($post->id)
            ->where('comment_status', FacebookGroupPost::COMMENT_PENDING)
            ->where('comment_attempts', $post->comment_attempts)
            ->update([
                'comment_claimed_at' => now(),
                'comment_attempts' => DB::raw('comment_attempts + 1'),
            ]);
        if (! $claimed) {
            return null;
        }

        $post->loadMissing(['group', 'profile']);
        $profile = $post->profile ?? FacebookProfile::where('is_primary', true)->first();

        return [
            'type' => 'comment',
            'post_id' => $post->id,
            'group_url' => $post->group->url,
            'group_name' => $post->group->name,
            // Bot bắt được link bài lúc đăng, hoặc lượt kiểm tra duyệt bài thấy. Không có thì bot
            // tự tìm bài theo đoạn đầu nội dung ở những trang trong "search".
            'post_url' => $post->post_url,
            'caption' => $post->caption,
            'comment' => $post->comment,
            // Trên bài đã có chữ này (link mua — bài không có link nào) là đã bình luận rồi.
            'marker' => self::marker($post),
            'search' => [rtrim($post->group->url, '/').'/'.FacebookGroupReviewChecker::TABS[FacebookGroupPost::REVIEW_PUBLISHED].'/'],
            // "Bình luận dưới tên" page đang mở — phải là page đã đăng bài.
            'profile' => $profile?->forRunner(),
        ];
    }

    /**
     * Bot báo kết quả một lượt bình luận. false = bài không chờ bình luận (đã chốt, chưa giao) —
     * controller trả 409 để bot khỏi gửi lại. Facebook chặn/bắt xác minh thì bot báo thêm ở lượt
     * hỏi việc kế tiếp (state), server cho page nghỉ/dừng bot như lúc đăng bài.
     */
    public function report(FacebookGroupPost $post, string $status, ?string $error, ?string $postUrl): bool
    {
        if ($post->comment_status !== FacebookGroupPost::COMMENT_PENDING || $post->comment_claimed_at === null) {
            return false;
        }

        $changes = [];
        if ($url = FacebookGroupReviewChecker::postUrl($postUrl)) {
            $changes['post_url'] = $url;
        }
        $error = $error !== null ? Str::limit($error, 990) : null;

        $changes += match ($status) {
            'commented' => [
                'comment_status' => FacebookGroupPost::COMMENT_DONE,
                'commented_at' => now(),
                'comment_error' => null,
            ],
            'ambiguous' => [
                'comment_status' => FacebookGroupPost::COMMENT_UNKNOWN,
                'comment_error' => $error ?? 'Đã gửi bình luận nhưng không thấy hiện ra — mở bài xem.',
            ],
            default => [
                'comment_status' => $post->comment_attempts >= self::MAX_ATTEMPTS ? FacebookGroupPost::COMMENT_FAILED : FacebookGroupPost::COMMENT_PENDING,
                'comment_error' => $error ?? 'Không bình luận được',
            ],
        };

        $post->forceFill($changes)->save();

        return true;
    }

    /** Chuỗi chỉ bình luận của mình có: link mua bỏ https:// (Facebook có thể hiện link không kèm). */
    public static function marker(FacebookGroupPost $post): ?string
    {
        return $post->buy_url ? preg_replace('#^https?://#i', '', $post->buy_url) : null;
    }

    /** Bài tới lượt bình luận: đã hiện trên nhóm, page đăng bài không bị chặn. */
    private function due(): Builder
    {
        return FacebookGroupPost::where('comment_status', FacebookGroupPost::COMMENT_PENDING)
            ->whereNotNull('comment')
            ->where('finished_at', '>=', now()->subDays(self::WATCH_DAYS))
            ->where(fn (Builder $query) => $query->whereNull('comment_claimed_at')
                ->orWhere('comment_claimed_at', '<=', now()->subMinutes(self::RETRY_MINUTES)))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $q) => $q->where('status', FacebookGroupPost::POSTED)->whereNull('review_state')->where('comment_attempts', 0))
                ->orWhere(fn (Builder $q) => $q->where('review_state', FacebookGroupPost::REVIEW_PUBLISHED)->where('comment_attempts', '<', self::MAX_ATTEMPTS)))
            ->where(fn (Builder $query) => $query->whereNull('facebook_profile_id')
                ->orWhereHas('profile', fn (Builder $q) => $q->whereNull('blocked_at')));
    }

    /** Lượt thử cuối mà bot không báo kết quả — thôi, không giao nữa. */
    private function giveUpSilentClaims(): void
    {
        FacebookGroupPost::where('comment_status', FacebookGroupPost::COMMENT_PENDING)
            ->where('comment_attempts', '>=', self::MAX_ATTEMPTS)
            ->where('comment_claimed_at', '<=', now()->subMinutes(self::RETRY_MINUTES))
            ->update([
                'comment_status' => FacebookGroupPost::COMMENT_FAILED,
                'comment_error' => 'Bot không báo kết quả bình luận — mở bài xem đã có link chưa.',
            ]);
    }
}
