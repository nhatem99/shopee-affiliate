<?php

namespace App\Services;

use App\Models\ApiConfig;

/**
 * Đường đi của khách sau khi bấm nút mua: thẳng sang Shopee, hay vòng qua Facebook (bình luận
 * hoặc caption reel), và có tự chuyển hướng luôn không.
 *
 * Tách khỏi ShopeeVoucherController vì giờ có HAI nơi cần biết: trang chủ (để viết đúng câu
 * hướng dẫn cho khách) và kho mẫu bài đăng (để admin không đăng ra một bài mô tả sai luồng thật
 * — bảo người ta "bấm nút Mua ngay" trong khi nút đang tên "Lấy mã qua Facebook", hoặc dạy bấm
 * nút trong khi chế độ tự chuyển hướng đã bỏ hẳn bước đó).
 *
 * Chép logic này ra hai chỗ là kiểu hỏng âm thầm điển hình: đổi cấu hình một bên, bên kia vẫn
 * nói câu cũ, và không có gì báo lỗi cả.
 */
class FacebookRedirectFlagService
{
    /**
     * @return array{viaFacebookComment: bool, autoRedirect: bool, facebookMode: string}
     */
    public function flags(): array
    {
        $config = ApiConfig::where('platform', 'facebook')->where('is_active', true)->first();

        $viaComment = $config
            && ($config->meta['comment_redirect_enabled'] ?? false)
            && $config->app_id
            && $config->app_secret
            // Một trong hai chế độ có đủ cấu hình là được: đổi caption reel (khách bấm link
            // trong phần mô tả reel) hoặc đăng comment (khách bấm link trong bình luận).
            && ($config->facebookReelCaptionEnabled() || $config->facebookTargetPostIds());

        if (! $viaComment) {
            return ['viaFacebookComment' => false, 'autoRedirect' => false, 'facebookMode' => 'comment'];
        }

        return [
            'viaFacebookComment' => true,
            'facebookMode' => $config->facebookReelCaptionEnabled() ? 'reel' : 'comment',
            'autoRedirect' => (bool) ($config->meta['auto_redirect_enabled'] ?? ! empty($config->meta['auto_source'])),
        ];
    }
}
