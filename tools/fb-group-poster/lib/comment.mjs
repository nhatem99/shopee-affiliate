// Bình luận link mua vào bài đã đăng (việc "comment" — FacebookGroupCommentQueue phía server). Bài
// "link ở bình luận" lên nhóm không có link; bot vào bài bình luận khối link.
//
// Tìm bài: link bài server gửi (bắt được lúc đăng, hoặc lượt kiểm tra duyệt bài thấy); không có thì
// dò đoạn đầu nội dung ở "Nội dung của bạn" → Đã đăng, rồi bảng tin nhóm xếp bài mới trước.
// Trước khi gõ luôn kiểm tra trên trang có đúng bài của mình — không bao giờ bình luận vào bài khác.
//
// Kết quả: commented / ambiguous (đã gửi, không xác nhận được) / not_found / failed (chưa gửi gì) /
// blocked / checkpoint.
import { around, overlayText, problem } from './browser.mjs';
import { GROUP_URL } from './post.mjs';
import { TAB_URL, fingerprint } from './review.mjs';
import * as ui from './ui.mjs';

// Link bài trong nhóm (giữ giống FacebookGroupReviewChecker::postUrl phía server).
const POST_PATH = /^\/groups\/([A-Za-z0-9][A-Za-z0-9._-]*)\/(posts|permalink)\/(\d+)\/?$/;
// Giống FacebookGroupReviewChecker::SNIPPET_LENGTHS: đoạn dài trước, ngắn dần vì "Xem thêm".
const SNIPPET_LENGTHS = [80, 50, 30];
const MAX_COMMENT = 2000;
// Thẻ bài tìm thấy ở bảng tin/tab — đánh dấu để Playwright tìm lại đúng thẻ đó.
const MARK = 'data-tkv-post';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const pause = (low = 0.6, high = 1.6) => sleep((low + Math.random() * (high - low)) * 1000);
const result = (status, error = null, postUrl = null) => ({ status, error, postUrl });

// Chỉ mở link bài trên facebook.com (m./web. đổi về www.), bỏ phần ?... — null nếu không phải.
export function postUrl(url) {
  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    return null;
  }
  const match = POST_PATH.exec(parsed.pathname);
  if (parsed.protocol !== 'https:' || !/^(www|m|web)\.facebook\.com$/.test(parsed.hostname) || !match) return null;
  return `https://www.facebook.com/groups/${match[1]}/${match[2]}/${match[3]}/`;
}

export function snippets(caption) {
  const print = fingerprint(caption || '');
  if (print.length < 10) return [];
  return [...new Set(SNIPPET_LENGTHS.map((length) => [...print].slice(0, length).join('')))];
}

// Bảng tin nhóm, bài mới đăng đứng đầu.
export function feedUrl(groupUrl) {
  return GROUP_URL.test(groupUrl) ? `${groupUrl.replace(/\/+$/, '')}/?sorting_setting=CHRONOLOGICAL` : null;
}

// Chữ đánh dấu bình luận của mình (link mua): Facebook có thể cắt link dài thành "…" nên chỉ cần
// đoạn đầu.
function markerProbe(marker) {
  return marker ? marker.slice(0, 40) : null;
}

// job: { group_url, post_url?, caption, comment, marker?, search?: [link trang con của nhóm] }
export async function commentOnPost(page, job, { dryRun = false, onSubmitting = null, log = () => {} } = {}) {
  const text = String(job.comment || '').trim();
  if (!text || text.length > MAX_COMMENT) return result('failed', 'Nội dung bình luận rỗng hoặc quá dài');
  const wanted = snippets(job.caption);
  if (!wanted.length && !dryRun) return result('failed', 'Thiếu nội dung bài để nhận ra bài của mình — không bình luận cho an toàn');

  let found = null;
  const direct = job.post_url ? postUrl(job.post_url) : null;
  if (direct) {
    const opened = await openPost(page, direct, wanted);
    if (opened.issue) return result(...opened.issue);
    if (opened.ok) found = { url: direct, scope: null };
    else log(`Mở link bài không thấy bài của mình (${opened.error}) — tìm bài trong nhóm.`);
  }

  if (!found) {
    const places = [...(job.search || []).filter((url) => TAB_URL.test(url)), feedUrl(job.group_url)].filter(Boolean);
    for (const place of places) {
      const hit = await searchPage(page, place, wanted);
      if (hit.issue) return result(...hit.issue);
      if (!hit.found) continue;
      if (hit.url) {
        log(`Thấy bài: ${hit.url}`);
        const opened = await openPost(page, hit.url, wanted);
        if (opened.issue) return result(...opened.issue);
        if (opened.ok) {
          found = { url: hit.url, scope: null };
          break;
        }
        // Mở link không ra bài — quay lại trang đã thấy bài, bình luận ngay tại đó.
        const again = await searchPage(page, place, wanted);
        if (again.issue) return result(...again.issue);
        if (!again.found) continue;
      }
      log('Bình luận ngay dưới bài ở trang vừa tìm thấy.');
      found = { url: hit.url, scope: page.locator(`[${MARK}]`).first() };
      break;
    }
  }
  if (!found) return result('not_found', 'Không tìm thấy bài trên nhóm — có thể đang chờ duyệt hoặc đã bị gỡ');

  // Ở bảng tin chỉ xét trong thẻ bài: bài cũ cùng sản phẩm có thể mang đúng link này.
  const scoped = found.scope !== null;
  const probe = markerProbe(job.marker);
  if (probe && (await hasText(page, probe, scoped))) return result('commented', 'Bài đã có bình luận link từ trước', found.url);

  const box = await commentBox(page, found.scope);
  if (!box) return result('failed', `Không thấy ô bình luận dưới bài${await approvalHint(page)}`, found.url);

  await box.click();
  await pause(0.6, 1.2);
  await typeLines(page, text);
  await pause(1.5, 3.0);
  if (dryRun) return result('dry_run', null, found.url);

  // ── Từ đây bình luận có thể đã lên ──
  if (onSubmitting) onSubmitting();
  await page.keyboard.press('Enter');
  return confirm(page, box, probe ?? lastLine(text), scoped, found.url);
}

// Mỗi dòng một lần: Enter trong ô bình luận là gửi luôn, xuống dòng phải Shift+Enter.
async function typeLines(page, text) {
  const lines = text.split(/\r?\n/);
  for (let i = 0; i < lines.length; i++) {
    if (i > 0) await page.keyboard.press('Shift+Enter');
    if (lines[i]) await page.keyboard.insertText(lines[i]);
    await sleep(150 + Math.random() * 250);
  }
}

function lastLine(text) {
  return text.split(/\r?\n/).filter((line) => line.trim()).pop().trim().slice(0, 40);
}

async function goto(page, url) {
  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60_000 });
  } catch (error) {
    if (error.name === 'TimeoutError') return ['failed', 'Không tải được trang (quá 60 giây)'];
    throw error;
  }
  await pause(3.0, 5.0);
  return problem(page);
}

// Mở link bài, xác nhận trên trang có đúng bài của mình.
async function openPost(page, url, wanted) {
  const issue = await goto(page, url);
  if (issue) return { issue };
  const text = await page.evaluate(() => (document.querySelector('[role="main"]') || document.body)?.innerText || '');
  if (ui.UNAVAILABLE.test(text.slice(0, 3000))) return { ok: false, error: 'Facebook báo bài không xem được' };
  if (wanted.length && !wanted.some((snippet) => fingerprint(text).includes(snippet))) {
    return { ok: false, error: 'trang không có nội dung bài' };
  }
  return { ok: true };
}

// Dò bài ở một trang (tab "Đã đăng", bảng tin nhóm). Thấy thì đánh dấu thẻ bài (data-tkv-post) và
// tìm link bài — Facebook chỉ điền link vào mốc giờ khi rê chuột qua, nên rê qua các link đầu thẻ.
async function searchPage(page, url, wanted) {
  const issue = await goto(page, url);
  if (issue) return { issue };
  for (let i = 0; i < 2; i++) {
    await page.mouse.wheel(0, 2000);
    await pause(1.5, 2.5);
  }

  const found = await page.evaluate(({ wanted, mark }) => {
    const print = (text) => String(text).normalize('NFC').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '');
    const main = document.querySelector('[role="main"]') || document.body;
    // Bình luận cũng là role=article nhưng nằm trong bài — chỉ lấy lớp ngoài cùng.
    const articles = Array.from(main.querySelectorAll('[role="article"]')).filter((a) => !a.parentElement?.closest('[role="article"]'));
    document.querySelectorAll(`[${mark}]`).forEach((el) => el.removeAttribute(mark));
    for (const snippet of wanted) {
      const article = articles.find((a) => print(a.innerText).includes(snippet));
      if (article) {
        article.setAttribute(mark, '1');
        return true;
      }
    }
    return false;
  }, { wanted, mark: MARK });
  if (!found) return { found: false };

  const article = page.locator(`[${MARK}]`).first();
  let link = await articleLink(article);
  if (!link) {
    const anchors = article.locator('a[role="link"]');
    const count = Math.min(await anchors.count(), 8);
    for (let i = 0; i < count && !link; i++) {
      try {
        await anchors.nth(i).hover({ timeout: 2_000 });
        await sleep(300);
      } catch {
        /* link bị che/khuất — thử link sau */
      }
      link = await articleLink(article);
    }
  }
  return { found: true, url: link };
}

async function articleLink(article) {
  const hrefs = await article.locator('a[href]').evaluateAll((anchors) => anchors.map((a) => a.href));
  for (const href of hrefs) {
    const url = postUrl(href.split('?')[0]);
    if (url) return url;
  }
  return null;
}

// Ô bình luận của bài: scope = thẻ bài (bảng tin/tab) hoặc null (trang riêng của bài). Chưa có ô
// thì bấm nút "Bình luận" — Facebook có thể mở bài trong hộp thoại, khi đó ô nằm trong hộp thoại.
async function commentBox(page, scope) {
  const where = scope ?? page;
  const box = await visibleBox(where);
  if (box) return box;

  const button = where.getByRole('button', { name: ui.COMMENT_BUTTON });
  try {
    if (!(await button.count())) return null;
    await button.first().click({ timeout: 5_000 });
    await pause(2.0, 3.5);
  } catch {
    return null;
  }
  return (await visibleBox(page.locator('[role="dialog"]').last())) ?? (await visibleBox(where));
}

async function visibleBox(where) {
  try {
    const boxes = where.locator('div[role="textbox"][contenteditable="true"]');
    const count = await boxes.count();
    for (let i = 0; i < count; i++) {
      const box = boxes.nth(i);
      if (ui.COMMENT_BOX.test((await box.getAttribute('aria-label')) || '') && (await box.isVisible())) return box;
    }
  } catch {
    /* hộp thoại vừa đóng */
  }
  return null;
}

// Có chữ đó ngoài các ô đang gõ — kể cả trong link (Facebook bọc link ngoài bằng l.php?u=...).
// scoped: chỉ xét thẻ bài đã đánh dấu và hộp thoại bài (mở ra khi bấm "Bình luận").
async function hasText(page, probe, scoped = false) {
  return page.evaluate(({ probe, scoped, mark }) => {
    const roots = scoped ? [document.querySelector(`[${mark}]`), ...document.querySelectorAll('[role="dialog"]')].filter(Boolean) : [document.body];
    return roots.some((root) => {
      const typing = Array.from(root.querySelectorAll('[contenteditable="true"]')).map((el) => el.innerText).filter(Boolean);
      let text = root.innerText || '';
      for (const part of typing) text = text.replace(part, '');
      if (text.includes(probe)) return true;
      return Array.from(root.querySelectorAll('a[href]')).some((a) => {
        try {
          return decodeURIComponent(a.href).includes(probe);
        } catch {
          return false;
        }
      });
    });
  }, { probe, scoped, mark: MARK });
}

async function approvalHint(page) {
  const text = await page.evaluate(() => (document.querySelector('[role="main"]') || document.body)?.innerText?.slice(0, 5000) || '');
  return ui.APPROVAL.test(text) ? ' (bài còn chờ admin nhóm duyệt)' : '';
}

async function confirm(page, box, probe, scoped, url, timeoutSeconds = 30) {
  const deadline = Date.now() + timeoutSeconds * 1000;
  let emptied = false;
  while (Date.now() < deadline) {
    await sleep(1000);
    const text = await overlayText(page);
    const blocked = ui.BLOCKED.exec(text);
    if (blocked) return result('blocked', `Facebook báo: ${around(text, blocked)}`, url);
    const rejected = ui.COMMENT_REJECTED.exec(text);
    if (rejected) return result('failed', `Facebook không nhận bình luận: ${around(text, rejected)}`, url);

    try {
      emptied = !(await box.innerText()).trim();
    } catch {
      emptied = true; // ô biến mất cùng hộp thoại sau khi gửi
    }
    if (emptied && (await hasText(page, probe, scoped))) return result('commented', null, url);
  }
  return emptied
    ? result('ambiguous', `Đã gửi bình luận nhưng sau ${timeoutSeconds} giây chưa thấy hiện ra`, url)
    : result('failed', 'Bấm Enter nhưng bình luận vẫn nằm trong ô, chưa gửi đi', url);
}
