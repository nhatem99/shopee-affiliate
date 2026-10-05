// Chuyển bot sang đúng page trước khi đăng/xem nhóm (server giao "profile" kèm việc — xem
// FacebookGroupPostScheduler). Facebook giữ page đang dùng trong cookie i_user (uid page); không
// có i_user là đang dùng nick chính (c_user).
//
// Sang page: mở trang của page rồi bấm "Chuyển ngay", như người dùng làm. Về nick chính: bỏ
// cookie i_user rồi tải lại. Page → page thì về nick chính trước rồi mới sang page kia.
import { identity, problem } from './browser.mjs';
import * as ui from './ui.mjs';

// Chỉ mở trang page trên facebook.com — server có bị chiếm quyền cũng không điều được bot đi nơi khác.
// Giữ giống FacebookProfile::parseUrl() phía server.
export const PROFILE_URL = /^https:\/\/www\.facebook\.com\/(profile\.php\?id=\d{5,30}|[A-Za-z0-9.]{5,80})\/?$/;

const HOME = 'https://www.facebook.com/';
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const pause = (low, high) => sleep((low + Math.random() * (high - low)) * 1000);

// Link page bot nhận được từ lệnh "switch --to": id số, link profile.php hoặc tên rút gọn.
export function profileFromInput(input) {
  if (input === 'primary') return { primary: true, name: 'nick chính', url: null, fb_id: null };
  const url = /^\d{5,30}$/.test(input) ? `https://www.facebook.com/profile.php?id=${input}` : input;
  const id = /profile\.php\?id=(\d+)/.exec(url)?.[1] ?? null;
  return { primary: false, name: url, url, fb_id: id };
}

// Kết quả: { ok: true, actorId } hoặc { ok: false, error, status? }. Có status là lỗi không phải
// của page này: 'checkpoint'/'blocked' khi Facebook bắt xác minh/chặn, 'failed' khi trang không tải
// được (mạng) — báo đúng kết quả đó. Không status là không chuyển được sang page ('switch_failed').
export async function switchTo(browser, profile, { log = () => {} } = {}) {
  const { context, page } = browser;
  const fail = (error, status = null) => ({ ok: false, error, ...(status ? { status } : {}) });

  let { accountId, actorId } = await identity(context);
  if (!accountId) return fail('Nick Facebook đã bị đăng xuất', 'checkpoint');

  const target = profile.primary ? accountId : profile.fb_id;
  if (target && actorId === target) return { ok: true, actorId };

  if (actorId !== accountId) {
    log(`Đang ở page uid ${actorId} — về nick chính trước.`);
    await context.clearCookies({ name: 'i_user' });
    const issue = await open(page, HOME);
    if (issue) return fail(issue[1], issue[0]);
    ({ actorId } = await identity(context));
    if (actorId !== accountId) return fail(`Không về được nick chính (Facebook vẫn để page uid ${actorId})`);
  }
  if (profile.primary) return { ok: true, actorId };

  if (!PROFILE_URL.test(profile.url || '')) return fail(`Link page không đúng dạng facebook.com/... — bỏ qua cho an toàn: ${String(profile.url).slice(0, 120)}`);
  log(`Chuyển sang ${profile.name || profile.url}.`);
  const issue = await open(page, profile.url);
  if (issue) return fail(issue[1], issue[0]);

  const button = await findSwitchButton(page);
  if (!button) return fail(`Không thấy nút "Chuyển ngay" trên trang ${profile.url} — nick có còn quản trị page này không?`);
  await button.click();

  // Facebook tải lại trang sau khi chuyển — chờ cookie i_user xuất hiện.
  const deadline = Date.now() + 30_000;
  while (Date.now() < deadline) {
    await sleep(1000);
    ({ actorId } = await identity(context));
    if (actorId !== accountId) break;
  }
  if (actorId === accountId) return fail('Đã bấm "Chuyển ngay" nhưng sau 30 giây Facebook vẫn để nick chính');
  if (profile.fb_id && actorId !== profile.fb_id) return fail(`Chuyển nhầm sang uid ${actorId} (cần uid ${profile.fb_id})`);

  await pause(2, 4);
  return { ok: true, actorId };
}

async function open(page, url) {
  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60_000 });
  } catch (error) {
    if (error.name === 'TimeoutError') return ['failed', `Không tải được ${url} (quá 60 giây)`];
    throw error;
  }
  await pause(2.5, 4.5);
  return problem(page);
}

async function findSwitchButton(page) {
  const candidates = [
    page.getByRole('button', { name: ui.SWITCH_BUTTON }),
    page.locator('div[role="button"], a[role="button"]').filter({ hasText: ui.SWITCH_BUTTON }),
  ];
  for (const candidate of candidates) {
    try {
      const first = candidate.first();
      await first.waitFor({ state: 'visible', timeout: 8_000 });
      return first;
    } catch {
      /* thử cách tiếp theo */
    }
  }
  return null;
}
