// Lấy danh sách nhóm nick đã tham gia từ trang "Nhóm của bạn" (facebook.com/groups/joins/).
import { problem } from './browser.mjs';

const JOINS_URL = 'https://www.facebook.com/groups/joins/?nav_source=tab';

// Giống FacebookGroup::NOT_GROUP_KEYS phía server — trang của Facebook nằm dưới /groups/ nhưng không phải nhóm.
const NOT_GROUP_KEYS = new Set([
  'joins', 'feed', 'discover', 'create', 'search', 'notifications', 'your_groups',
  'categories', 'category', 'invites', 'browse', 'membership', 'manage', 'settings',
  'saved', 'you', 'pending', 'posts', 'permalink',
]);
const KEY_PATTERN = /^[a-z0-9][a-z0-9._-]{1,149}$/;

export function groupKey(url) {
  let parsed;
  try {
    parsed = new URL(url.includes('://') ? url : `https://${url}`);
  } catch {
    return null;
  }
  const host = parsed.hostname.toLowerCase();
  if (host !== 'facebook.com' && !host.endsWith('.facebook.com')) return null;
  const segments = parsed.pathname.split('/').filter(Boolean);
  if (segments.length < 2 || segments[0] !== 'groups') return null;
  let key;
  try {
    key = decodeURIComponent(segments[1]).toLowerCase();
  } catch {
    return null;
  }
  if (!KEY_PATTERN.test(key) || NOT_GROUP_KEYS.has(key)) return null;
  return key;
}

export class ScrapeError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

const rand = (low, high) => Math.floor(low + Math.random() * (high - low + 1));

export async function scrapeJoinedGroups(page, { maxGroups = 2000, log = console.log } = {}) {
  await page.goto(JOINS_URL, { waitUntil: 'domcontentloaded', timeout: 60_000 });
  await page.waitForTimeout(rand(3000, 5000));
  const issue = await problem(page);
  if (issue) throw new ScrapeError(issue[0], issue[1]);

  const found = new Map();
  let unchanged = 0;
  while (unchanged < 3 && found.size < maxGroups) {
    const before = found.size;
    const links = await page.evaluate(() =>
      Array.from(document.querySelectorAll('a[href*="/groups/"]')).map((a) => ({
        href: a.href,
        text: (a.innerText || a.getAttribute('aria-label') || '').trim(),
      })),
    );
    for (const link of links) {
      const key = groupKey(link.href);
      if (!key) continue;
      const name = link.text.split('\n').map((l) => l.trim()).find(Boolean) ?? null;
      if (!found.has(key)) found.set(key, { url: `https://www.facebook.com/groups/${key}/`, name: null });
      // Link đầu tiên của một nhóm có khi chỉ là ảnh đại diện (không có chữ) — lấy tên ở link sau.
      const entry = found.get(key);
      if (!entry.name && name) entry.name = name.slice(0, 255);
    }
    unchanged = found.size === before ? unchanged + 1 : 0;
    await page.mouse.wheel(0, 4000);
    await page.waitForTimeout(rand(1500, 2500));
  }

  log(`Tìm thấy ${found.size} nhóm.`);
  return [...found.values()];
}
