<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một lượt điểm danh của một khách trong một ngày. Xem DailyCheckInService.
 */
#[Fillable(['user_id', 'checked_on', 'prize_amount', 'bonus_amount', 'amount', 'streak'])]
class CheckIn extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        // `checked_on` CỐ Ý KHÔNG có cast, đọc ra là chuỗi 'Y-m-d' đúng như trong DB.
        //
        // Cast 'date' hay 'immutable_date' đều làm Eloquent GHI XUỐNG theo định dạng
        // 'Y-m-d H:i:s' (Model::fromDateTime), tức cột nhận '2026-09-23 00:00:00'. MySQL âm thầm
        // cắt phần giờ vì cột kiểu DATE, còn SQLite (DB của test) giữ nguyên cả chuỗi — thế là
        // where('checked_on', '2026-09-23') khớp trên production và trượt trong test, kiểu lệch
        // tồi nhất: tính năng vẫn xanh trong test mà kho quà đếm sai ngoài đời (hoặc ngược lại).
        //
        // Cần Carbon thì parse tại chỗ: chuỗi 'Y-m-d' parse ra nửa đêm theo giờ Việt Nam,
        // đúng mốc mà DailyCheckInService::today() dùng.
        return [
            'prize_amount' => 'decimal:2',
            'bonus_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'streak' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
