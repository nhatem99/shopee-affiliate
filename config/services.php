<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'zalo' => [
        'oa_token' => env('ZALO_OA_TOKEN'),
        // app_id/secret_key dùng để xác thực chữ ký webhook (X-ZEvent-Signature) —
        // bắt buộc phải cấu hình trước khi bật group reply, nếu không webhook sẽ bị từ chối.
        'app_id' => env('ZALO_APP_ID'),
        'secret_key' => env('ZALO_OA_SECRET_KEY'),
    ],

    'shopee_affiliate' => [
        // mmp_pid dùng để gắn hoa hồng đơn hàng về tài khoản affiliate Shopee của mình.
        'mmp_pid' => env('SHOPEE_MMP_PID', 'an_17332410386'),

        // ID tài khoản KOL dùng để ĐÚC link có mã (xem App\Services\ChannelVoucher).
        // Đây KHÔNG phải tài khoản nhận hoa hồng: link đúc ra mang mmp_pid của KOL này, sau đó
        // AffiliateLinkRewriterService đổi sang mmp_pid ở trên trước khi trả cho khách (KOL -> KOC).
        // Vì sao phải là tài khoản khác: chữ ký credential_token của Shopee buộc chặt vào tài khoản
        // đã liên kết kênh Facebook/Instagram — 30 ký tự đầu của token giống nhau giữa link FB và
        // link IG của cùng một KOL, chỉ 12 ký tự cuối đổi theo từng link (đo trên 2 link thật).
        'kol_pid' => env('SHOPEE_KOL_PID', 'an_17356640097'),

        // Nhãn sub_id gắn vào utm_content của link đúc ra, để đối soát doanh thu theo nguồn.
        // Trường này do người tạo link đặt, Shopee không ký — đổi thoải mái.
        'sub_id' => env('SHOPEE_SUB_ID', 'tietkiemvi'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tự đúc link có mã — NGUỒN MÃ DUY NHẤT
    |--------------------------------------------------------------------------
    |
    | Cơ chế: dựng link affiliate trơn mang mmp_pid của KOL -> đăng lên Facebook/Instagram bằng
    | Graph API -> mở chính bài đăng đó bằng trình duyệt thật (Playwright) và bấm vào link ->
    | Shopee đúc ra link mang credential_token/encrypted_payload (tức là CÓ mã của kênh đó) ->
    | AffiliateLinkRewriterService đổi mmp_pid sang của mình rồi mới trả cho khách.
    |
    | Vì sao phải mở bằng trình duyệt chứ không gọi API: credential_token + encrypted_payload do
    | Shopee ký, không thể tự sinh từ ID KOL. Chỉ có đường đọc ngược về từ nền tảng.
    |
    | KHÔNG CÒN ĐƯỜNG DỰ PHÒNG. Trước đây hỏng thì lùi về salesoc.vn; salesoc đã bị gỡ bỏ hoàn
    | toàn (2026-09-05) nên đây hỏng là khách không có mã. Đổi lại: hoa hồng về hết tài khoản
    | của mình thay vì của salesoc, và không còn phụ thuộc một bên có thể chặn mình bất cứ lúc nào.
    |
    | MẶC ĐỊNH TẮT ('enabled' => false): chưa điền token/post_id mà bật lên thì mọi lượt quét đều
    | hỏng và khách không nhận được mã nào. Bật sau khi `php artisan voucher:mint-check <link>`
    | chạy xanh.
    |
    */
    'channel_voucher' => [
        'enabled' => env('CHANNEL_VOUCHER_ENABLED', false),

        // Các kênh sẽ đúc mã, theo thứ tự hiển thị cho khách.
        // 'fb' cần facebook.post_id, 'ig' cần facebook.ig_media_id.
        'channels' => ['fb', 'ig'],

        // Xoá comment ngay sau khi đọc xong link. Bật mặc định: mỗi lượt khách quét là một comment
        // mới lên cùng một bài, không dọn thì bài tích luỹ hàng nghìn comment link Shopee và Page
        // rất dễ bị Facebook đánh dấu spam. Link đã đúc vẫn sống sau khi comment bị xoá.
        'delete_comment_after' => env('CHANNEL_VOUCHER_DELETE_COMMENT', true),

        // Chờ tối đa bao lâu cho toàn bộ chuỗi (comment -> mở trình duyệt -> đúc link) của MỘT kênh.
        'timeout' => env('CHANNEL_VOUCHER_TIMEOUT', 60),

        // Link đúc ra có cts (thời điểm đúc) nên coi như hàng tươi, không giữ lâu.
        'cache_minutes' => env('CHANNEL_VOUCHER_CACHE_MINUTES', 15),
    ],

    'facebook' => [
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v21.0'),

        // Page Access Token dài hạn. Quyền cần: pages_manage_engagement (tạo/xoá comment) +
        // pages_read_engagement (đọc lại comment). Kiểm tra hạn bằng GET /debug_token.
        'page_access_token' => env('FACEBOOK_PAGE_ACCESS_TOKEN'),

        // ID bài đăng cố định để comment link vào. Dạng {page_id}_{post_id}.
        'post_id' => env('FACEBOOK_POST_ID'),

        // Media Instagram để comment (kênh 'ig'). Cần tài khoản IG Professional liên kết Page và
        // quyền instagram_manage_comments.
        'ig_media_id' => env('INSTAGRAM_MEDIA_ID'),
        'ig_access_token' => env('INSTAGRAM_ACCESS_TOKEN'),
    ],

    // Node service chạy Playwright, mở bài đăng Facebook/Instagram bằng Chromium thật rồi bấm vào
    // link để lấy URL đích. Nguồn: deploy/browser-resolver/ — chạy thường trực bằng pm2/systemd
    // trên chính VPS, chỉ nghe 127.0.0.1 nên không cần mở cổng ra ngoài.
    'browser_resolver' => [
        'url' => env('BROWSER_RESOLVER_URL', 'http://127.0.0.1:8787'),
        'secret' => env('BROWSER_RESOLVER_SECRET', 'change-me-cung-voi-deploy-browser-resolver'),
    ],

];
