# Bản vá cầu nối Zalo: gửi ảnh bằng URL

## Vì sao cần

Bot nhóm Zalo bằng nick cá nhân đi qua cầu nối hermes-zalo-plugin (Node + zca-js), chạy ở máy nhà
(điện thoại Termux hoặc Mac), không phải trên VPS. Tin deal trong các nhóm nguồn có kèm ảnh, nhưng
`POST /send-attachment` bản gốc chỉ nhận **đường dẫn file nằm trên máy chạy cầu nối**. Laravel ở
VPS chỉ có URL ảnh, không đặt file lên điện thoại được.

Bản vá này cho cầu nối tự tải ảnh từ URL rồi gửi. Nó chỉ sửa `server.js` của cầu nối và không đụng
gì tới repo Laravel.

## Bản vá làm gì

`POST /send-attachment` nhận thêm một dạng body:

```json
{ "threadId": "<id nhóm>", "threadType": "group", "urls": ["https://..."], "caption": "..." }
```

- Tải từng URL về file tạm trong `os.tmpdir()` (tên `zalo-bridge-<uuid>.<đuôi>`), gửi bằng đúng hàm
  cũ `client.sendAttachment`, xong thì xoá file tạm, **kể cả khi lỗi**.
- Giới hạn: chỉ http/https, tối đa 10 URL, mỗi ảnh tối đa 15 MB (kiểm cả `content-length` lẫn số
  byte thật), 20 giây mỗi ảnh, response phải là 2xx và `image/*`.
- Đuôi file lấy theo content-type (`jpg`/`png`/`webp`/`gif`, loại `image/*` khác thì đặt `jpg`) vì
  zca-js chọn cách gửi theo đuôi file, mà link CDN thường không có đuôi.
- **Được cả hoặc không được gì**: tải đủ mọi ảnh rồi mới gửi. Chết một link là cả lượt trả 422 và
  không có gì lên nhóm, nên không bao giờ ra album thiếu ảnh.
- Gửi `paths`/`path` như cũ thì chạy y hệt bản gốc. Gửi cả `paths` lẫn `urls` thì trả 400.

| Mã | Nghĩa |
|----|-------|
| 200 | `{ "success": true, "result": ... }`, đã gửi |
| 400 | Body sai: thiếu `threadId`, `urls` không phải mảng chuỗi, rỗng, quá 10, không phải http(s), gửi cả `paths` lẫn `urls` |
| 422 | Có URL không tải được thành ảnh. `error` ghi rõ `urls[i]`, URL và lý do (`HTTP 404`, `not an image`, `too large`, `timed out after 20s`, `fetch failed: ENOTFOUND`…) |
| 500 | Tải xong nhưng Zalo từ chối gửi (hoặc lỗi ghi file tạm) |
| 401 / 403 / 503 | Như mọi route khác: sai token / bị chặn quyền `uploadAttachment` / nick chưa đăng nhập |

Caption: nếu chỉ có **1 ảnh jpg/png/webp** thì caption nằm chung bong bóng với ảnh. Nhiều ảnh
(album) hoặc ảnh gif thì zca-js gửi caption thành một tin chữ riêng, **trước** album.

## Cài

Cần Node 18 trở lên (dùng `fetch` có sẵn). Chép file `send-attachment-urls.patch` sang máy chạy cầu
nối (scp, hoặc tải bản Raw trên GitHub), rồi chạy:

```bash
cd <thư mục clone hermes-zalo-plugin>
git apply --check /đường/dẫn/send-attachment-urls.patch   # thử trước, không sửa gì
git apply /đường/dẫn/send-attachment-urls.patch
node --check server.js
```

Sau đó **khởi động lại cầu nối**: dừng tiến trình `node server.js` / `npm start` đang chạy rồi bật
lại theo cách đang dùng (Termux, LaunchAgent trên Mac, hay `supervisorctl restart zalo-bridge` trên
VPS). Phiên đăng nhập lưu trong file nên không phải quét QR lại.

Nếu `--check` báo lỗi vì `server.js` trên máy đó đã bị sửa khác đi, thử `git apply --3way`.

Gỡ bản vá: `git apply -R /đường/dẫn/send-attachment-urls.patch`, rồi khởi động lại.

## Không cài thì sao

Cầu nối cũ không biết `urls`, nên coi như thiếu `paths` và trả
`400 {"error":"threadId and paths required"}`. Phía Laravel coi đó là "không gửi được ảnh", và bản
chép tin sang nhóm mình **vẫn đăng phần chữ (link đã đổi sang mã của mình)**, chỉ thiếu ảnh.
