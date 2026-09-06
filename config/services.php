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
    | Đã bật sẵn 'enabled' => true kèm token/media_id của kênh 'ig' (TechTrust Mobile, xác nhận
    | bằng `voucher:mint-check` ngày 2026-09-06) để merge xong chạy được ngay, không cần set ENV
    | trên production. Kênh 'fb' vẫn CHƯA có page_access_token/post_id nên tự động không sinh link,
    | không cần xoá khỏi mảng 'channels' — xem ChannelVoucherMinter::run().
    |
    */
    'channel_voucher' => [
        'enabled' => env('CHANNEL_VOUCHER_ENABLED', true),

        // Các kênh sẽ đúc mã, theo thứ tự hiển thị cho khách.
        // 'fb' cần facebook.post_id, 'ig' cần facebook.ig_media_id.
        //
        // 'ig' TẮT có chủ ý (2026-09-06): đã kiểm chứng bằng voucher:mint-check --channel=ig rằng
        // Shopee KHÔNG đúc mã qua đường Instagram — cả bấm link (không khả thi, IG không tự biến
        // URL trong comment thành link bấm được) lẫn điều hướng thẳng với referer đúng (chạy được
        // nhưng URL đích không có credential_token) đều thất bại. Bật lại 'ig' chỉ khi tìm ra cơ
        // chế khác khiến Shopee đúc mã qua kênh này.
        'channels' => ['fb'],

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
        //
        // Giá trị mặc định bên dưới là Page Token thật của Page "TechTrust Mobile" (Page id
        // 1135866952951524, IG business id 17841425760823921) — hardcode có chủ ý để merge xong
        // chạy ngay không cần set ENV, theo yêu cầu. Token loại PAGE, debug_token trả expires_at=0
        // (không tự hết hạn), scope gồm instagram_basic + instagram_manage_comments. Đây LÀ SECRET
        // THẬT nằm trong git — nếu repo này từng public/chia sẻ ra ngoài, coi token đã bị lộ và
        // phải thu hồi (Graph API Explorer -> App dienthoaigiare -> Đặt lại quyền/Page Token).
        'ig_media_id' => env('INSTAGRAM_MEDIA_ID', '18624429151052482'),
        'ig_access_token' => env('INSTAGRAM_ACCESS_TOKEN', 'EAAN8uHrrzHsBSWS1yiZCq4EWtkCVrnN3nNSXKIrd6f599ko9QF29FE0gACiuNbQysiNjxUa8l855jcXoQuWRisuhAwhWBfDRXXZAExMA8Q5pZCdF7ieE6Nx6AGQ1TFb3Rhqf66L554dcUeHA7Gy41ZCHgZCiH50ZBu4AE6jJ7r7HSgMCVzxVbY0ZArWnpJAF74JMHUxZCRWZBqz4c38n8xIVV'),
    ],

    // Node service chạy Playwright, mở bài đăng Facebook/Instagram bằng Chromium thật rồi bấm vào
    // link để lấy URL đích. Nguồn: deploy/browser-resolver/ — chạy thường trực bằng pm2/systemd
    // trên chính VPS, chỉ nghe 127.0.0.1 nên không cần mở cổng ra ngoài.
    'browser_resolver' => [
        'url' => env('BROWSER_RESOLVER_URL', 'http://127.0.0.1:8787'),
        'secret' => env('BROWSER_RESOLVER_SECRET', 'change-me-cung-voi-deploy-browser-resolver'),
    ],

];
