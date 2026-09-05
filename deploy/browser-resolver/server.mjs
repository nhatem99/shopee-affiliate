/**
 * Browser resolver — mở một bài đăng Facebook/Instagram bằng Chromium thật, bấm vào link của
 * mình trong đó, rồi trả về URL đích cuối cùng.
 *
 * VÌ SAO PHẢI LÀ TRÌNH DUYỆT THẬT (không phải curl đi theo redirect):
 *   1. Trang comment của Facebook chỉ xem được khi đã đăng nhập — service này giữ sẵn phiên.
 *   2. Shopee chỉ đúc link CÓ MÃ khi cú bấm đến từ trong ngữ cảnh nền tảng. Đi theo chuỗi
 *      redirect từ server là mất đúng ngữ cảnh đó, và mất luôn cái mã — tức là mất toàn bộ
 *      lý do tồn tại của cơ chế này.
 *
 * DEPLOY (trên chính VPS chạy Laravel):
 *   1. cd deploy/browser-resolver && npm install && npx playwright install --with-deps chromium
 *      (Node >= 18. --with-deps cần sudo để cài thư viện hệ thống cho Chromium.)
 *   2. Đăng nhập Facebook MỘT LẦN, lưu phiên vào ổ đĩa:
 *        node login.mjs
 *      Máy chủ không có màn hình thì chạy lệnh này ở máy cá nhân rồi copy cả thư mục
 *      .browser-profile/ lên VPS — phiên nằm hết trong đó.
 *   3. Chạy thường trực:
 *        RESOLVER_SECRET=<đúng chuỗi trong config/services.php> pm2 start server.mjs --name browser-resolver
 *   4. Kiểm tra từ Laravel: php artisan voucher:mint-check "<link shopee>"
 *
 * BẢO MẬT: chỉ nghe 127.0.0.1 nên không mở ra Internet. Ngay cả vậy vẫn bắt buộc header
 * X-Resolver-Secret, vì mọi process trên cùng VPS đều gọi được localhost.
 */

import { createServer } from 'node:http'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { chromium } from 'playwright'

const PORT = Number(process.env.PORT || 8787)
const HOST = process.env.HOST || '127.0.0.1'
const SECRET = process.env.RESOLVER_SECRET || ''
const PROFILE_DIR = join(dirname(fileURLToPath(import.meta.url)), '.browser-profile')

// Chromium mặc định lộ rõ là máy tự động (thiếu ngôn ngữ, múi giờ lạ, cửa sổ bé). Đặt cho giống
// một máy thật ở Việt Nam để Facebook không đẩy vào luồng kiểm tra bảo mật.
const CONTEXT_OPTIONS = {
  headless: process.env.HEADLESS !== 'false',
  locale: 'vi-VN',
  timezoneId: 'Asia/Ho_Chi_Minh',
  viewport: { width: 1280, height: 900 },
  args: ['--disable-blink-features=AutomationControlled'],
}

let context = null

async function browserContext() {
  if (context) return context

  // Persistent context: cookie phiên Facebook nằm trên ổ đĩa, không phải nạp lại sau mỗi lần
  // khởi động. Đổi lại, CHỈ MỘT process được mở cùng thư mục — dừng service trước khi chạy login.mjs.
  context = await chromium.launchPersistentContext(PROFILE_DIR, CONTEXT_OPTIONS)
  context.on('close', () => { context = null })

  return context
}

async function isLoggedIn() {
  const ctx = await browserContext()
  const cookies = await ctx.cookies('https://www.facebook.com')

  // c_user chỉ tồn tại khi đã đăng nhập — rẻ hơn nhiều so với mở thật một trang để kiểm tra.
  return cookies.some((c) => c.name === 'c_user' && c.value)
}

/**
 * Mở trang, tìm link chứa marker, bấm vào, đợi tới URL cuối.
 *
 * marker là chuỗi duy nhất của lượt đúc này (nằm trong utm_term của link) — bài đăng dùng chung
 * cho mọi khách nên lúc nào cũng có comment của lượt khác; không có marker thì bấm nhầm link
 * của người khác và trả về mã của sản phẩm khác.
 */
async function resolveOutbound({ url, marker, timeoutMs }) {
  const ctx = await browserContext()
  const page = await ctx.newPage()
  const deadline = Date.now() + timeoutMs

  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: timeoutMs })

    const href = await waitForMarkedHref(page, marker, deadline)
    if (!href) {
      return { error: `Không thấy link chứa marker "${marker}" trong trang sau ${timeoutMs}ms` }
    }

    const link = page.locator(`a[href="${cssEscape(href)}"]`).first()

    // Link trong bài đăng Facebook thường mở tab mới (target=_blank). Bắt sự kiện 'page' song song
    // với cú bấm, nếu không có tab mới thì điều hướng xảy ra ngay trên trang hiện tại.
    const [popup] = await Promise.all([
      ctx.waitForEvent('page', { timeout: 15_000 }).catch(() => null),
      link.click({ timeout: 10_000 }),
    ])

    const target = popup ?? page
    await target.waitForLoadState('domcontentloaded').catch(() => {})

    // Shopee còn chuyển hướng thêm vài nhịp (kể cả bằng JS) trước khi dừng ở link đã đúc mã.
    // Đợi tới khi URL đứng yên, hoặc tới khi hết giờ — lấy được gì trả nấy, còn hơn ném lỗi.
    const finalUrl = await waitForSettledUrl(target, deadline)

    if (popup) await popup.close().catch(() => {})

    return { final_url: finalUrl, matched_href: href }
  } finally {
    await page.close().catch(() => {})
  }
}

async function waitForMarkedHref(page, marker, deadline) {
  while (Date.now() < deadline) {
    // $$eval là API truy vấn DOM của Playwright (không phải eval() của JavaScript): hàm bên dưới
    // được Playwright serialize rồi chạy trong trang, không nhận chuỗi nào từ phía người dùng.
    const hrefs = await page.$$eval('a[href]', (nodes) => nodes.map((n) => n.getAttribute('href')))

    // Facebook bọc link ra ngoài thành l.facebook.com/l.php?u=<đã mã hoá> — marker vẫn nằm trong
    // đó nhưng ở dạng percent-encoded, nên phải so trên cả bản thô lẫn bản đã giải mã.
    const match = hrefs.find((h) => h && (h.includes(marker) || safeDecode(h).includes(marker)))
    if (match) return match

    await page.waitForTimeout(1000)
  }

  return null
}

async function waitForSettledUrl(page, deadline) {
  let last = page.url()
  let stableFor = 0

  while (Date.now() < deadline) {
    await page.waitForTimeout(500)
    const current = page.url()

    if (current === last) {
      stableFor += 500
      // Đứng yên 2s và đã tới shopee.vn thì coi như xong; chưa tới Shopee thì kiên nhẫn thêm.
      if (stableFor >= 2000 && current.includes('shopee')) return current
    } else {
      last = current
      stableFor = 0
    }
  }

  return page.url()
}

function safeDecode(value) {
  try {
    return decodeURIComponent(value)
  } catch {
    return value
  }
}

// href thật hay chứa dấu " và \ — phải thoát trước khi nhét vào selector thuộc tính.
function cssEscape(value) {
  return value.replace(/\\/g, '\\\\').replace(/"/g, '\\"')
}

// Chromium chịu tải kém khi mở nhiều trang cùng lúc, và bắn liên tiếp nhiều cú bấm từ một phiên
// cũng là tín hiệu tự động hoá rõ nhất với Facebook. Xếp hàng: mỗi lúc chỉ chạy một yêu cầu.
let queue = Promise.resolve()

function enqueue(task) {
  const result = queue.then(task, task)
  queue = result.catch(() => {})

  return result
}

function readJson(req) {
  return new Promise((resolve, reject) => {
    let body = ''
    req.on('data', (chunk) => {
      body += chunk
      if (body.length > 1e6) reject(new Error('Body quá lớn'))
    })
    req.on('end', () => {
      try {
        resolve(JSON.parse(body || '{}'))
      } catch (e) {
        reject(e)
      }
    })
    req.on('error', reject)
  })
}

function send(res, status, payload) {
  const body = JSON.stringify(payload)
  res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) })
  res.end(body)
}

const server = createServer(async (req, res) => {
  if (!SECRET || req.headers['x-resolver-secret'] !== SECRET) {
    return send(res, 403, { error: 'Forbidden' })
  }

  try {
    if (req.method === 'GET' && req.url.startsWith('/health')) {
      const loggedIn = await isLoggedIn()

      return send(res, 200, {
        logged_in: loggedIn,
        detail: loggedIn ? 'Phiên Facebook còn hiệu lực' : 'Chưa đăng nhập — chạy node login.mjs',
      })
    }

    if (req.method === 'POST' && req.url.startsWith('/resolve')) {
      const { url, marker, timeout_ms: timeoutMs = 60_000 } = await readJson(req)

      if (!url || !marker) {
        return send(res, 400, { error: 'Thiếu url hoặc marker' })
      }

      const startedAt = Date.now()
      const result = await enqueue(() => resolveOutbound({ url, marker, timeoutMs }))

      if (result.error) {
        return send(res, 422, { ...result, duration_ms: Date.now() - startedAt })
      }

      return send(res, 200, { ...result, duration_ms: Date.now() - startedAt })
    }

    return send(res, 404, { error: 'Not Found' })
  } catch (e) {
    console.error('[browser-resolver]', e)

    return send(res, 500, { error: e.message })
  }
})

server.listen(PORT, HOST, () => {
  console.log(`[browser-resolver] nghe tại http://${HOST}:${PORT}, hồ sơ trình duyệt: ${PROFILE_DIR}`)
  if (!SECRET) console.warn('[browser-resolver] CHƯA đặt RESOLVER_SECRET — mọi yêu cầu sẽ bị từ chối.')
})

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, async () => {
    await context?.close().catch(() => {})
    server.close(() => process.exit(0))
  })
}
