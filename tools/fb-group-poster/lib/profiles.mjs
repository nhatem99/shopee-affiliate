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
  if (!button) return fail(`Không thấy nút "Chuyển ngay" trên trang ${profile.url} — nick có còn quản trị page này không? ${await describe(page)}`);
  log(`Bấm nút "${await label(button)}".`);
  await button.click();

  // Facebook tải lại trang sau khi chuyển — chờ cookie i_user xuất hiện. Hiện hộp hỏi lại thì bấm
  // xác nhận (tối đa 2 lần: bấm hụt lúc hộp đang mở dần thì còn một lần nữa).
  const deadline = Date.now() + 30_000;
  let confirmed = 0;
  while (Date.now() < deadline) {
    await sleep(1000);
    ({ actorId } = await identity(context));
    if (actorId !== accountId) break;
    if (confirmed < 2) {
      const confirm = await confirmButton(page);
      if (confirm) {
        log(`Facebook hỏi xác nhận — bấm "${await label(confirm)}".`);
        await confirm.click().catch(() => {});
        confirmed++;
      }
    }
  }
  if (actorId === accountId) {
    return fail(`Đã bấm "Chuyển ngay"${confirmed ? ' và xác nhận' : ''} nhưng sau 30 giây Facebook vẫn để nick chính. ${await describe(page)}`);
  }
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

// Nút xác nhận trong hộp thoại đang mở, không có thì null. Trang đang tải lại thì coi như chưa có.
async function confirmButton(page) {
  try {
    const button = page.locator('[role="dialog"]').getByRole('button', { name: ui.SWITCH_CONFIRM }).first();
    return (await button.isVisible()) ? button : null;
  } catch {
    return null;
  }
}

async function label(locator) {
  try {
    const text = (await locator.getAttribute('aria-label', { timeout: 2_000 })) || (await locator.innerText({ timeout: 2_000 }));
    return text.replace(/\s+/g, ' ').trim().slice(0, 60);
  } catch {
    return '?';
  }
}

// Chuyển hỏng: trang đang hiện gì (hộp thoại, các nút có chữ chuyển/switch) — đi kèm lỗi lên server
// và in ra ở lệnh "switch", để sửa bộ chọn nút mà không phải đoán.
export async function describe(page) {
  try {
    const { dialogs, buttons } = await page.evaluate(() => {
      const visible = (el) => {
        const box = el.getBoundingClientRect();
        return box.width > 0 && box.height > 0;
      };
      const clean = (text) => (text || '').replace(/\s+/g, ' ').trim();
      return {
        dialogs: Array.from(document.querySelectorAll('[role="dialog"]'))
          .filter(visible)
          .map((dialog) => clean(dialog.innerText).slice(0, 160))
          .filter(Boolean)
          .slice(0, 2),
        buttons: [
          ...new Set(
            Array.from(document.querySelectorAll('[role="button"], button'))
              .filter(visible)
              .map((button) => clean(button.getAttribute('aria-label') || button.innerText).slice(0, 60))
              .filter((text) => /chuyển|switch/i.test(text)),
          ),
        ].slice(0, 8),
      };
    });
    return [
      `Trang: ${page.url().slice(0, 100)}`,
      dialogs.length ? `Hộp thoại: ${dialogs.map((text) => `"${text}"`).join(' | ')}` : 'Không có hộp thoại',
      buttons.length ? `Nút chuyển/switch: ${buttons.map((text) => `"${text}"`).join(', ')}` : 'Không có nút chuyển/switch',
    ].join('. ');
  } catch (error) {
    return `(Không đọc được trang: ${String(error.message).slice(0, 80)})`;
  }
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
