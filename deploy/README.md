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
ngăn cách bằng dấu phẩy (cấu hình mặc định nằm ở `config/services.php`, không cần `.env`):

```
SALESOC_RELAY_URL=https://a.deno.net,https://b.vercel.app/api/relay,https://c.netlify.app/relay
```

## Xoay vòng IP

`SalesOcService` **xoay vòng** danh sách trên theo từng lần quét:

| Lần quét | Relay đi ra |
|----------|-------------|
| link 1 | `a.deno.net` |
| link 2 | `b.vercel.app` |
| link 3 | `c.netlify.app` |
| link 4 | quay lại `a.deno.net` |

Lý do không phải "luôn ưu tiên relay đầu": làm vậy thì toàn bộ lưu lượng dồn vào một IP, salesoc
nhìn thấy một nguồn gọi dày bất thường rồi chặn — đúng kịch bản đã xảy ra với IP VPS và Cloudflare
Worker. Chia đều cho n relay thì mỗi IP chỉ gánh 1/n.

Con trỏ xoay vòng nằm ở cache (`salesoc:relay-cursor`, cache store production là `database`) nên
đếm đúng xuyên qua mọi request và queue worker. Cache hỏng thì chỉ mất phần rải tải, tính năng
vẫn chạy bằng relay đầu danh sách.

Xoay vòng **không** thay thế fallback: relay được chia cho lượt nào mà chết thì lượt đó tự rơi
sang relay kế tiếp trong vòng, rồi mới tới `proxy`/`direct`. Khi đó log ghi
`SalesOcService: đường 'relay:<host>' bị từ chối` — xem tại `/admin/logs`. Đó là lúc dựng relay
thay thế, không phải lúc chữa cháy.

Lưu ý: kết quả mỗi link được cache 15 phút, nên quét lại **cùng một link** không tốn lượt xoay và
không gọi ra ngoài.

Muốn IP **thật sự xoay theo dải** (mỗi request một IP khác trong hàng nghìn) thì phải dùng rotating
residential proxy (trả phí) — cắm vào `SALESOC_PROXY_URL`, không cần sửa code.

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
