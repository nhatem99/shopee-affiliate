/**
 * Đăng nhập Facebook MỘT LẦN và lưu phiên vào .browser-profile/ để server.mjs dùng lại mãi.
 *
 * Chạy:  node login.mjs
 *
 * Mở ra một cửa sổ Chromium thật — tự tay đăng nhập trong đó (kể cả bước 2FA), rồi quay lại
 * terminal bấm Enter. Cookie nằm hết trong .browser-profile/.
 *
 * Máy chủ không có màn hình thì chạy lệnh này ở máy cá nhân rồi copy cả thư mục .browser-profile/
 * lên VPS (scp -r). Không có cách nào đăng nhập Facebook hoàn toàn không màn hình mà không đụng
 * tới mật khẩu/2FA trong script — và nhét mật khẩu vào script là thứ không nên làm.
 *
 * LƯU Ý: dừng server.mjs trước khi chạy, hai process không mở chung một thư mục hồ sơ được.
 * Thư mục này chứa PHIÊN ĐĂNG NHẬP THẬT — đừng commit, đừng để quyền đọc cho user khác.
 */

import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { createInterface } from 'node:readline/promises'
import { chromium } from 'playwright'

const PROFILE_DIR = join(dirname(fileURLToPath(import.meta.url)), '.browser-profile')

const context = await chromium.launchPersistentContext(PROFILE_DIR, {
  headless: false,
  locale: 'vi-VN',
  timezoneId: 'Asia/Ho_Chi_Minh',
  viewport: { width: 1280, height: 900 },
  args: ['--disable-blink-features=AutomationControlled'],
})

const page = context.pages()[0] ?? (await context.newPage())
await page.goto('https://www.facebook.com/')

console.log('Đăng nhập Facebook trong cửa sổ vừa mở, xong thì quay lại đây bấm Enter.')

const rl = createInterface({ input: process.stdin, output: process.stdout })
await rl.question('')
rl.close()

const cookies = await context.cookies('https://www.facebook.com')
const loggedIn = cookies.some((c) => c.name === 'c_user' && c.value)

console.log(loggedIn
  ? `Đã lưu phiên vào ${PROFILE_DIR}. Khởi động lại server.mjs là dùng được.`
  : 'CHƯA thấy cookie đăng nhập (c_user) — có vẻ chưa đăng nhập xong. Chạy lại lệnh này.')

await context.close()
process.exit(loggedIn ? 0 : 1)
