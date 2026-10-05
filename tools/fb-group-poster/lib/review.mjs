// Bài đã đăng có được admin nhóm duyệt không: mở các tab "Nội dung của bạn" của nhóm (đang chờ,
// đã đăng, bị từ chối, đã gỡ — server gửi đường dẫn, xem FacebookGroupReviewChecker::TABS) và lấy
// chữ của từng bài thấy được. Server tự dò bài nào của mình nằm ở tab nào; bot chỉ đọc, không bấm gì.
import { problem } from './browser.mjs';
import * as ui from './ui.mjs';

// Chỉ mở trang con của một nhóm — server có bị chiếm quyền cũng không điều được bot đi nơi khác.
export const TAB_URL = /^https:\/\/www\.facebook\.com\/groups\/[A-Za-z0-9][A-Za-z0-9._-]*\/[a-z_]+\/?$/;

// Cho lệnh "review" chạy tay — giữ giống FacebookGroupReviewChecker::TABS phía server.
export const DEFAULT_TABS = {
  pending: 'my_pending_content',
  published: 'my_posted_content',
  declined: 'my_declined_content',
  removed: 'my_removed_content',
};

// Giữ thân request nhỏ (nginx mặc định nhận 1 MB): vài chục bài mỗi tab là đủ cho 3 ngày gần nhất.
const MAX_ITEMS = 40;
const MAX_ITEM_TEXT = 3000;
const MAX_PAGE_TEXT = 20000;
const SCROLLS = 3;

const rand = (low, high) => Math.floor(low + Math.random() * (high - low + 1));

export function tabUrls(groupUrl) {
  const base = groupUrl.replace(/\/+$/, '');
  return Object.fromEntries(Object.entries(DEFAULT_TABS).map(([state, path]) => [state, `${base}/${path}/`]));
}

// Giống FacebookGroupReviewChecker::fingerprint() phía server — chỉ dùng cho lệnh "review --text".
export function fingerprint(text) {
  return String(text).normalize('NFC').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '');
}

// Kết quả từng tab: { ok, error, items: [{ text, url, page? }], issue? }. issue = 'checkpoint' |
// 'blocked' khi Facebook bắt xác minh/chặn — dừng luôn, không mở tab sau.
export async function scanTabs(page, tabs) {
  const result = {};
  for (const [state, url] of Object.entries(tabs)) {
    if (Object.values(result).some((tab) => tab.issue)) {
      result[state] = { ok: false, error: 'Bỏ qua — Facebook vừa bắt xác minh/chặn ở tab trước', items: [] };
      continue;
    }
    result[state] = await scanTab(page, url);
    await page.waitForTimeout(rand(1500, 3500));
  }
  return result;
}

async function scanTab(page, url) {
  const fail = (error, issue = null) => ({ ok: false, error, items: [], ...(issue ? { issue } : {}) });
  if (!TAB_URL.test(url)) return fail(`Đường dẫn không phải trang con của nhóm — bỏ qua: ${url.slice(0, 120)}`);

  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60_000 });
  } catch (error) {
    if (error.name === 'TimeoutError') return fail('Không tải được trang (quá 60 giây)');
    throw error;
  }
  await page.waitForTimeout(rand(3000, 5000));

  const issue = await problem(page);
  if (issue) return fail(issue[1], issue[0]);

  // Nhóm tên rút gọn có thể bị chuyển sang id số — chỉ cần còn đúng trang con (vd my_pending_content).
  const wanted = lastSegment(url);
  if (lastSegment(page.url()) !== wanted) return fail(`Facebook chuyển sang trang khác (${page.url().slice(0, 120)})`);

  const head = await page.evaluate(() => ((document.querySelector('[role="main"]') || document.body)?.innerText || '').slice(0, 3000));
  if (ui.UNAVAILABLE.test(head)) return fail('Facebook báo trang này không xem được');

  for (let i = 0; i < SCROLLS; i++) {
    await page.mouse.wheel(0, 3000);
    await page.waitForTimeout(rand(1500, 2500));
  }

  const data = await page.evaluate(
    ({ maxItems, maxItemText, maxPageText }) => {
      const main = document.querySelector('[role="main"]') || document.body;
      const postLink = /\/groups\/[^/]+\/(posts|permalink)\/\d+/;
      // Bình luận cũng là role=article nhưng nằm trong bài — chỉ lấy lớp ngoài cùng.
      const articles = Array.from(main.querySelectorAll('[role="article"]')).filter((a) => !a.parentElement?.closest('[role="article"]'));
      const items = articles.slice(0, maxItems).map((article) => {
        const href = Array.from(article.querySelectorAll('a[href]')).map((a) => a.href).find((h) => postLink.test(h));
        return { text: (article.innerText || '').slice(0, maxItemText), url: href ? href.split('?')[0] : null };
      });
      return { items, pageText: (main.innerText || '').slice(0, maxPageText) };
    },
    { maxItems: MAX_ITEMS, maxItemText: MAX_ITEM_TEXT, maxPageText: MAX_PAGE_TEXT },
  );

  // Gửi kèm cả khối chữ của trang (không link): Facebook đổi giao diện, không còn thẻ role=article
  // thì server vẫn dò được bài nằm ở tab nào.
  const items = data.items.filter((item) => item.text.trim());
  if (data.pageText.trim()) items.push({ text: data.pageText, url: null, page: true });
  return { ok: true, error: null, items };
}

function lastSegment(url) {
  try {
    return new URL(url).pathname.split('/').filter(Boolean).pop() ?? '';
  } catch {
    return '';
  }
}
