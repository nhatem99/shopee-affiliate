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

    'salesoc' => [
        // salesoc.vn chặn thẳng theo IP của server (403 từ nginx, không phải app) — cấu hình
        // 1 outbound proxy ở đây để gọi salesoc.vn qua IP khác thay vì IP thật của VPS.
        // Định dạng: http://user:pass@host:port hoặc http://host:port nếu proxy không cần auth.
        // Để trống (null) thì gọi trực tiếp như bình thường, không qua proxy.
        'proxy' => env('SALESOC_PROXY_URL'),

        // URL của relay gọi hộ salesoc.vn từ một IP khác rồi trả nguyên response về.
        // SalesOcService thử relay trước, hỏng thì tự rơi xuống proxy/direct.
        //
        // Hard-code sẵn app Deno Deploy đang chạy (nguồn: deploy/deno-relay/main.ts) để push code
        // là production dùng được ngay, không phải sửa .env trên server. env() chỉ là đường override
        // khi cần đổi gấp mà chưa kịp deploy.
        //
        // Relay TRƯỚC ĐÓ là Cloudflare Worker và đã bị salesoc.vn chặn 403 ở nginx (log production
        // 2026-09-04): Cloudflare tự chèn header CF-Worker vào mọi subrequest nên một rule nginx
        // là chặn được hết Worker, bất kể IP. Nếu Deno Deploy cũng bị chặn thì đổi sang nền tảng
        // khác (Vercel/Netlify Edge) — code relay là Web standard, port gần như nguyên vẹn.
        // Khai báo được NHIỀU relay, ngăn cách bằng dấu phẩy, theo thứ tự ưu tiên:
        //   'https://a.deno.net,https://b.vercel.app'
        // Mỗi relay chỉ có một IP egress cố định nên một cái là một điểm chết — dựng thêm relay
        // ở nhà cung cấp khác là cách rẻ nhất để salesoc không giết được cả tính năng bằng một
        // lệnh chặn. Tất cả relay dùng chung SALESOC_RELAY_SECRET bên dưới.
        'relay_url' => env('SALESOC_RELAY_URL', 'https://shopee-affiliate.nhatem99.deno.net'),
        'relay_secret' => env('SALESOC_RELAY_SECRET', 'f58d832ed4b4077f7512eb9bdc964c3a9ff46c906c3920fb'),
    ],

];
