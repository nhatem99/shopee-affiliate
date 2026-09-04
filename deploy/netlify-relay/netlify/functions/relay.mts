/**
 * Relay salesoc.vn cho SalesOcService — bản Netlify Function (v2, Web standard).
 *
 * Logic giống hệt deploy/deno-relay/main.ts và deploy/vercel-relay/api/relay.ts. Xem
 * deploy/README.md để hiểu vì sao cần nhiều relay ở nhiều nhà cung cấp.
 *
 * DEPLOY:
 *   1. app.netlify.com → Add new site → Import an existing project → chọn repo này.
 *   2. Base directory: deploy/netlify-relay (QUAN TRỌNG — để root thì Netlify build nhầm dự án
 *      Laravel/Vite). Build command để trống. Publish directory để trống.
 *   3. Site configuration → Environment variables → RELAY_SECRET = ĐÚNG chuỗi đang dùng cho các
 *      relay khác (xem config/services.php: services.salesoc.relay_secret).
 *   4. Deploy xong, URL relay là: https://<site>.netlify.app/relay
 *      Nối vào SALESOC_RELAY_URL, ngăn cách bằng dấu phẩy.
 *   5. Kiểm tra: php artisan salesoc:check "<link shopee>"
 */

const SALESOC_ENDPOINT = 'https://salesoc.vn/api/convert-with-shelf'

// Giả lập request từ mobile — giữ nguyên như SalesOcService để salesoc trả về cùng dữ liệu.
const MOBILE_USER_AGENT =
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'

// salesoc.vn kiểm tra Origin/Referer phía server, thiếu là 403 ORIGIN_NOT_ALLOWED.
const SPOOFED_ORIGIN = 'https://salesoc.vn'

const IP_ECHO_ENDPOINT = 'https://api.ipify.org?format=json'

export default async (request: Request): Promise<Response> => {
  const secret = process.env.RELAY_SECRET

  if (!secret || request.headers.get('X-Relay-Secret') !== secret) {
    return new Response('Forbidden', { status: 403 })
  }

  // GET — IP egress hiện tại, quy ước chung cho mọi relay.
  if (request.method === 'GET') {
    const echo = await fetch(IP_ECHO_ENDPOINT)

    return new Response(await echo.text(), {
      status: echo.status,
      headers: { 'Content-Type': 'application/json' },
    })
  }

  if (request.method !== 'POST') {
    return new Response('Method Not Allowed', { status: 405 })
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

  // Giữ nguyên status để server chính phân biệt "salesoc từ chối" với "relay từ chối".
  return new Response(upstream.body, {
    status: upstream.status,
    headers: { 'Content-Type': 'application/json' },
  })
}

// Cho phép gọi thẳng https://<site>.netlify.app/relay thay vì /.netlify/functions/relay.
export const config = { path: '/relay' }
