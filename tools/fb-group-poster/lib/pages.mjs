// Lấy danh sách page nick đang quản lý từ trang "Trang của bạn" (việc "sync_pages" — FacebookPageListSync
// phía server). Phải đang dùng nick chính: ở dưới tên một page thì Facebook liệt kê thứ khác.
import { problem } from './browser.mjs';
import { ScrapeError } from './groups.mjs';

const PAGES_URL = 'https://www.facebook.com/pages/?category=your_pages';

// Đường dẫn một đoạn của chính Facebook, không phải tên rút gọn của page.
const NOT_PAGES = new Set([
  'groups', 'pages', 'watch', 'marketplace', 'gaming', 'events', 'home.php', 'login', 'friends',
  'messages', 'notifications', 'bookmarks', 'settings', 'help', 'policies', 'privacy', 'ads',
  'business', 'reel', 'reels', 'stories', 'photo', 'photo.php', 'photos', 'hashtag', 'saved',
  'memories', 'fundraisers', 'live', 'search', 'me', 'latest', 'feeds', 'ad_center', 'creatorstudio',
]);

const rand = (low, high) => Math.floor(low + Math.random() * (high - low + 1));

// Link tới trang của một page: profile.php?id=<uid>, /people/<tên>/<uid>/, hoặc /<tên rút gọn>.
// Trả { url, fb_id } (giống FacebookProfile::parseUrl phía server), không phải link page thì null.
export function pageLink(href) {
  let url;
  try {
    url = new URL(href);
  } catch {
    return null;
  }
  if (url.protocol !== 'https:' || !/^(www\.|m\.|web\.)?facebook\.com$/.test(url.hostname)) return null;
  const segments = url.pathname.split('/').filter(Boolean);
  const byId = (id) => ({ url: `https://www.facebook.com/profile.php?id=${id}`, fb_id: id });

  if (url.pathname === '/profile.php') {
    const id = url.searchParams.get('id') || '';
    return /^\d{5,30}$/.test(id) ? byId(id) : null;
  }
  if (segments[0] === 'people' && /^\d{5,30}$/.test(segments[2] || '')) return byId(segments[2]);
  if (segments.length === 1 && /^[A-Za-z0-9.]{5,80}$/.test(segments[0]) && !NOT_PAGES.has(segments[0].toLowerCase())) {
    return { url: `https://www.facebook.com/${segments[0]}`, fb_id: null };
  }
  return null;
}

// exclude: uid không tính là page (nick chính).
export async function scrapeManagedPages(page, { exclude = [], maxPages = 300, log = console.log } = {}) {
  await page.goto(PAGES_URL, { waitUntil: 'domcontentloaded', timeout: 60_000 });
  await page.waitForTimeout(rand(3000, 5000));
  const issue = await problem(page);
  if (issue) throw new ScrapeError(issue[0], issue[1]);

  const skip = new Set(exclude.filter(Boolean));
  const found = new Map();
  let unchanged = 0;
  while (unchanged < 3 && found.size < maxPages) {
    const before = found.size;
    // Chỉ trong khung chính — thanh trên và cột trái có link trang cá nhân của nick.
    const links = await page.evaluate(() =>
      Array.from((document.querySelector('[role="main"]') || document.body).querySelectorAll('a[href]')).map((a) => ({
        href: a.href,
        text: (a.innerText || a.getAttribute('aria-label') || '').trim(),
      })),
    );
    for (const link of links) {
      const parsed = pageLink(link.href);
      if (!parsed || skip.has(parsed.fb_id)) continue;
      const key = parsed.fb_id || parsed.url.toLowerCase();
      if (!found.has(key)) found.set(key, { url: parsed.url, name: null });
      // Link đầu tiên của một page có khi chỉ là ảnh đại diện (không có chữ) — lấy tên ở link sau.
      const entry = found.get(key);
      const name = link.text.split('\n').map((line) => line.trim()).find(Boolean);
      if (!entry.name && name) entry.name = name.slice(0, 255);
    }
    unchanged = found.size === before ? unchanged + 1 : 0;
    await page.mouse.wheel(0, 3000);
    await page.waitForTimeout(rand(1500, 2500));
  }

  log(`Tìm thấy ${found.size} page.`);
  return [...found.values()];
}
