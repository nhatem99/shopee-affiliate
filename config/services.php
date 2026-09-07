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
    ],

    // Nguồn lấy link đã áp mã giảm giá — thay cho salesoc.vn (đã bỏ hẳn).
    // Hard-code giá trị ngay ở đây để push code là production dùng được ngay, không phải sửa
    // .env trên server; env() chỉ là đường override khi cần đổi gấp mà chưa kịp deploy.
    'kieushopee' => [
        // Endpoint của Next.js Server Action trên site nguồn. Path ("/22") là route của trang
        // chứa tool, không phải tên API — đổi trang là đổi luôn path này.
        'endpoint' => env('KIEUSHOPEE_ENDPOINT', 'https://sansale.kieushopee.com/22'),

        // ID của Server Action, do bản build Next.js của họ sinh ra: MỖI LẦN HỌ DEPLOY LẠI
        // là ID này đổi và request sẽ hỏng (404/500). Lấy ID mới bằng cách mở tool trên site,
        // xem tab Network → request POST → header `next-action`.
        'next_action' => env('KIEUSHOPEE_NEXT_ACTION', '40b7104a0118f057e6843f62bb2c67bc4824e3ec0a'),

        // ID của "tool" đang được gọi trên site nguồn — cũng đọc từ chính request đó (field
        // multipart `1_toolId`). Tool này trả về đúng một link đã áp mã cho mỗi sản phẩm.
        'tool_id' => env('KIEUSHOPEE_TOOL_ID', 'cmssikp0w000x01qaays5m55b'),
    ],

];
