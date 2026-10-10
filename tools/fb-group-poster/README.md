# Bot đăng deal vào nhóm Facebook — bản chạy trên điện thoại

Bản Node của bot ở `deploy/fb-group-runner` (bản Python cho Mac). Hai bản nói chuyện với server
giống nhau, chỉ **chạy một bản một lúc** — riêng việc kiểm tra bài có được duyệt không (bên dưới)
chỉ bản Node từ 1.2.0 làm, đăng bằng nhiều page chỉ bản Node từ 1.3.0 làm.

Bot chạy trên **điện thoại Android (Termux)**, dùng wifi nhà. Không chạy trên VPS: Facebook nhận
ra IP trung tâm dữ liệu và bắt xác minh/khoá nick.

Cách hoạt động: Chromium mở thật trên điện thoại (xem được qua app Termux:X11), đã đăng nhập
Facebook. Cứ 1–2 phút bot hỏi server "có bài nào cần đăng không"; có thì vào nhóm đăng, xong báo
kết quả. Giờ giấc, số bài/ngày, khoảng nghỉ đều do server quyết — chỉnh ở `/admin/fb-groups`.

> ⚠️ Facebook không cho phép tự động đăng bài. Nick có thể bị bắt xác minh hoặc khoá nếu đăng dày.
> Khi thấy Facebook bắt xác minh hoặc đăng xuất nick, server tự **tạm dừng** cả bot; khi một page
> bị "tạm thời bị chặn", chỉ page đó **nghỉ**, page sau đăng tiếp. Cả hai đều báo qua Zalo.

## Cài đặt (một lần)

Cần **Termux** và **Termux:Boot** (cài từ F-Droid, không lấy bản Play Store) và **Termux:X11** (tải
APK `app-arm64-v8a-debug.apk` ở https://github.com/termux/termux-x11/releases). Nếu đã chạy cầu nối
Zalo trên máy này thì đã có Termux và Termux:Boot.

Trong Termux:

```bash
pkg update -y && pkg upgrade -y
pkg install -y x11-repo
pkg install -y chromium termux-x11-nightly nodejs-lts git
```

Lấy code (chỉ lấy thư mục bot):

```bash
cd ~
git clone --filter=blob:none --sparse https://github.com/nhatem99/shopee-affiliate.git
cd shopee-affiliate
git sparse-checkout set tools/fb-group-poster
cd tools/fb-group-poster
npm install
```

Cập nhật code về sau: `cd ~/shopee-affiliate && git pull`. Nếu git báo "Your local changes … would be
overwritten" (thường do lỡ `chmod` file): `git checkout -- tools/fb-group-poster && git pull`.

## 1. Mở Chromium và đăng nhập Facebook

```bash
phone/start-chromium.sh
node poster.mjs login
```

Mở app **Termux:X11**: thấy cửa sổ Chromium trang Facebook. Đăng nhập (kể cả mã 2 lớp). Thấy bảng tin
rồi thì quay lại Termux bấm Enter.

Phiên đăng nhập lưu ở `~/.local/share/tietkiemvi-fb-runner/chromium-profile` — đừng chép thư mục này
cho ai.

## 2. Nối bot với server

1. Vào `/admin/fb-groups`, bấm **Tạo token**.
2. Dán đoạn JSON vào `~/.config/tietkiemvi-fb-runner/config.json` và khoá file:

```bash
mkdir -p ~/.config/tietkiemvi-fb-runner
nano ~/.config/tietkiemvi-fb-runner/config.json   # dán JSON rồi lưu
chmod 600 ~/.config/tietkiemvi-fb-runner/config.json
```

3. Kiểm tra (không nhận bài nào):

```bash
node poster.mjs check
```

Phải thấy cả hai dòng `Server OK` và `đã đăng nhập Facebook`.

## 3. Thử một nhóm, chưa đăng thật

```bash
node poster.mjs dry-run --group https://www.facebook.com/groups/<id-nhom-test>
```

Bot mở nhóm, điền nội dung rồi **dừng trước nút Đăng**. Xem trên Termux:X11, xong bấm Enter. Thử kèm
ảnh: thêm `--image <đường dẫn ảnh>`, lặp lại tối đa 5 lần để thử bài nhiều ảnh
(`--image a.jpg --image b.jpg`).

Bài soạn ở /admin/fb-posts có thể kèm tới 5 ảnh (ảnh sản phẩm Shopee + ảnh tự tải lên) hoặc là
"bài tự soạn" không link. Ảnh tự tải lên chỉ bot từ bản 1.1.0 đăng được — bot cũ hơn được server
cho qua những bài đó (trang admin báo "đang chờ"), nên nhớ `git pull` rồi chạy lại bot.

## 3b. Thử đọc "Nội dung của bạn" của một nhóm

Từ bản 1.2.0, lúc rảnh bot tự mở "Nội dung của bạn" trong nhóm (Đang chờ / Đã đăng / Bị từ chối / Đã
gỡ) để biết bài đã đăng có được admin nhóm duyệt không. Thử tay trên một nhóm — chỉ đọc, không gọi
server:

```bash
node poster.mjs review --group https://www.facebook.com/groups/<id-nhom-test>
node poster.mjs review --group https://www.facebook.com/groups/<id-nhom-test> --text "đoạn đầu bài đã đăng"
```

Mỗi tab in ra: mở được không, các bài thấy được (kèm link nếu có). Có `--text` thì in thêm bài đó
nằm ở tab nào. Tab nào báo "KHÔNG mở được" mà trên Facebook vẫn có tab đó: mở tab bằng tay, chép
đường dẫn trên thanh địa chỉ gửi cho người sửa code (đường dẫn các tab nằm ở
`FacebookGroupReviewChecker::TABS` phía server và `lib/review.mjs`).

## 3b'. Link ở bình luận

Từ bản 1.4.0, bài soạn ở /admin/fb-posts có thể tích **"Để link ở bình luận"**: bài lên nhóm không
có link nào (chỗ `{link}` thành câu kiểu "👇 Link mua có mã ở bình luận"), đăng xong bot tự vào bài
bình luận link mua — bằng đúng page đã đăng. Bài phải chờ admin nhóm duyệt thì bot bình luận sau khi
lượt kiểm tra duyệt bài (mục 3b) thấy bài đã lên. Bot cũ hơn 1.4.0 không nhận những bài này.

Bot tìm bài theo thứ tự: link bài bắt được lúc bấm Đăng → "Nội dung của bạn" (Đã đăng) → bảng tin
nhóm xếp bài mới trước, nhận ra bài bằng đoạn đầu nội dung. Trên trang không có đúng bài của mình
thì bot không gõ gì. Thấy link đã có trên bài thì không bình luận lại.

Thử tay trên nhóm test (**tắt bot chạy nền trước**, xem mục 3c). Mặc định chỉ điền ô bình luận rồi
dừng, xem trên Termux:X11 rồi bấm Enter để bỏ:

```bash
node poster.mjs comment --post https://www.facebook.com/groups/<id-nhom-test>/posts/<id-bai>/
node poster.mjs comment --group https://www.facebook.com/groups/<id-nhom-test> --find "đoạn đầu bài đã đăng"
node poster.mjs comment --group https://www.facebook.com/groups/<id-nhom-test> --find "đoạn đầu bài" --text "Bình luận thử" --send
```

`--send` mới gửi thật (phải có `--find` để bot chắc đúng bài). Không thấy ô bình luận: chữ trên ô và
nút nằm ở `COMMENT_BOX` / `COMMENT_BUTTON` trong `lib/ui.mjs`.

## 3c. Đăng bằng nhiều page

Từ bản 1.3.0, ngoài nick chính bot đăng được bằng các Trang (fanpage) mà nick đang quản trị. Thêm
Trang ở mục **Page đăng bài** trên `/admin/fb-groups`. Các page đăng lần lượt theo thứ tự: page trên
đăng đủ số bài/ngày của nó rồi mới tới page dưới. Trước mỗi bài bot chuyển sang đúng page bằng cách
mở trang của page rồi bấm **Chuyển ngay** (như bấm tay). Muốn về nick chính thì bot bỏ cookie
`i_user`. Trang phải tự tham gia từng nhóm, và nhóm phải cho Trang tham gia. Thêm page xong, bot tự
lấy danh sách nhóm của page đó.

Thử tay trước khi cho bot chạy thật. **Tắt bot chạy nền trước** (xem cuối mục 4): hai bot cùng
điều khiển một Chromium sẽ giẫm chân nhau.

```bash
node poster.mjs whoami
node poster.mjs switch --to https://www.facebook.com/profile.php?id=<uid-page>
node poster.mjs whoami
node poster.mjs switch --to primary
```

`switch` in ra những cookie bị đổi sau khi chuyển. Không chuyển được thì gửi các dòng nó in ra (kèm
ảnh màn hình Termux:X11) cho người sửa code. Chữ trên nút nằm ở `SWITCH_BUTTON` trong `lib/ui.mjs`,
cách nhận ra page đang dùng nằm ở `identity()` trong `lib/browser.mjs`.

## 4. Chạy

Chạy tay (Ctrl+C để dừng):

```bash
node poster.mjs run
```

Tự chạy khi bật máy (Termux:Boot):

```bash
mkdir -p ~/.termux/boot
cp phone/fb-poster.sh ~/.termux/boot/fb-poster.sh
```

Script giữ máy thức (`termux-wake-lock`), mở Chromium, chạy bot, bot tắt thì tự chạy lại.

Trong cài đặt Android của Xiaomi, với **Termux**, **Termux:X11**, **Termux:Boot**: bật *Tự khởi
động*, chọn *Không hạn chế* ở Tiết kiệm pin. Không làm thì MIUI sẽ tắt bot khi tắt màn hình.

- **Lấy danh sách nhóm:** bấm **Lấy nhóm đã tham gia** ở `/admin/fb-groups`. Bot lần lượt chuyển
  sang từng page đang bật để lấy nhóm của page đó. `node poster.mjs sync` chỉ lấy nhóm của page
  đang mở.
- **Bật nhóm:** nhóm lấy về đều đang tắt — tự tích nhóm được đăng, đọc nội quy nhóm trước.
- **Bài có được duyệt không:** bot tự kiểm tra khoảng 1 giờ sau khi đăng, lúc rảnh và trong khung
  giờ đăng; kết quả hiện ở `/admin/fb-posts` (từng bài) và cột **Duyệt** ở `/admin/fb-groups` (từng
  nhóm). Muốn kiểm tra ngay: nút **Kiểm tra duyệt bài ngay** ở `/admin/fb-groups`.
- **Theo dõi:** `/admin/fb-posts`. Nhật ký: `~/.local/share/tietkiemvi-fb-runner/logs/`. Ảnh chụp
  màn hình khi lỗi: `~/.local/share/tietkiemvi-fb-runner/screenshots/`.

Tắt bot chạy nền: `pkill -f boot/fb-poster.sh` trước, rồi `pkill -f "poster.mjs run"`.

## Khi có sự cố

| Thấy gì | Làm gì |
|---|---|
| Admin báo bot **tạm dừng** vì xác minh / đăng xuất | Mở Termux:X11, xác minh hoặc đăng nhập lại, rồi bấm **Chạy tiếp** ở `/admin/fb-groups`. |
| Một page **đang nghỉ** vì Facebook tạm chặn đăng bài | Bot đã tự chuyển sang page sau. Để page đó nghỉ 1–2 ngày, giảm số bài/ngày của nó rồi mới bấm **Mở lại**. |
| Một page **đang nghỉ** vì "Bot không chuyển được sang page này" | Mở Termux:X11 xem trang của page còn nút **Chuyển ngay** không, nick còn quản trị page không. Thử `node poster.mjs switch --to <link page>` (mục 3c), sửa xong bấm **Mở lại**. |
| Bài báo **"chưa bình luận được link"** | Bot thử 3 lần (cách nhau 20 phút) vẫn hỏng — lý do ghi ngay dưới bài ở `/admin/fb-posts`, ảnh chụp màn hình ở `screenshots/comment-<số bài>-…png`. Bình luận tay vào bài, hoặc thử `node poster.mjs comment` (mục 3b'). |
| Bài **"Không rõ — xem nhóm"** | Bot đã bấm Đăng nhưng không xác nhận được. Mở nhóm xem; chỉ **Đăng lại** khi chắc chắn bài chưa lên. |
| `Server từ chối token (403)` | Token đã bị tạo lại — dán token mới vào `config.json`. |
| `Chromium không mở được cổng 9222` | Xem `~/.local/share/tietkiemvi-fb-runner/chromium.log`; mở app Termux:X11 một lần rồi chạy lại. |
| Facebook đổi giao diện, bot không tìm thấy nút | Chữ trên nút bot dựa vào nằm ở `lib/ui.mjs` (giữ giống `deploy/fb-group-runner/fbrunner/ui.py`). |

Cổng 9222 chỉ nghe trong máy (127.0.0.1), nhưng app khác trên cùng điện thoại vẫn gọi được — đừng
cài app lạ lên máy chạy bot.

## Kiểm tra code

```bash
npm test
```
