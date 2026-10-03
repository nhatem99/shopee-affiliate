# Bot đăng deal vào nhóm Facebook — bản chạy trên điện thoại

Bản Node của bot ở `deploy/fb-group-runner` (bản Python cho Mac). Hai bản nói chuyện với server
giống hệt nhau, chỉ **chạy một bản một lúc**.

Bot chạy trên **điện thoại Android (Termux)**, dùng wifi nhà. Không chạy trên VPS: Facebook nhận
ra IP trung tâm dữ liệu và bắt xác minh/khoá nick.

Cách hoạt động: Chromium mở thật trên điện thoại (xem được qua app Termux:X11), đã đăng nhập
Facebook. Cứ 1–2 phút bot hỏi server "có bài nào cần đăng không"; có thì vào nhóm đăng, xong báo
kết quả. Giờ giấc, số bài/ngày, khoảng nghỉ đều do server quyết — chỉnh ở `/admin/fb-groups`.

> ⚠️ Facebook không cho phép tự động đăng bài. Nick có thể bị bắt xác minh hoặc khoá nếu đăng dày.
> Khi thấy Facebook bắt xác minh, đăng xuất nick hoặc "tạm thời bị chặn", server tự **tạm dừng**
> và báo qua Zalo.

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
chmod +x phone/*.sh
```

Cập nhật code về sau: `cd ~/shopee-affiliate && git pull`.

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
ảnh: thêm `--image <đường dẫn ảnh>`.

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

- **Lấy danh sách nhóm:** bấm **Lấy nhóm đã tham gia** ở `/admin/fb-groups` (hoặc `node poster.mjs sync`).
- **Bật nhóm:** nhóm lấy về đều đang tắt — tự tích nhóm được đăng, đọc nội quy nhóm trước.
- **Theo dõi:** `/admin/fb-posts`. Nhật ký: `~/.local/share/tietkiemvi-fb-runner/logs/`. Ảnh chụp
  màn hình khi lỗi: `~/.local/share/tietkiemvi-fb-runner/screenshots/`.

Tắt bot chạy nền: `pkill -f boot/fb-poster.sh` trước, rồi `pkill -f "poster.mjs run"`.

## Khi có sự cố

| Thấy gì | Làm gì |
|---|---|
| Admin báo bot **tạm dừng** vì xác minh / đăng xuất | Mở Termux:X11, xác minh hoặc đăng nhập lại, rồi bấm **Chạy tiếp** ở `/admin/fb-groups`. |
| Tạm dừng vì Facebook **tạm chặn đăng bài** | Đừng chạy tiếp ngay. Để nick nghỉ 1–2 ngày, giảm số bài/ngày, nới khoảng nghỉ. |
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
