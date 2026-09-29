<?php

namespace App\Services;

/**
 * Nguồn lấy mã laymavoucher.afp.ad — dự phòng cho kieushopee khi nguồn đó lỗi.
 *
 * Cùng nền tảng tool affiliate afp.ad với sansale.kieushopee.com: request đo trên trình duyệt
 * ngày 29-09-2026 giống hệt từng chi tiết (Next.js Server Action, multipart `1_url` +
 * `1_toolId` + field "0" = ["$K1"], header `next-action`, Accept `text/x-component`), chỉ khác
 * endpoint, next_action và tool_id. Nên toàn bộ phần gọi và đọc phản hồi dùng lại của
 * KieuShopeeService; lớp này chỉ đổi SOURCE — thứ quyết định đọc tham số ở bản ghi api_configs
 * nào, khoá config/services.php nào và nhãn log nào.
 *
 * Tách thành nguồn riêng (không phải sửa endpoint của kieushopee sang đây) để admin chuyển qua
 * lại bằng một công tắc ở /admin/api-config: kieushopee sống lại là bật về ngay, không phải
 * nhớ và dán lại ba tham số cũ.
 */
class LaymaVoucherService extends KieuShopeeService
{
    public const SOURCE = 'laymavoucher';
}
