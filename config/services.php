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

    /**
     * Kho mã giảm giá toàn sàn ở /ma-giam-gia — xem VoucherCatalogSyncService.
     */
    'voucher_catalog' => [
        // Nguồn cấp danh sách mã. Đây là API của một website khác, không phải của mình:
        // họ đổi đường dẫn hoặc chặn là trang mã đứng yên ở lần đồng bộ cuối (không sập,
        // chỉ cũ dần) — lệnh `vouchers:sync` sẽ kêu trong log và ở /admin/scheduler.
        'source_url' => env('VOUCHER_CATALOG_SOURCE', 'https://bloghoantien.com/api/vouchers'),

        // Số mã xin mỗi lượt gọi và trần số trang, để một nguồn trả sai `hasMore` không
        // kéo lệnh chạy vô tận.
        'page_size' => (int) env('VOUCHER_CATALOG_PAGE_SIZE', 50),
        'max_pages' => (int) env('VOUCHER_CATALOG_MAX_PAGES', 20),

        // Các sàn đi lấy. Nguồn cấp cả Lazada/TikTok Shop/Tiki/ShopeeFood nhưng CHƯA bật sàn
        // nào ngoài Shopee, vì hai lý do khác nhau (đo thật 23-09-2026):
        //
        //  • Lazada/TikTok Shop/Tiki: link không trỏ shopee.vn nên không đổi được sang
        //    affiliate của mình — mình chỉ có affiliate ID Shopee. Bật lên là mã bị bỏ hết
        //    ở bước đồng bộ, không mã nào ra tới trang.
        //
        //  • ShopeeFood: nguồn trả 50 mã nhưng CẢ 50 dùng CHUNG đúng một link rút gọn
        //    (https://shope.ee/8pkfHVMmn3) trỏ trang chủ ShopeeFood, không phải link riêng
        //    theo mã. Tức là không có chuyện bấm "Áp dụng" rồi mã tự lưu vào tài khoản —
        //    đúng thứ cả trang này hứa. Bày lên thì mỗi nút đưa khách tới cùng một chỗ.
        //
        // Muốn thêm sàn = phải có affiliate ID của sàn đó VÀ nguồn phải trả link theo từng mã.
        'platforms' => ['shopee'],

        // Nhãn Sub_id cho traffic đến từ trang mã, để tách khỏi fb/IG/YT trong báo cáo
        // affiliate Shopee. Cùng quy tắc với `utm_content` ở trên: Shopee đọc được và
        // khách thấy trên thanh địa chỉ, nên KHÔNG đặt tên miền/thương hiệu vào đây.
        // Để rỗng thì tham số bị xoá hẳn khỏi URL.
        'utm_content' => env('VOUCHER_CATALOG_UTM_CONTENT', 'MGG'),
    ],

    /**
     * Kho sản phẩm Flash Sale ở /flashsale — xem FlashSaleSyncService.
     *
     * KHÁC voucher_catalog ở một điểm cốt lõi: link gửi khách KHÔNG lấy từ nguồn (nguồn
     * chỉ cấp link cho ~9% sản phẩm, và link đó là short-link JS-redirect không đổi được
     * affiliate bằng cách swap query param thường). Ở đây tự dựng link sản phẩm từ
     * shopid+itemid — trang sản phẩm Shopee thường không cần chữ ký như link voucher,
     * chỉ cần mmp_pid để tính hoa hồng — nên dùng được cho 100% sản phẩm, không phụ
     * thuộc nguồn có cấp link hay không.
     */
    'flash_sale' => [
        // Nguồn trả TOÀN BỘ danh sách trong một lần gọi, không phân trang.
        'source_url' => env('FLASH_SALE_SOURCE', 'https://api.thichsansale.click/apidata.php'),

        // Suất amount <= giá trị này bị loại ở bước đồng bộ — coi là đã hết suất, bày ra
        // chỉ để khách bấm vào một sale không còn gì. Mặc định 0 = chỉ loại đúng "amount
        // bằng 0" (đo thật 23-09-2026: 20/2659 dòng), không đoán thêm ngưỡng nào khác vì
        // chưa có bằng chứng amount thấp mà vẫn còn mua được hay không.
        'min_amount' => (int) env('FLASH_SALE_MIN_AMOUNT', 1),

        // Nhãn Sub_id riêng cho traffic từ trang Flash Sale, tách khỏi 'MGG' của trang mã
        // và 'fb' mặc định — cùng quy tắc: Shopee đọc được, khách thấy trên thanh địa chỉ,
        // không đặt tên miền/thương hiệu vào đây.
        'utm_content' => env('FLASH_SALE_UTM_CONTENT', 'FS'),
    ],

    /**
     * Mạng affiliate ACCESSTRADE — dùng cho TikTok Shop (xem AccessTradeService).
     *
     * Khác mọi khối khác trong file này ở một điểm: API KEY CỐ Ý KHÔNG ĐẶT SẴN GIÁ TRỊ Ở ĐÂY.
     * Key này mở được toàn bộ tài khoản affiliate (đọc đơn, tạo link, xem doanh thu) và xoay
     * key là thao tác một nút trên pub2.accesstrade.vn, nên chỗ đúng của nó là bản ghi
     * `accesstrade` ở /admin/api-config — dán vào là chạy ngay, không phải sửa code rồi deploy.
     * env() chỉ là đường dự phòng.
     *
     * campaign_id thì không phải bí mật, để sẵn đây cho khỏi phải nhập lại sau mỗi lần cài mới.
     */
    'accesstrade' => [
        'endpoint' => env('ACCESSTRADE_ENDPOINT', 'https://api.accesstrade.vn/v1'),

        'api_key' => env('ACCESSTRADE_API_KEY', ''),

        // Chiến dịch TIKTOK SHOP CPS — đã được duyệt cho tài khoản này (kiểm chứng
        // 25-09-2026 qua GET /v1/campaigns: approval = "successful", status = 1).
        'campaign_id' => env('ACCESSTRADE_CAMPAIGN_ID', '6648523843406889655'),

        // Nhãn nguồn traffic gửi kèm link. Cùng nguyên tắc với utm_content bên Shopee: giá trị
        // này ACCESSTRADE và sàn đều đọc được, nên đừng đặt tên miền/thương hiệu vào đây.
        'utm_source' => env('ACCESSTRADE_UTM_SOURCE', 'web'),

        // Link dùng cho nút "Kiểm tra kết nối" ở /admin/api-config. Sản phẩm nào cũng được —
        // phép thử này để xem key + campaign + trạng thái duyệt có chạy không, không phải để
        // kiểm tra sản phẩm còn hàng.
        'test_url' => env('ACCESSTRADE_TEST_URL', 'https://shop.tiktok.com/view/product/1733724538346374522?region=VN&local=en'),
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
        'next_action' => env('KIEUSHOPEE_NEXT_ACTION', '404c52f3900e67aef658bc66241a1a6be83477b3c1'),

        // ID của "tool" đang được gọi trên site nguồn — cũng đọc từ chính request đó (field
        // multipart `1_toolId`). Tool này trả về đúng một link đã áp mã cho mỗi sản phẩm.
        'tool_id' => env('KIEUSHOPEE_TOOL_ID', 'cmssikp0w000x01qaays5m55b'),

        // Field multipart "0" — cách Next.js đóng gói danh sách tham số cho Server Action.
        // "$K1" là tham chiếu tới cụm field có tiền tố "1_". Hiếm khi đổi, nhưng nếu họ thêm
        // tham số thì chuỗi này đổi theo nên vẫn để sửa được.
        'action_payload' => env('KIEUSHOPEE_ACTION_PAYLOAD', '["$K1"]'),
    ],

];
