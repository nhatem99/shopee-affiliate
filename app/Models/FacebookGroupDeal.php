<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Một bài admin soạn ở /admin/fb-posts — đăng vào nhiều nhóm, mỗi nhóm một FacebookGroupPost.
 * shopee_url null là "bài tự soạn": không link mua, chỉ có chữ admin viết và ảnh tự tải lên.
 */
#[Fillable(['shopee_url', 'canonical_url', 'source', 'product', 'images', 'with_product_image', 'caption', 'fallback_buy_url', 'fallback_ytb_url', 'created_by'])]
class FacebookGroupDeal extends Model
{
    /** Khớp mặc định của cột — model vừa tạo chưa đọc lại từ DB vẫn có đúng giá trị. */
    protected $attributes = [
        'with_product_image' => true,
    ];

    protected function casts(): array
    {
        return [
            'product' => 'array',
            // Tên file ảnh tự tải lên (FacebookPostImages) — null chứ không [] khi không có ảnh:
            // FacebookGroupPostScheduler lọc "bài có ảnh tự tải" bằng whereNotNull.
            'images' => 'array',
            'with_product_image' => 'boolean',
        ];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(FacebookGroupPost::class);
    }

    public function isCustom(): bool
    {
        return $this->shopee_url === null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
