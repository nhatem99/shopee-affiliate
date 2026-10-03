# Bot đăng deal vào nhóm Facebook

Bot chạy trên **máy nhà (Mac)**, không chạy trên VPS. Nó mở một cửa sổ Chromium đã đăng nhập nick Facebook của bạn. Cứ 1–2 phút bot hỏi server tietkiemvi "có bài nào cần đăng không"; có thì vào nhóm đăng, xong báo kết quả về.

Server quyết định mọi thứ về giờ giấc: đăng mấy bài một ngày, nghỉ bao lâu giữa hai bài, đăng trong khung giờ nào. Những thứ này chỉnh ở trang `/admin/fb-groups`. Bài soạn ở trang `/admin/fb-posts`.

> ⚠️ Facebook không cho phép tự động đăng bài. Nick có thể bị bắt xác minh hoặc bị khoá nếu đăng quá dày.
> Khi bot thấy Facebook bắt xác minh, đăng xuất nick, hay báo "tạm thời bị chặn", nó tự **tạm dừng mọi thứ** và báo qua Zalo.

## Cài đặt (một lần)

```bash
cd deploy/fb-group-runner
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
.venv/bin/python -m playwright install chromium
```

Cần Python 3.10 trở lên.

## 1. Đăng nhập Facebook

```bash
.venv/bin/python runner.py login
```

Một cửa sổ Chromium sẽ mở ra. Bạn đăng nhập Facebook trong đó, kể cả mã 2 lớp nếu có. Khi thấy bảng tin thì quay lại terminal bấm Enter.

Phiên đăng nhập được lưu ở `~/.local/share/tietkiemvi-fb-runner/profile`. Thư mục này chứa cookie Facebook của bạn, đừng chia sẻ nó cho ai.

## 2. Thử trên một nhóm, chưa đăng thật

```bash
.venv/bin/python runner.py dry-run --group https://www.facebook.com/groups/<id-nhom-test>
```

Bot sẽ mở nhóm, bấm vào ô "Bạn viết gì đi", điền thử nội dung rồi **dừng trước nút Đăng**. Bạn xem trên cửa sổ Chromium, xong bấm Enter để đóng. Bài nháp sẽ bị bỏ.

Muốn thử kèm ảnh thì thêm `--image <đường dẫn ảnh trên máy>`.

## 3. Nối bot với server

1. Vào `/admin/fb-groups`, bấm **Tạo token**.
2. Dán đoạn JSON vừa hiện ra vào file `~/.config/tietkiemvi-fb-runner/config.json`.
3. Khoá file lại để chỉ tài khoản của bạn đọc được:

```bash
mkdir -p ~/.config/tietkiemvi-fb-runner
nano ~/.config/tietkiemvi-fb-runner/config.json   # dán JSON vào rồi lưu
chmod 600 ~/.config/tietkiemvi-fb-runner/config.json
```

Nếu file vẫn để người khác đọc được, bot sẽ không chịu chạy.

## 4. Chạy

```bash
.venv/bin/python runner.py run
```

Bấm **Ctrl+C** để dừng.

- **Lấy danh sách nhóm:** bấm **Lấy nhóm đã tham gia** ở `/admin/fb-groups`. Bot sẽ làm ở lượt hỏi kế tiếp. Muốn lấy ngay cũng được, chạy `runner.py sync`.
- **Bật nhóm:** nhóm bot lấy về **đều đang tắt**. Bạn tự tích những nhóm được đăng. Nên đọc nội quy nhóm trước, vì nhiều nhóm cấm đăng link.
- **Theo dõi bài:** xem trạng thái từng bài ở `/admin/fb-posts`. Nhật ký của bot nằm ở `~/.local/share/tietkiemvi-fb-runner/logs/runner.log`. Ảnh chụp màn hình khi có lỗi nằm ở `.../screenshots/`.

### Chạy nền, tự bật lại (launchd)

1. Chép file `launchd/com.tietkiemvi.fb-runner.plist.example` sang `~/Library/LaunchAgents/com.tietkiemvi.fb-runner.plist`.
2. Sửa các đường dẫn trong file cho đúng máy bạn.
3. Bật nó lên:

```bash
launchctl load ~/Library/LaunchAgents/com.tietkiemvi.fb-runner.plist
launchctl unload ~/Library/LaunchAgents/com.tietkiemvi.fb-runner.plist   # khi muốn tắt
```

File mẫu đã bọc lệnh chạy trong `caffeinate -i` để máy không ngủ khi bot đang chạy. Tuy vậy, **gập nắp MacBook thì máy vẫn ngủ**. Máy ngủ cũng không sao: lịch đăng nằm ở server, máy thức dậy thì bot đăng tiếp.

## Khi có sự cố

| Thấy gì | Làm gì |
|---|---|
| Trang admin báo bot đã **tạm dừng** vì Facebook bắt xác minh hoặc nick bị đăng xuất | Mở cửa sổ Chromium của bot, xác minh hoặc đăng nhập lại cho xong, rồi bấm **Chạy tiếp** ở `/admin/fb-groups`. |
| Bot tạm dừng vì Facebook **tạm chặn đăng bài** | Đừng bấm Chạy tiếp ngay. Để nick nghỉ 1–2 ngày, rồi giảm số bài mỗi ngày và nới khoảng nghỉ giữa hai bài. |
| Bài ở trạng thái **"Không rõ — xem nhóm"** | Bot đã bấm Đăng nhưng không xác nhận được bài có lên hay không. Mở nhóm xem trước; chỉ bấm **Đăng lại** khi chắc chắn bài chưa lên. |
| Nhóm bị **bot tự tắt** | Nick chưa được duyệt vào nhóm, hoặc nhóm chỉ cho quản trị viên đăng bài. |
| Bot báo `Server từ chối token (403)` | Token đã bị tạo lại. Dán token mới vào `config.json`. |
| Facebook đổi giao diện, bot không tìm thấy nút | Các chữ trên nút mà bot dựa vào nằm trong `fbrunner/ui.py`. |

## Kiểm tra code

```bash
.venv/bin/python -m unittest discover -s tests
```
