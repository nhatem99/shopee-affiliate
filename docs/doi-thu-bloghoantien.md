# bloghoantien.com — họ làm hoàn tiền thế nào

**Ngày khảo sát:** 26-09-2026. Mọi số liệu dưới đây đọc trực tiếp từ HTML/JS công khai của họ
(qua `ssh tietkiemvi` vì sandbox local chặn mạng ra ngoài), không phải suy đoán.

---

## 1. Câu trả lời ngắn: có dùng AccessTrade, nhưng chỉ cho phần rìa

| Tầng | Sàn | Đường đi | Bằng chứng |
|---|---|---|---|
| Lõi | Shopee | Affiliate Shopee **trực tiếp** | 30 link `shopee.vn/universal-link/...&mmp_pid=an_17305690359` |
| Lõi | Lazada | Short link của chính Lazada | `s.lazada.vn/s.ohV90?c=d&t=p-i2kic2p-sDUofwV` |
| Lõi | TikTok Shop | API riêng `/api/tiktok/search` → trang nội bộ `/san-pham/tiktok/{id}` | — |
| Lõi | ShopeeFood | `/hoan-tien/shopeefood?link=...` | — |
| Rìa | Tiki 10.5%, FPT Telecom 4.95%, VP Bank 3.15% | **AccessTrade** | `go.isclix.com/deep_link/v5/6025682837957300282/{campaign_id}?sub4=oneatweb`, logo từ `content.accesstrade.vn` |

Publisher ID AccessTrade của họ: `6025682837957300282` (của mình: `7076279747515259482`).
Phần rìa chỉ là **deep link cấp chiến dịch**, không xử lý theo từng sản phẩm — tức họ đổ nguyên
feed chiến dịch AccessTrade vào làm danh sách "các sàn khác cũng có hoàn tiền".

## 2. Luồng khách

```
dán link → server phân giải → CHUYỂN TỚI TRANG SẢN PHẨM NỘI BỘ /san-pham/{id}
        → bấm MUA NGAY → POST /api/shopee/create-link → window.location tới short link của họ
        → thẳng Shopee
```

Khác mình ở hai điểm lớn:
- Họ **giữ khách lại một trang sản phẩm trung gian** (có giá, ảnh, số tiền hoàn dự kiến, nút
  "Sao chép link" để chia sẻ). Mình đưa thẳng link ra ngoài.
- Họ **không có bước vòng qua Facebook nào cả** — vì họ bán hoàn tiền thuần, không bán "mã giảm
  giá độc quyền chỉ áp được khi đến từ Facebook".

Endpoint của họ: `/api/shopee/create-link`, `/api/shopee/get-final-link`, `/api/shopee/render-link`,
`/api/lazada/get-by-link`, `/api/tiktok/search`, `/api/system-errors/report`.

## 3. Bốn cơ chế chống thất thoát

1. **Bắt đăng nhập mới tạo được link mua.** `getLink()` chặn thẳng: *"Vui lòng đăng nhập để mua
   hàng và nhận hoàn tiền."* → không có đơn vô chủ. (Mình cho khách vãng lai lấy link — đó là
   lý do báo cáo affiliate của mình có nhiều dòng Sub_id không quy được về ai.)
2. **Chặn desktop hai lớp**: client-side + header `X-Shopee-Device-Type` / `-Platform` /
   `-Max-Touch-Points` gửi kèm mọi request tạo link.
3. **Banner cảnh báo webview** (`/client/js/in-app-browser-notice.js`): nhận ra
   Messenger / Facebook / Zalo rồi hiện "Mở bằng Safari" hoặc "Mở bằng Chrome".
4. **Danh sách loại trừ viết rất kỹ**: đơn từ LiveStream/Video, thêm giỏ trước rồi mới bấm mua,
   đặt hàng xong mới quay lại lấy link, bấm link affiliate của bên khác lúc thanh toán, đổi
   thiết bị/trình duyệt/tài khoản giữa chừng.

## 4. Con số họ hiện cho khách

Trang sản phẩm ghi **"Hoàn tiền dự kiến 17.485đ"** cạnh giá 269.000đ.

Đối chiếu với nguồn hoa hồng thật (`data.addlivetag.com`, chính nguồn mình đang dùng) cho đúng
item đó: `sellerRate = 0.1`, `shopeeRate = 0.04`, `commission = 37.660đ`.

→ **Họ chia lại khoảng 46% hoa hồng** (17.485 / 37.660). Tỉ lệ của mình là Setting
`cashback_rate` (thực) và `cashback_display_rate` (hiển thị).

Thời gian: ghi nhận đơn 24-36 giờ, đối soát 5-10 ngày, rút tối thiểu 10.000đ. Có bảng xếp hạng
và chương trình giới thiệu bạn bè (mình đã có cả hai).

## 5. Thứ mình học và đã làm

`sellerRate + shopeeRate` **là phân số, không phải phần trăm** (0.1 + 0.04 = 0.14 = 14%), và
nguồn trả thẳng `commission` bằng tiền. `ShopeeProductLookupService` đã lấy số này từ lâu nhưng
`ShopeeLinkResolverService::fetchProductInfo()` ưu tiên nhánh gọi thẳng Shopee (không có trường
hoa hồng) rồi `return` luôn — nên con số bị vứt đi và giao diện không bao giờ ước tính được.

Đã sửa: ghép hai nguồn, thêm endpoint `GET /voucher/hoa-hong/{itemId}` (gọi SAU khi kết quả đã
hiện, cache 6 giờ) và hiện "Hoàn tiền dự kiến ~X đ" trên thẻ kết quả.

## 6. Lệnh dò lại

```bash
ssh tietkiemvi
curl -s -A "Mozilla/5.0 (iPhone)" https://bloghoantien.com/ -o /tmp/bht.html

# Domain ngoài xuất hiện trên một trang
grep -oE "https?://[a-zA-Z0-9._-]+" /tmp/bht.html | sed 's|https\?://||' | sort | uniq -c | sort -rn

# Dấu vết mạng affiliate (trang /hoan-tien là nơi lộ rõ nhất)
curl -s https://bloghoantien.com/hoan-tien | grep -oiE ".{0,120}isclix.{0,160}"

# Hoa hồng thật của một item Shopee (nguồn mình đang dùng)
curl -s "https://data.addlivetag.com/product-data/product-data.php?item_id=29816681536" | python3 -m json.tool | head -40
```
