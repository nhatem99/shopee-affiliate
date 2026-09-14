<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledTaskRun extends Model
{
    /** Giữ tối đa chừng này ký tự output — đủ để thấy bảng kết quả hoặc dòng lỗi, không phình DB. */
    public const OUTPUT_LIMIT = 5000;

    protected $fillable = [
        'command',
        'started_at',
        'finished_at',
        'exit_code',
        'output',
        'triggered_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'exit_code' => 'integer',
    ];

    public function isRunning(): bool
    {
        return $this->finished_at === null;
    }

    public function succeeded(): bool
    {
        return $this->finished_at !== null && $this->exit_code === 0;
    }

    /** Cắt output về phần CUỐI: dòng lỗi và bảng tổng kết thường nằm ở cuối, không phải đầu. */
    public static function trimOutput(?string $output): ?string
    {
        if ($output === null) {
            return null;
        }

        $output = trim($output);

        if ($output === '') {
            return null;
        }

        return mb_strlen($output) > self::OUTPUT_LIMIT
            ? '…'.mb_substr($output, -self::OUTPUT_LIMIT)
            : $output;
    }
}
