# Relay gọi hộ salesoc.vn

## Vì sao cần

`SalesOcService` lấy mã giảm giá thật từ API của salesoc.vn. Salesoc chặn theo **nguồn gọi** ở tầng
nginx (403 trả về trước khi request vào app của họ), và đã chặn lần lượt:

| Ngày | Nguồn bị chặn | Dấu hiệu |
|------|---------------|----------|
| trước 2026-09-04 | IP thật của VPS | 403 nginx khi gọi thẳng |
| 2026-09-04 | Cloudflare Worker | 403 nginx qua relay — Cloudflare tự chèn header `CF-Worker` vào mọi subrequest, không tắt được, nên một rule nginx chặn được mọi Worker bất kể IP |

Cùng lúc đó, cùng một request từ IP nhà vẫn trả 200 kèm đủ mã — tức API của họ bình thường, họ chỉ
lọc nguồn gọi.

Relay là một dịch vụ trung gian gọi hộ từ IP khác rồi trả nguyên response về.

## Điểm yếu phải biết

Mỗi relay đi ra bằng **đúng một IP cố định**. Đo thực tế trên relay Deno: 10 lần gọi liên tiếp đều
ra từ `144.202.54.204` (The Constant Company / Vultr). Nghĩa là một relay = một điểm chết, salesoc
chỉ cần chặn một địa chỉ.

Cách bù: dựng nhiều relay ở **nhiều nhà cung cấp khác nhau**. `SALESOC_RELAY_URL` nhận danh sách
ngăn cách bằng dấu phẩy, `SalesOcService` thử lần lượt và lấy cái đầu tiên trả dữ liệu dùng được:

```
SALESOC_RELAY_URL=https://a.deno.net,https://b.vercel.app/api/relay,https://c.netlify.app/relay
```

Khi một relay chết, log ghi `SalesOcService: đường 'relay:<host>' bị từ chối` và tính năng vẫn chạy
bằng relay sau — xem tại `/admin/logs`. Đó là lúc dựng relay thay thế, không phải lúc chữa cháy.

Muốn IP **thật sự xoay theo dải** thì phải dùng rotating residential proxy (trả phí) — cắm vào
`SALESOC_PROXY_URL`, không cần sửa code.

## Các bản hiện có

| Thư mục | Nền tảng | URL relay sau khi deploy |
|---------|----------|--------------------------|
| `deno-relay/` | Deno Deploy | `https://<app>.<org>.deno.net` |
| `vercel-relay/` | Vercel Edge Functions | `https://<project>.vercel.app/api/relay` |
| `netlify-relay/` | Netlify Functions | `https://<site>.netlify.app/relay` |
| `cloudflare-worker/` | Cloudflare Workers | **đã bị chặn**, giữ lại để tham khảo |

Hướng dẫn deploy từng cái nằm trong comment đầu file tương ứng. Điểm chung:

- **Root/Base directory** phải trỏ vào đúng thư mục relay, để root thì nền tảng tưởng đây là dự án
  Laravel/Vite và build sai.
- Biến môi trường `RELAY_SECRET` phải **giống hệt nhau ở mọi relay**, và khớp với
  `services.salesoc.relay_secret` trong `config/services.php`.
- Không cần install/build command — đều là một file chạy thẳng.

## Kiểm tra

```bash
php artisan salesoc:check "https://shopee.vn/...-i.<shop_id>.<item_id>"
```

In trạng thái từng đường (`relay:<host>`, `proxy`, `direct`) kèm HTTP status và thời gian.

Xem IP egress của một relay (dùng để biết nó đi ra bằng địa chỉ nào, và so sánh trước/sau khi bị
chặn) — `GET` trên chính URL relay, vẫn cần secret:

```bash
curl -H "X-Relay-Secret: <secret>" https://<relay-url>
```

## Thêm relay mới

1. Copy một trong các bản trên, sửa cho vừa vỏ nền tảng mới (logic giữ nguyên: kiểm tra secret →
   `GET` trả IP → `POST` gọi salesoc với header giả → trả nguyên response kèm status).
2. Deploy, đặt `RELAY_SECRET` giống các relay khác.
3. Nối URL vào `SALESOC_RELAY_URL` trong `config/services.php`, đặt trước `direct`.
4. Chạy `salesoc:check` xác nhận đường mới ✅.

Tránh Cloudflare Workers — đã bị chặn và không sửa được vì header `CF-Worker` do hạ tầng tự thêm.
