// Bình luận dạo trong nhóm follow chéo (việc "engage" — FacebookEngageQueue phía server). Mở bảng tin
// nhóm (bài mới trước), chọn bài đầu tiên chưa bình luận và không phải của mình, bình luận câu server
// giao ngay dưới bài trên bảng tin — như người lướt nhóm.
//
// Bỏ qua bài: số bài nằm trong skip_posts (server nhớ bài đã bình luận), hoặc trong thẻ bài có link
// tới uid trong own_ids (bài mình đăng, hoặc mình đã bình luận). Không lấy được link bài thì cũng bỏ —
// không có số bài thì server không nhớ được, lần sau dễ bình luận lại.
//
// Kết quả: commented / ambiguous (đã gửi, không xác nhận được) / no_post / not_allowed / failed (chưa
// gửi gì) / blocked / checkpoint — kèm postUrl, author, comment (câu đã gửi thật).
import { MARK, commentBox, confirm, countText, feedUrl, goto, pause, postUrl, typeLines } from './comment.mjs';
import { fingerprint } from './review.mjs';
import * as ui from './ui.mjs';

const MAX_COMMENT = 2000;
// Không lọc theo ngày: cuộn xuống tới khi gặp bài chưa bình luận. Chỉ dừng khi đã xét chừng này bài,
// cuộn chừng này lần, hết thời gian (một lượt của bot bị cắt sau 15 phút), hoặc cuộn mãi không ra
// bài mới (hết bảng tin).
const MAX_CANDIDATES = 80;
const MAX_SCROLLS = 30;
const TIME_BUDGET_MS = 5 * 60_000;
const STALE_SCROLLS = 3;
// Đánh số thẻ bài lúc dò — khác MARK (thẻ bài đã chọn để bình luận).
const CANDIDATE = 'data-tkv-candidate';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// uid trong link tới một người/page: /groups/<nhóm>/user/<uid>/, profile.php?id=<uid>, /people/<tên>/<uid>/.
export function linkUid(href) {
  let url;
  try {
    url = new URL(href);
  } catch {
    return null;
  }
  if (!/(^|\.)facebook\.com$/.test(url.hostname)) return null;
  const user = /^\/groups\/[^/]+\/user\/(\d{5,30})\/?$/.exec(url.pathname);
  if (user) return user[1];
  if (url.pathname === '/profile.php') {
    const id = url.searchParams.get('id') || '';
    return /^\d{5,30}$/.test(id) ? id : null;
  }
  return /^\/people\/[^/]+\/(\d{5,30})\/?$/.exec(url.pathname)?.[1] ?? null;
}

// Số bài trong link bài nhóm — giống FacebookEngageQueue::postKey phía server.
export function postKey(url) {
  return /\/(?:posts|permalink)\/(\d{1,40})\/$/.exec(postUrl(url) || '')?.[1] ?? null;
}

// Thay {ten} bằng tên người đăng. Không có tên thì bỏ, dọn khoảng trắng và dấu câu bị bỏ lơ lửng.
export function fillName(text, name) {
  const clean = String(name || '').replace(/[{}]/g, '').replace(/\s+/g, ' ').trim().slice(0, 80);
  let out = String(text).replace(/\{ten\}/gi, clean);
  if (!clean) out = out.replace(/[ \t]+([,.!?])/g, '$1').replace(/^[ \t]*[,.][ \t]*/gm, '').replace(/[ \t]{2,}/g, ' ');
  return out.split('\n').map((line) => line.trim()).join('\n').trim();
}

// Đoạn chữ để nhận ra bình luận của mình sau khi gửi. Facebook vẽ emoji thành ảnh (không có trong
// innerText) nên lấy đoạn chữ dài nhất giữa các emoji của dòng đầu.
export function probeOf(text) {
  const line = String(text).split('\n').find((l) => l.trim()) || '';
  const parts = line.split(/[\p{Extended_Pictographic}\u{FE0F}\u{200D}]+/u).map((part) => part.trim()).filter(Boolean);
  const longest = parts.sort((a, b) => b.length - a.length)[0] || '';
  return longest.length >= 4 ? longest.slice(0, 40) : null;
}

// Thẻ bài có tên (link có chữ) của nick/page mình: bài mình đăng hoặc mình đã bình luận. Bỏ link không
// chữ — ảnh đại diện của chính mình cạnh ô bình luận dưới bài nào cũng có.
export function mentionsOwn(links, own) {
  return links.some((link) => (link.text || '').trim() && own.has(linkUid(link.href)));
}

// Tên người đăng: link đầu tiên trong thẻ bài trỏ tới một người/page và có chữ (ảnh đại diện thì không).
export function authorOf(links) {
  for (const link of links) {
    const name = (link.text || '').split('\n')[0].trim();
    if (name && name.length <= 80 && linkUid(link.href)) return name;
  }
  return null;
}

// job: { group_url, comment, skip_posts?: [số bài], own_ids?: [uid] }
export async function engageGroup(page, job, { dryRun = false, onSubmitting = null, log = () => {} } = {}) {
  const template = String(job.comment || '').trim();
  if (!template || template.length > MAX_COMMENT) return result('failed', 'Câu bình luận rỗng hoặc quá dài');
  const feed = feedUrl(job.group_url || '');
  if (!feed) return result('failed', 'Link nhóm không đúng dạng facebook.com/groups/...');

  const issue = await goto(page, feed);
  if (issue) return result(...issue);

  const { chosen, stats } = await pickPost(page, job, log);
  if (!chosen) {
    if (await notMember(page)) return result('not_allowed', 'Page chưa là thành viên nhóm (thấy nút "Tham gia nhóm")');
    return result('no_post', `Cuộn bảng tin nhóm không gặp bài nào để bình luận — ${describeStats(stats)}`);
  }
  const { url, author } = chosen;
  log(`Chọn bài ${url}${author ? ` của ${author}` : ''}.`);

  const article = page.locator(`[${MARK}]`).first();
  await article.scrollIntoViewIfNeeded().catch(() => {});
  await pause(1.0, 2.0);
  const box = await commentBox(page, article);
  if (!box) {
    if (await notMember(page)) return result('not_allowed', 'Page chưa là thành viên nhóm (thấy nút "Tham gia nhóm")', { url, author });
    return result('failed', 'Không thấy ô bình luận dưới bài — nhóm có thể không cho page bình luận', { url, author });
  }

  const text = fillName(template, author);
  const probe = probeOf(text);
  await box.click();
  await pause(0.6, 1.2);
  const baseline = probe ? await countText(page, probe, true) : 0;
  await typeLines(page, text);
  await pause(1.5, 3.0);
  if (dryRun) return result('dry_run', null, { url, author, text });

  // ── Từ đây bình luận có thể đã lên ──
  if (onSubmitting) onSubmitting();
  await page.keyboard.press('Enter');
  const outcome = await confirm(page, box, probe, true, url, { baseline });
  return { ...outcome, postUrl: url, author, comment: text };
}

function result(status, error = null, { url = null, author = null, text = null } = {}) {
  return { status, error, postUrl: url, author, comment: text };
}

// Không chọn được bài thì vì sao — ghi vào lý do để xem ở /admin/fb-engage, khỏi phải mở nhật ký.
export function describeStats({ checked, own, done, noLink, kinds }) {
  return `xét ${checked} bài: ${own} của mình/đã bình luận, ${done} đã bình luận (server nhớ), ${noLink} không lấy được link. Thẻ trên trang: ${kinds || 'chưa đọc được'}`;
}

// Dò từ đầu bảng tin xuống, cuộn thêm nếu cần. Thấy bài hợp lệ thì đánh dấu thẻ bài (MARK).
async function pickPost(page, job, log) {
  const skip = new Set((job.skip_posts || []).map(String));
  const own = new Set((job.own_ids || []).map(String));
  const tried = new Set();
  const stats = { checked: 0, own: 0, done: 0, noLink: 0, kinds: null };
  const deadline = Date.now() + TIME_BUDGET_MS;
  let stale = 0;

  for (let round = 0; round <= MAX_SCROLLS && stats.checked < MAX_CANDIDATES && stale < STALE_SCROLLS && Date.now() < deadline; round++) {
    if (round > 0) {
      await page.mouse.wheel(0, 2500);
      await pause(1.5, 2.5);
    }
    const { units, kinds } = await scan(page);
    stats.kinds = kinds;
    const before = stats.checked;
    for (const item of units) {
      if (stats.checked >= MAX_CANDIDATES || Date.now() >= deadline) break;
      const print = fingerprint(item.text).slice(0, 80);
      // Thẻ đang tải (chưa có chữ) để lượt cuộn sau xét lại.
      if (print.length < 10 || tried.has(print)) continue;
      tried.add(print);
      stats.checked++;

      if (mentionsOwn(item.links, own)) {
        stats.own++;
        continue;
      }
      const links = item.links.some((link) => postUrl(link.href.split('?')[0])) ? item.links : await revealLinks(page, item.index);
      const url = links.map((link) => postUrl(link.href.split('?')[0])).find(Boolean);
      if (!url) {
        stats.noLink++;
        log('Bỏ một bài: không lấy được link bài.');
        continue;
      }
      if (skip.has(postKey(url))) {
        stats.done++;
        continue;
      }

      const marked = await page.evaluate(({ attr, index, mark }) => {
        document.querySelectorAll(`[${mark}]`).forEach((el) => el.removeAttribute(mark));
        const article = document.querySelector(`[${attr}="${index}"]`);
        if (!article) return false;
        article.setAttribute(mark, '1');
        return true;
      }, { attr: CANDIDATE, index: item.index, mark: MARK });
      if (marked) return { chosen: { url, author: authorOf(links) }, stats };
    }
    stale = stats.checked === before ? stale + 1 : 0;
  }
  log(`Không chọn được bài — ${describeStats(stats)}.`);
  return { chosen: null, stats };
}

// Thẻ bài trên bảng tin, đánh số lại mỗi lần. Facebook có lúc để role=article cho bài, có lúc chỉ cho
// bình luận — lấy theo thứ tự: khối có aria-posinset (mẫu "feed" chuẩn), role=article lớp ngoài cùng
// (bình luận nằm trong bài), rồi con trực tiếp của role=feed. kinds: đếm từng loại, ghi vào lý do khi
// không chọn được bài.
async function scan(page) {
  return page.evaluate((attr) => {
    const main = document.querySelector('[role="main"]') || document.body;
    const outermost = (els) => els.filter((el) => !els.some((other) => other !== el && other.contains(el)));
    const posinset = outermost(Array.from(main.querySelectorAll('[aria-posinset]')));
    const articles = Array.from(main.querySelectorAll('[role="article"]')).filter((a) => !a.parentElement?.closest('[role="article"]'));
    const feedChildren = Array.from(main.querySelectorAll('[role="feed"] > *')).filter((el) => (el.innerText || '').trim().length > 20);
    const units = posinset.length ? posinset : articles.length ? articles : feedChildren;

    document.querySelectorAll(`[${attr}]`).forEach((el) => el.removeAttribute(attr));
    return {
      kinds: `aria-posinset ${posinset.length} · role=article ${articles.length} · con của role=feed ${feedChildren.length}`,
      units: units.map((unit, index) => {
        unit.setAttribute(attr, String(index));
        return {
          index,
          text: (unit.innerText || '').slice(0, 1000),
          links: Array.from(unit.querySelectorAll('a[href]'))
            .slice(0, 300)
            .map((a) => ({ href: a.href, text: (a.innerText || '').trim().slice(0, 120) })),
        };
      }),
    };
  }, CANDIDATE);
}

// Facebook chỉ điền link bài vào mốc giờ khi rê chuột qua — rê qua các link đầu thẻ rồi đọc lại.
async function revealLinks(page, index) {
  const article = page.locator(`[${CANDIDATE}="${index}"]`).first();
  const read = () => article.locator('a[href]').evaluateAll((anchors) => anchors.slice(0, 300).map((a) => ({ href: a.href, text: (a.innerText || '').trim().slice(0, 120) })));
  try {
    const anchors = article.locator('a[role="link"]');
    const count = Math.min(await anchors.count(), 8);
    for (let i = 0; i < count; i++) {
      try {
        await anchors.nth(i).hover({ timeout: 2_000 });
        await sleep(300);
      } catch {
        /* link bị che/khuất — thử link sau */
      }
      const links = await read();
      if (links.some((link) => postUrl(link.href.split('?')[0]))) return links;
    }
    return await read();
  } catch {
    return []; // thẻ bài vừa bị Facebook gỡ khỏi trang khi cuộn
  }
}

async function notMember(page) {
  try {
    return await page.locator('[role="main"]').getByRole('button', { name: ui.JOIN_BUTTON }).first().isVisible();
  } catch {
    return false;
  }
}
