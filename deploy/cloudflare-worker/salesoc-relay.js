/**
 * Relay salesoc.vn API call cho SalesOcService (xem app/Services/SalesOcService.php).
 *
 * Vì sao cần: salesoc.vn chặn 403 thẳng ở nginx theo IP thật của VPS server chính.
 * Worker này chạy trên hạ tầng Cloudflare (IP khác, không bị chặn) — server chính
 * gọi vào Worker, Worker gọi hộ salesoc.vn rồi trả nguyên JSON response về.
 *
 * DEPLOY (dashboard, không cần cài wrangler):
 *   1. workers.cloudflare.com → Create Worker → dán toàn bộ nội dung file này vào,
 *      chọn "Deploy".
 *   2. Settings → Variables and Secrets → thêm secret RELAY_SECRET = 1 chuỗi ngẫu
 *      nhiên tự chọn (dùng để server chính xác thực với Worker, tránh người lạ
 *      spam Worker chiếm quota free tier).
 *   3. Copy URL Worker (dạng https://<tên>.<subdomain>.workers.dev) và secret vừa
 *      tạo, điền vào .env của server chính:
 *        SALESOC_RELAY_URL=https://<tên>.<subdomain>.workers.dev
 *        SALESOC_RELAY_SECRET=<secret đã tạo>
 *
 * Không cần đổi gì thêm — SalesOcService tự chuyển sang gọi qua Worker này khi
 * SALESOC_RELAY_URL có giá trị (ưu tiên hơn SALESOC_PROXY_URL nếu cả 2 cùng set).
 */

const SALESOC_ENDPOINT = 'https://salesoc.vn/api/convert-with-shelf';

const MOBILE_USER_AGENT =
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

const SPOOFED_ORIGIN = 'https://salesoc.vn';

export default {
  async fetch(request, env) {
    if (request.method !== 'POST') {
      return new Response('Method Not Allowed', { status: 405 });
    }

    if (!env.RELAY_SECRET || request.headers.get('X-Relay-Secret') !== env.RELAY_SECRET) {
      return new Response('Forbidden', { status: 403 });
    }

    let body;
    try {
      body = await request.json();
    } catch {
      return new Response('Bad Request', { status: 400 });
    }

    if (!body?.url) {
      return new Response('Bad Request', { status: 400 });
    }

    const upstream = await fetch(SALESOC_ENDPOINT, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'User-Agent': MOBILE_USER_AGENT,
        Origin: SPOOFED_ORIGIN,
        Referer: `${SPOOFED_ORIGIN}/`,
      },
      body: JSON.stringify({ url: body.url }),
    });

    return new Response(upstream.body, {
      status: upstream.status,
      headers: { 'Content-Type': 'application/json' },
    });
  },
};
