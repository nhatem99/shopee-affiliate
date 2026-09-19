# Meta đã chặn sửa caption reel — chế độ "vòng qua Facebook" đang chết

**Ngày phát hiện:** 19-09-2026, khoảng 17:30 (giờ VN)
**Mức độ:** đang ảnh hưởng khách thật trên production
**Trạng thái:** đã xác định nguyên nhân, CHƯA sửa

---

## 1. Triệu chứng nhìn thấy

Trên trang chủ, nút mua hiện chữ **"Mua ngay (đã áp mã)"** và bấm vào là sang thẳng Shopee —
không còn mở Facebook/reel như thiết kế. Lẽ ra nút phải là "Lấy mã qua Facebook" → "Mở Facebook
ngay" → khách bấm link trong caption reel → mới về Shopee.

Hệ quả: **mọi khách mua từ ~17:25 ngày 19-09 đều bỏ qua bước đi qua Facebook**, trong khi chính
trang mình đang nói với khách là "phải đi qua Facebook thì mã mới có hiệu lực" (xem FAQ ở
`resources/js/Pages/Home.vue`).

## 2. Nguyên nhân gốc

**Graph API không cho sửa `description` của reel nữa.** Đọc thì vẫn được, ghi thì bị từ chối —
kể cả khi ghi lại ĐÚNG nội dung caption đang có (tức không phải lỗi nội dung, không phải lỗi
quyền).

Đo thật từ chính server production ngày 19-09-2026:

| Reel | Ngày tạo | Đọc caption | Ghi caption |
|---|---|---|---|
| `2139078070009288` (trong nhóm đang dùng) | 08-09 | OK | ❌ `OAuthException code 1 — "An unknown error has occurred."` |
| `1560688535789753` | 14-09 | OK | ❌ `GraphMethodException code 100 — "Unsupported request - method type: post"` |
| `2271445090318236` | 10-09 | OK | ❌ cùng lỗi |
| `1792018025312167` | 10-09 | OK | ❌ cùng lỗi |

Token hoàn toàn khoẻ, đã kiểm tra bằng `debug_token`:

```
type=PAGE  app_id=981556708232315  is_valid=true  expires_at=0 (không hết hạn)
scopes=... pages_manage_posts, pages_manage_engagement, pages_read_engagement ...
x-business-use-case-usage: call_count=1  (không hề đụng rate limit)
```

Đây đúng là rủi ro mà chú thích trong `app/Services/FacebookReelSlotService.php` đã ghi từ đầu:
sửa caption reel là API **Meta không tài liệu hoá** (`POST /{reel_id}` với field `description`),
đo thấy chạy được ngày 08-09-2026. Nay họ đã đóng.

## 3. Vì sao nó rơi thẳng xuống Shopee

Chuỗi rơi, đúng như code đang viết:

1. Cờ server vẫn bật chế độ reel — `FacebookRedirectFlagService::flags()` trả
   `{viaFacebookComment: true, facebookMode: "reel", autoRedirect: true}`, nên nút ban đầu vẫn
   đúng là "Lấy mã qua Facebook".
2. Khách bấm mua → `FacebookReelSlotService::reelUrlFor()` quét cả nhóm reel, **reel nào cũng
   lỗi** khi đổi caption → trả `null`.
3. Đáng lẽ rơi tiếp xuống chế độ comment, nhưng `meta.target_post_ids` **đang rỗng** (chỉ cấu
   hình reel) → `ShortLinkController::facebookCommentRedirectUrl()` trả thẳng `$fallbackUrl`.
4. Frontend nhận về link `/go/{code}` (không phải `facebook.com`) → nhãn nút tự đổi thành
   "Mua ngay (đã áp mã)", bấm là sang Shopee.

Mốc thời gian: lần cuối caption còn sửa được là **16:25 ngày 19-09** — short link `r5GFLc9` và
`QlYJf6P` tạo lúc đó hiện vẫn đang nằm trong caption thật của reel trên page. Lượt 17:25 thì cả
nhóm đã hỏng.

## 4. Ba vấn đề phụ phát hiện cùng lúc

- **Nhóm reel có 4 mục rác** trong `meta.target_reel_ids`:
  - một mục dán dính hai link vào nhau:
    `https://www.facebook.com/reel/123456https://www.facebook.com/reel/789012`
  - ba reel **không còn tồn tại** trên page (Graph trả code 100 subcode 33):
    `1849297039410938`, `2459179241261783`, `1092412453747910`

  Mỗi lượt khách bấm mua đốt thêm 4 lời gọi API vô ích trước khi bỏ cuộc.

- **`LOG_LEVEL=error` trong `.env` production** nên toàn bộ `Log::warning` bị nuốt — kể cả
  "không đổi được caption reel, thử reel khác" và "hết slot reel, khách đi thẳng Shopee". Hỏng
  từ chiều mà không có một dòng log nào. Cân nhắc hạ xuống `warning`.

- Lượt `facebook_open` (khách thật sự bấm sang Facebook) đang tụt: 17-09 = 22, 18-09 = 31,
  **19-09 = 2** (cả hai đều trước 02:00).

## 5. Việc cần làm tiếp

- [ ] **Thử chế độ comment dưới BÀI VIẾT thường.** Endpoint `POST /{post_id}/comments` được Meta
      tài liệu hoá đàng hoàng (khác hẳn cái reel), nhiều khả năng còn sống. Code chế độ này còn
      nguyên trong `ShortLinkController::facebookCommentRedirectUrl()`, chỉ thiếu danh sách bài
      viết đích. Cách thử: đăng một comment lên một bài của page rồi xoá ngay.
      - Lưu ý từ chú thích trong code: link trong **bình luận của reel** thì Facebook hiển thị
        thành text thường, **bấm không được** — nên phải là bài viết thường, không phải reel.
- [ ] Nếu comment còn chạy: dọn `meta.target_reel_ids` (bỏ 4 mục rác), điền `meta.target_post_ids`
      ở `/admin/api-config`, tắt `reel_caption_enabled`.
- [ ] Nếu comment cũng chết: **tắt hẳn `comment_redirect_enabled`** — để bật mà không chạy được
      thì khách đọc hướng dẫn "bấm link trong reel" trong khi thực tế đi thẳng Shopee, tức trang
      đang dạy sai. Đồng thời xem lại câu FAQ "phải đi qua Facebook thì mã mới có hiệu lực".
- [ ] Cân nhắc cho `SourceHealthService` (tự bật bảo trì khi nguồn mã chết) canh thêm cả đường
      Facebook này — hôm nay hỏng 5 tiếng mà không ai biết.

## 6. Lệnh kiểm chứng lại (chạy trên prod)

```bash
ssh tietkiemvi
cd /var/www/tietkiemvi.com

# Xem cờ điều khiển luồng + cấu hình Facebook
sudo -u www-data HOME=/tmp php8.4 artisan tinker --execute='
  $c = App\Models\ApiConfig::where("platform","facebook")->first();
  echo json_encode(app(App\Services\FacebookRedirectFlagService::class)->flags()).PHP_EOL;
  echo "reel_ids=".json_encode($c->facebookTargetReelIds()).PHP_EOL;
  echo "post_ids=".json_encode($c->facebookTargetPostIds()).PHP_EOL;
'

# Lỗi gần nhất của từng slot reel (cột sync_error ghi nguyên văn lỗi Graph trả về)
sudo -u www-data HOME=/tmp php8.4 artisan tinker --execute='
  foreach (App\Models\FacebookReelSlot::all() as $s)
    echo $s->reel_id." ".$s->updated_at." ".mb_substr((string)$s->sync_error,0,120).PHP_EOL;
'

# Thử ghi lại ĐÚNG caption đang có (không đổi gì) — cách kiểm tra an toàn xem Meta đã mở lại chưa
sudo -u www-data HOME=/tmp php8.4 artisan tinker --execute='
  $c = App\Models\ApiConfig::where("platform","facebook")->first();
  $svc = new App\Services\FacebookPageService($c->app_id, $c->app_secret);
  $id = "2139078070009288";
  $hien = $svc->fetchReelCaption($id);
  echo "doc=".($hien === null ? "LOI" : "OK").PHP_EOL;
  echo "ghi=".($svc->updateReelCaption($id, (string) $hien) ? "OK" : "THAT BAI: ".$svc->lastError).PHP_EOL;
'
```
