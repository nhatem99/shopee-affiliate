# Tự đúc link có mã

> **Đây là nguồn mã duy nhất của hệ thống.** Nguồn cũ (gọi API salesoc.vn qua relay) đã được gỡ bỏ
> hoàn toàn ngày 2026-09-05 cùng với 4 relay Deno/Vercel/Netlify/Cloudflare. Không còn đường lùi:
> cơ chế này hỏng là khách không có mã. Đổi lại, hoa hồng về hết tài khoản của mình và không còn
> phụ thuộc một bên có thể chặn mình bất cứ lúc nào.

## Vấn đề

Mã giảm giá "Mã FB / Mã IG" nằm trong link Shopee dưới dạng chữ ký:

```
https://shopee.vn/product/973033125/26480867602
  ?channel_type=fb            <- kênh quyết định là mã FB hay mã IG
  &credential_token=8wEwiDL7YDtwoNqUv79PdX4kJmSD46eVXKsehGUZM4     (42 ký tự)
  &encrypted_payload=0XB0zjexzPblubO0ff9f6xPc4HUNsLMtmgMK...       (212 ký tự)
  &mmp_pid=an_17356640097     <- ID tài khoản KOL
  &cts=1788570772             <- thời điểm link được đúc
```

`credential_token` và `encrypted_payload` **do Shopee ký**. So hai link thật của cùng một KOL cho
cùng một sản phẩm (một FB, một IG, cách nhau 6 phút):

| Tham số | Kết quả so sánh |
|---|---|
| `channel_type`, `content_source` | `fb` vs `ig` — công tắc kênh |
| `mmp_pid` | giống hệt |
| `credential_token` | **30 ký tự đầu trùng khít**, 12 ký tự cuối khác |
| `encrypted_payload`, `sm_sig_id`, `fb_content_id` | khác hoàn toàn |

30 ký tự đầu của token buộc chặt vào tài khoản KOL, phần còn lại đúc mới theo từng link. Nghĩa là
**không thể tự sinh link có mã từ một ID KOL** — chỉ có đường đọc ngược về từ nền tảng. Đó là lý do
cơ chế này tồn tại, và cũng là lý do phải có một trình duyệt thật trong chuỗi.

## Cơ chế

```
ID KOL + link sản phẩm khách dán
    │  ChannelVoucherLinkBuilder — dựng link affiliate TRƠN (chưa mã), gắn marker vào utm_term
    ▼
Graph API: POST /{POST_ID}/comments        (FacebookGraphClient)
    ▼
COMMENT_ID → GET ?fields=permalink_url
    ▼
Chromium thật mở permalink, tìm <a> chứa marker, CLICK   (deploy/browser-resolver ← thư mục này)
    │  bấm từ trong ngữ cảnh Facebook mới ra mã; đi theo redirect từ server thì không
    ▼
URL đích — có credential_token/encrypted_payload = ĐÃ CÓ MÃ
    ▼
DELETE /{COMMENT_ID}   (dọn comment, link đã đúc vẫn sống)
    ▼
AffiliateLinkRewriterService đổi mmp_pid: KOL → của mình (KOC), chữ ký giữ nguyên nên mã vẫn áp
    ▼
/go/{code} trả cho khách
```

Việc này tốn 10-60s nên chạy trong `App\Jobs\ResolveVoucherLinks`, frontend hỏi lại kết quả qua
`GET /voucher/status/{token}`.

## Cần có trước

- Một **Facebook Page** và một **bài đăng cố định** trên Page đó để comment vào (`POST_ID` dạng
  `{page_id}_{post_id}`).
- **Page Access Token dài hạn** với quyền `pages_manage_engagement` (tạo/xoá comment) và
  `pages_read_engagement` (đọc lại comment).
- Tài khoản **KOL Shopee đã liên kết kênh Facebook/Instagram** — chữ ký gắn với tài khoản này.
- Một tài khoản Facebook thật để trình duyệt đăng nhập (xem được trang comment).

## Cài đặt

```bash
cd deploy/browser-resolver
npm install
npx playwright install --with-deps chromium     # cần sudo, cài thư viện hệ thống cho Chromium

node login.mjs                                   # đăng nhập Facebook 1 lần, phiên lưu vào .browser-profile/

RESOLVER_SECRET=<đúng chuỗi trong config/services.php> pm2 start server.mjs --name browser-resolver
pm2 save
```

Máy chủ không có màn hình thì chạy `node login.mjs` ở máy cá nhân rồi `scp -r .browser-profile/` lên
VPS. Thư mục đó chứa **phiên đăng nhập Facebook thật** — không commit, không để quyền đọc cho user khác
(đã có `.gitignore`).

Service chỉ nghe `127.0.0.1:8787`, không mở ra Internet. Vẫn bắt buộc header `X-Resolver-Secret` vì
mọi process trên cùng VPS đều gọi được localhost.

## Bật lên

Điền vào `.env` trên server (hoặc sửa mặc định trong `config/services.php`):

```env
CHANNEL_VOUCHER_ENABLED=true
FACEBOOK_PAGE_ACCESS_TOKEN=EAAG...
FACEBOOK_POST_ID=123456789_987654321
SHOPEE_KOL_PID=an_17356640097
BROWSER_RESOLVER_SECRET=<cùng chuỗi với RESOLVER_SECRET ở trên>
```

**Mặc định là TẮT.** Và vì không còn nguồn dự phòng, bật lên khi chưa điền đủ token/post_id nghĩa là
khách quét link xong **không nhận được mã nào**. Chỉ bật sau khi `voucher:mint-check` chạy xanh.

## Kiểm tra

```bash
php artisan voucher:mint-check "https://shopee.vn/...-i.<shop_id>.<item_id>"
php artisan voucher:mint-check "<link>" --channel=ig
```

In ra token Facebook còn sống không, trình duyệt còn đăng nhập không, rồi từng mắt xích của chuỗi
kèm thời gian. Chuỗi này nối 3 hệ thống ngoài tầm kiểm soát (Meta, Chromium, Shopee) — khi không ra
mã, câu hỏi luôn là "gãy ở mắt nào", và lệnh này trả lời trong một lần chạy.

Bước cuối `kiểm tra chữ ký mã` là bước quan trọng nhất: nó phân biệt "chuỗi chạy xong" với "chuỗi
ra được mã". Thiếu `credential_token`/`encrypted_payload` thì link thu về chỉ là link trơn của
chính mình — hệ thống coi là **thất bại** và không trả link nào, thay vì đưa cho khách một nút
"Mã FB" bấm vào chẳng giảm đồng nào.

## Điểm chết và dấu hiệu

| Hỏng ở đâu | Dấu hiệu trong `/admin/logs` | Cách chữa |
|---|---|---|
| Token hết hạn | `FacebookGraphClient: Graph API từ chối` code **190** | Cấp lại Page Access Token |
| Thiếu quyền | code **200** | Thêm `pages_manage_engagement` |
| Page bị Facebook hạn chế vì spam | code **368** | Giảm tần suất, tăng `cache_minutes`, đổi bài đăng |
| Vượt rate limit | code **613** | Chờ, hoặc tăng `cache_minutes` |
| Chromium mất phiên | `browser service không lấy được link`, `/health` trả `logged_in=false` | Chạy lại `node login.mjs` |
| Chuỗi chạy xong nhưng không ra mã | `ChannelVoucherMinter: chuỗi chạy xong nhưng không ra mã` | Xem mục dưới |

## Điều chưa kiểm chứng

Giả định gốc của cơ chế: **đăng link lên Facebook rồi mở ra thì Shopee đúc mã vào link**. Giả định
này chưa được kiểm chứng trên hệ thống thật — hai link mẫu dùng để thiết kế là link tạo tay, không
biết chắc thao tác nào khiến Shopee ký. Nếu chữ ký thực ra được đúc bên trong app Shopee Affiliate
lúc bấm "chia sẻ" chứ không phải do Facebook, thì bước comment là thừa và phải nhắm tự động hoá chỗ
khác. `voucher:mint-check` là công cụ để trả lời câu này bằng dữ liệu chứ không phải bằng suy đoán.

Hai điểm yếu đã biết:

- **Instagram không tự biến URL trong comment thành link bấm được.** Kênh `ig` chạy hết được chuỗi
  Graph API nhưng nhiều khả năng trình duyệt sẽ không tìm thấy thẻ `<a>` nào để bấm. Kênh này để đó
  cho `voucher:mint-check --channel=ig` xác nhận, chưa nên bật cho khách.
- **Comment IG không có `permalink_url`.** Graph API không trả field này cho comment Instagram, nên
  kênh `ig` phải lùi về permalink của cả media rồi để trình duyệt tự tìm comment bằng marker.

## Chi phí vận hành

Mỗi lượt quét **một sản phẩm mới** = một comment thật lên Facebook. Vì vậy:

- Kết quả cache theo sản phẩm `services.channel_voucher.cache_minutes` (mặc định 15 phút) — hai
  khách cùng xem một sản phẩm hot dùng chung một lần đúc.
- Comment bị xoá ngay sau khi đọc xong (`delete_comment_after`), kể cả khi chuỗi hỏng giữa chừng.
- Job đặt `tries = 1`: thử lại tự động nghĩa là nhân đôi comment rác đúng lúc Facebook đang trục trặc.
