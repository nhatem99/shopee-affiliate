// Kết nối trình duyệt: nối vào Chromium đã mở sẵn (cdp, dùng trên điện thoại) hoặc tự mở một
// Chromium có hồ sơ riêng (máy nhà) — đăng nhập một lần, lần sau tự vào lại.
import { chromium } from 'playwright-core';
import fs from 'node:fs';
import * as ui from './ui.mjs';

// Chromium trên điện thoại không bị tắt giữa các lần chạy: khung soạn bài còn dở (dry-run, lỗi
// giữa chừng) có thể bật hộp "Rời trang?" — Playwright mặc định bấm "Ở lại" làm goto kẹt.
function leaveWithoutAsking(page) {
  page.on('dialog', (dialog) => (dialog.type() === 'beforeunload' ? dialog.accept() : dialog.dismiss()).catch(() => {}));
  return page;
}

export async function launch(cfg) {
  if (cfg.cdp) {
    const browser = await chromium.connectOverCDP(cfg.cdp, { slowMo: 60 });
    const context = browser.contexts()[0] ?? (await browser.newContext());
    const page = leaveWithoutAsking(context.pages()[0] ?? (await context.newPage()));
    // close() trên kết nối CDP chỉ ngắt kết nối, không tắt Chromium.
    return { context, page, close: () => browser.close() };
  }

  fs.mkdirSync(cfg.profileDir, { recursive: true });
  const context = await chromium.launchPersistentContext(cfg.profileDir, {
    channel: cfg.browserChannel || undefined,
    executablePath: cfg.executablePath || undefined,
    headless: false,
    locale: 'vi-VN',
    timezoneId: 'Asia/Ho_Chi_Minh',
    viewport: { width: 1280, height: 900 },
    slowMo: 60,
    args: ['--disable-blink-features=AutomationControlled'],
  });
  const page = leaveWithoutAsking(context.pages()[0] ?? (await context.newPage()));
  return { context, page, close: () => context.close() };
}

// uid Facebook của nick đang đăng nhập (cookie c_user) — không có là chưa đăng nhập.
export async function accountId(context) {
  const cookies = await context.cookies('https://www.facebook.com');
  return cookies.find((c) => c.name === 'c_user' && c.value)?.value ?? null;
}

export async function overlayText(page) {
  try {
    return (await page.locator(ui.OVERLAYS).allInnerTexts()).join('\n').slice(0, 20000);
  } catch {
    return '';
  }
}

export function around(text, match) {
  const start = Math.max(0, match.index - 60);
  return text.slice(start, match.index + match[0].length + 120).split(/\s+/).join(' ');
}

// Nick có đang bị Facebook chặn/đăng xuất không — trả [status, mô tả] theo kết quả bot báo về.
export async function problem(page) {
  const url = page.url();
  if (url.includes('/checkpoint')) return ['checkpoint', `Facebook bắt xác minh tài khoản (${url.slice(0, 120)})`];
  if (/facebook\.com\/(login|recover)/.test(url) || (await loginFormVisible(page))) {
    return ['checkpoint', 'Nick Facebook đã bị đăng xuất'];
  }
  const text = await overlayText(page);
  const match = ui.BLOCKED.exec(text);
  if (match) return ['blocked', `Facebook báo: ${around(text, match)}`];
  return null;
}

async function loginFormVisible(page) {
  try {
    return (await page.locator('input[name="email"]').first().isVisible()) && (await page.locator('input[name="pass"]').first().isVisible());
  } catch {
    return false;
  }
}
