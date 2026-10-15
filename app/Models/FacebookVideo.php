<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Một Reel làm từ video sản phẩm Shopee — xem FacebookVideoQueue (bot lấy link video),
 * FacebookVideoEditor (cắt + dựng khung 9:16) và PublishFacebookVideo (đăng lên page).
 *
 * File nằm ở disk 'local' (storage/app/private/fb-videos/{id}/): source.mp4 là video gốc tải từ
 * Shopee hoặc admin tải lên, output.mp4 là bản đã dựng. Giữ bản gốc để admin đổi chữ/cắt rồi
 * dựng lại mà không phải nhờ bot lấy lại.
 */
#[Fillable(['shopee_url', 'status', 'product', 'short_link_id', 'buy_url', 'caption', 'source_url', 'source_duration', 'trim_start', 'trim_end', 'overlay_top', 'overlay_bottom', 'output_duration', 'has_source', 'has_output', 'fb_video_id', 'permalink', 'error', 'claimed_at', 'posted_at'])]
class FacebookVideo extends Model
{
    /** Chờ bot điện thoại mở Shopee lấy link video. */
    public const WAITING_BOT = 'waiting_bot';

    /** Bot đã nhận việc, chưa báo kết quả. */
    public const FETCHING = 'fetching';

    /** Server đang tải video gốc / dựng video. */
    public const PROCESSING = 'processing';

    /** Đã dựng xong, chờ admin xem rồi bấm Đăng. */
    public const READY = 'ready';

    public const PUBLISHING = 'publishing';

    public const POSTED = 'posted';

    /** Bot đọc được trang sản phẩm và sản phẩm không có video. */
    public const NO_VIDEO = 'no_video';

    /** Không lấy được / không dựng được — error ghi lý do; admin tải video lên tay hoặc thử lại. */
    public const FAILED = 'failed';

    /** Đang có việc chạy — trang admin tự tải lại, không cho bấm gì thêm. */
    public const BUSY = [self::WAITING_BOT, self::FETCHING, self::PROCESSING, self::PUBLISHING];

    public const DIR = 'fb-videos';

    protected function casts(): array
    {
        return [
            'product' => 'array',
            'source_duration' => 'float',
            'trim_start' => 'float',
            'trim_end' => 'float',
            'output_duration' => 'float',
            'has_source' => 'boolean',
            'has_output' => 'boolean',
            'claimed_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function isBusy(): bool
    {
        return in_array($this->status, self::BUSY, true);
    }

    public function sourcePath(): string
    {
        return Storage::disk('local')->path($this->relativePath('source.mp4'));
    }

    public function outputPath(): string
    {
        return Storage::disk('local')->path($this->relativePath('output.mp4'));
    }

    public function relativePath(string $file = ''): string
    {
        return self::DIR.'/'.$this->id.($file !== '' ? '/'.$file : '');
    }

    /** Xoá file khi xoá bản ghi — video vài chục MB, để lại thì đầy ổ VPS. */
    protected static function booted(): void
    {
        static::deleted(fn (FacebookVideo $video) => Storage::disk('local')->deleteDirectory($video->relativePath()));
    }
}
