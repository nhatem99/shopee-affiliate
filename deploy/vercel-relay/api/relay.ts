/**
 * Relay salesoc.vn cho SalesOcService — bản Vercel Edge Function.
 *
 * Logic giống hệt deploy/deno-relay/main.ts, chỉ khác vỏ của nền tảng. Lý do phải có nhiều bản:
 * mỗi relay đi ra bằng ĐÚNG MỘT IP cố định (đo được: relay Deno luôn ra từ 144.202.54.204), nên
 * một relay là một điểm chết — salesoc chỉ cần chặn một địa chỉ là tính năng lấy mã tắt. Dựng
 * relay ở nhiều nhà cung cấp khác nhau thì họ phải chặn lần lượt từng cái, và log của mình báo
 * ngay từ cái đầu tiên rụng.
 *
 * DEPLOY:
 *   1. vercel.com → Add New → Project → chọn repo này.
 *   2. Root Directory: deploy/vercel-relay (QUAN TRỌNG — để root thì Vercel tưởng đây là dự án
 *      Laravel/Vite và build sai). Framework Preset: Other. Không cần build command.
 *   3. Settings → Environment Variables → RELAY_SECRET = ĐÚNG chuỗi đang dùng cho relay Deno
 *      (xem config/services.php: services.salesoc.relay_secret). Mọi relay dùng chung một secret.
 *   4. Deploy xong, URL relay là: https://<project>.vercel.app/api/relay
 *      Nối vào SALESOC_RELAY_URL, ngăn cách bằng dấu phẩy với relay đang có.
 *   5. Kiểm tra: php artisan salesoc:check "<link shopee>"
 */

export const config = { runtime: 'edge' }

const SALESOC_ENDPOINT = 'https://salesoc.vn/api/convert-with-shelf'

// Giả lập request từ mobile — giữ nguyên như SalesOcService để salesoc trả về cùng dữ liệu.
const MOBILE_USER_AGENT =
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'

// salesoc.vn kiểm tra Origin/Referer phía server, thiếu là 403 ORIGIN_NOT_ALLOWED.
const SPOOFED_ORIGIN = 'https://salesoc.vn'

const IP_ECHO_ENDPOINT = 'https://api.ipify.org?format=json'

export default async function handler(request: Request): Promise<Response> {
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
