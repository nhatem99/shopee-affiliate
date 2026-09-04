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
        // CẢNH BÁO: default hard-code dưới đây là Cloudflare Worker cũ và ĐÃ BỊ salesoc.vn chặn
        // (403 từ nginx của họ, xác nhận trong log production 2026-09-04) — Cloudflare tự chèn
        // header CF-Worker vào subrequest nên mọi Worker đều bị nhận diện. Deploy relay mới theo
        // deploy/deno-relay/main.ts rồi set SALESOC_RELAY_URL/SECRET trong .env để thay thế.
        'relay_url' => env('SALESOC_RELAY_URL', 'https://salesoc-relay.hoangvuminhnhat1.workers.dev'),
        'relay_secret' => env('SALESOC_RELAY_SECRET', '81651b0350a2c63cd43c3f277d8a0c7a125c001980d8b638'),
    ],

];
