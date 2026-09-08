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

        // Nhãn utm_content gắn vào URL cuối gửi tới Shopee, để nhận ra traffic của mình trong
        // báo cáo affiliate. Giá trị này Shopee ĐỌC ĐƯỢC và khách cũng thấy trên thanh địa chỉ,
        // nên TUYỆT ĐỐI không đặt tên miền/tên thương hiệu của website vào đây — trước đây chỗ
        // này để 'tietkiemvi', tức tự khai website nguồn cho Shopee.
        //
        // Đổi thoải mái, chỉ cần không suy ra được tên miền. Để chuỗi rỗng thì tham số bị xoá
        // hẳn khỏi URL (kín nhất, nhưng mất luôn khả năng tự nhận diện traffic của mình).
        'utm_content' => env('SHOPEE_UTM_CONTENT', 'fb'),

        // Nhãn riêng cho mã lấy từ kênh Instagram, thay cho utm_content mặc định ở trên. Có
        // nó thì trong báo cáo affiliate Shopee mới tách được đơn/click đến từ IG khỏi FB —
        // trước đây mọi mã đều gắn cùng một nhãn nên cột Sub_id lúc nào cũng là 'fb'.
        'utm_content_ig' => env('SHOPEE_UTM_CONTENT_IG', 'IG'),

        // Các nhãn kênh (viết thường) của nguồn cấp mã được coi là "mã IG". kieushopee nhét
        // tên nhóm/tool của họ vào utm_content của link trả về, dạng 5 khe nối bằng dấu "-";
        // khe nào bằng đúng một trong các giá trị dưới đây thì link đó là mã IG.
        //
        // Thêm giá trị vào đây khi họ đổi cách đặt tên nhóm — xem
        // AffiliateLinkRewriterService::resolveSubId(). Danh sách rỗng = tắt hẳn việc tách IG.
        'ig_markers' => ['ig', 'insta', 'instagram'],

        // Nhãn cho mã lấy từ nguồn YouTube (ganma.vn).
        'utm_content_yt' => env('SHOPEE_UTM_CONTENT_YT', 'YT'),

        // Marker nhận ra "mã YouTube". Link an_redir của ganma mang sub_id dạng "YT3-<token>";
        // đi theo redirect thì Shopee đổ nguyên văn giá trị đó sang utm_content, nên khe đầu
        // ('yt3') là chỗ nhận ra kênh. Thêm giá trị vào đây nếu họ đổi cách đặt tên nhóm —
        // xem AffiliateLinkRewriterService::resolveSubId(). Rỗng = tắt hẳn việc tách YT.
        'yt_markers' => ['yt', 'yt1', 'yt2', 'yt3', 'ytb', 'youtube'],
    ],

    // Nguồn lấy mã YouTube — chạy song song kieushopee, admin chọn nguồn nào đang dùng ở
    // /admin/api-config. Khác kieushopee ở chỗ đây là API BẤT ĐỒNG BỘ: tạo job rồi phải hỏi
    // lại nhiều lần cho tới khi xong.
    'ganma' => [
        'endpoint' => env('GANMA_ENDPOINT', 'https://ganma.vn'),

        // Đo thật 08-09-2026: một job mất ~17-20 giây (queue_position đếm lùi 3 → 0).
        //
        // Trần 45s là do HẠ TẦNG chứ không phải do nguồn, đo trên chính VPS production:
        //   • nginx không đặt fastcgi_read_timeout → mặc định 60s. Vượt là khách ăn 504.
        //   • PHP-FPM max_execution_time = 30 (xem GanmaService::waitForJob xử lý cái này).
        // 45s vừa đủ dư địa cho lúc hàng đợi dài gấp đôi bình thường, vừa còn ~15s biên an toàn
        // trước ngưỡng 60s của nginx. Nâng cao hơn thì phải nới nginx trước, không thì vô nghĩa.
        'poll_interval_seconds' => 3,
        'max_wait_seconds' => 45,

        // Timeout cho từng request lẻ (tạo job / hỏi trạng thái), không phải cho cả job.
        'request_timeout_seconds' => 20,
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

        // Field multipart "0" — cách Next.js đóng gói danh sách tham số cho Server Action.
        // "$K1" là tham chiếu tới cụm field có tiền tố "1_". Hiếm khi đổi, nhưng nếu họ thêm
        // tham số thì chuỗi này đổi theo nên vẫn để sửa được.
        'action_payload' => env('KIEUSHOPEE_ACTION_PAYLOAD', '["$K1"]'),
    ],

];
