/**
 * Relay salesoc.vn API call cho SalesOcService (xem app/Services/SalesOcService.php).
 *
 * VÌ SAO KHÔNG DÙNG CLOUDFLARE WORKER NỮA:
 * salesoc.vn nằm sau Cloudflare, và Cloudflare tự chèn header `CF-Worker` vào MỌI subrequest
 * phát ra từ Worker — không tắt được. Chỉ cần một rule nginx bắt header đó là chặn sạch mọi
 * Worker bất kể IP, đúng như đã xảy ra (403 Forbidden từ nginx của salesoc, xác nhận trong log
 * production ngày 2026-09-04). Chạy relay ngoài hạ tầng Cloudflare thì không có header này.
 *
 * DEPLOY (console.deno.com — bản console mới, deploy từ GitHub; bản dash.deno.com cũ có
 * Playground dán code trực tiếp thì console mới đã bỏ):
 *   0. Commit + push file này lên GitHub trước — Deno đọc code từ repo, không dán tay được.
 *   1. Settings → Environment Variables → Create a new Environment Variable:
 *        RELAY_SECRET = chuỗi ngẫu nhiên tự chọn (tạo bằng `openssl rand -hex 24`).
 *      Server chính dùng secret này để xác thực, tránh người lạ spam chiếm quota.
 *   2. Tab Apps → New App → chọn repo GitHub này, branch main.
 *   3. Entrypoint: deploy/deno-relay/main.ts — không cần install/build command.
 *   4. Deploy xong, copy URL app rồi điền vào .env của server chính:
 *        SALESOC_RELAY_URL=<url app>
 *        SALESOC_RELAY_SECRET=<secret ở bước 1>
 *      Nếu deploy pipeline có chạy `php artisan config:cache` thì phải chạy lại, không thì
 *      config cũ vẫn trỏ về relay chết.
 *   5. Kiểm tra: php artisan salesoc:check "<link shopee bất kỳ>"
 *
 * Code này là Web-standard (Request/Response + fetch) nên chạy được gần như nguyên vẹn trên
 * Vercel Edge / Netlify Edge / Bun nếu sau này Deno Deploy cũng bị chặn — chỉ đổi cách lấy
 * biến môi trường.
 */

const SALESOC_ENDPOINT = 'https://salesoc.vn/api/convert-with-shelf'

// Giả lập request từ mobile — giữ nguyên như SalesOcService để salesoc trả về cùng dữ liệu.
const MOBILE_USER_AGENT =
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'

// salesoc.vn kiểm tra Origin/Referer phía server, thiếu là 403 ORIGIN_NOT_ALLOWED.
const SPOOFED_ORIGIN = 'https://salesoc.vn'

Deno.serve(async (request: Request): Promise<Response> => {
  if (request.method !== 'POST') {
    return new Response('Method Not Allowed', { status: 405 })
  }

  const secret = Deno.env.get('RELAY_SECRET')

  if (!secret || request.headers.get('X-Relay-Secret') !== secret) {
    return new Response('Forbidden', { status: 403 })
  }

  let body: { url?: string }
  try {
    body = await request.json()
  } catch {
    return new Response('Bad Request', { status: 400 })
  }

  if (!body?.url) {
    return new Response('Bad Request', { status: 400 })
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
  })

  // Giữ nguyên status để server chính phân biệt được "salesoc từ chối" và "relay từ chối".
  return new Response(upstream.body, {
    status: upstream.status,
    headers: { 'Content-Type': 'application/json' },
  })
})
