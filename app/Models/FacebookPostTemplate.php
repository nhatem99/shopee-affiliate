<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Mẫu "bài tự soạn" ở /admin/fb-posts — chọn mẫu là điền sẵn nội dung và ảnh vào ô soạn bài.
 * Mẫu chỉ là chỗ để soạn nhanh: xếp bài vẫn tạo FacebookGroupDeal riêng, sửa mẫu không đổi bài cũ.
 */
#[Fillable(['name', 'caption', 'images', 'created_by'])]
class FacebookPostTemplate extends Model
{
    protected function casts(): array
    {
        return [
            // Tên file trong FacebookPostImages::DIR, theo thứ tự đăng — null khi không có ảnh.
            'images' => 'array',
        ];
    }
}
