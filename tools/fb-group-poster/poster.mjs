#!/usr/bin/env node
// Bot đăng deal vào nhóm Facebook cho tietkiemvi (bản Node, chạy trên điện thoại/Termux). Xem README.md.
//
//   node poster.mjs login                 đăng nhập Facebook một lần
//   node poster.mjs run                   chạy thật: hỏi server có bài thì đăng
//   node poster.mjs sync                  lấy danh sách nhóm đã tham gia gửi lên server
//   node poster.mjs check                 kiểm tra kết nối server + trình duyệt + đã đăng nhập chưa
//   node poster.mjs dry-run --group URL   thử trên một nhóm: điền hết nhưng KHÔNG bấm Đăng
//                                         (thêm --image <ảnh> tối đa 5 lần để thử kèm ảnh)
//   node poster.mjs review --group URL    xem "Nội dung của bạn" của một nhóm bot đọc được gì
//                                         (thêm --text "đoạn đầu bài" để xem bài đó nằm ở tab nào)
import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline/promises';
import { parseArgs } from 'node:util';

import { Api, ApiError, AuthError, errorText } from './lib/api.mjs';
import { accountId, launch } from './lib/browser.mjs';
import * as configModule from './lib/config.mjs';
import { ScrapeError, scrapeJoinedGroups } from './lib/groups.mjs';
import { MAX_IMAGES, downloadAsJpeg, fetchAsJpeg, isUpload } from './lib/images.mjs';
import { GROUP_URL, postToGroup } from './lib/post.mjs';
import { fingerprint, scanTabs, tabUrls } from './lib/review.mjs';
import { State } from './lib/state.mjs';

let logFile = null;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const rand = (low, high) => Math.floor(low + Math.random() * (high - low + 1));

function pad(n) {
  return String(n).padStart(2, '0');
}

function stamp(date = new Date()) {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

function log(message) {
  const line = `[${stamp()}] ${message}`;
  console.log(line);
  if (logFile) fs.appendFileSync(logFile, `${line}\n`);
}

const accountLabel = (uid) => (uid ? `uid ${uid}` : null);

// Một lượt (hỏi việc + đăng + báo kết quả) bình thường dưới 5 phút; lấy nhóm nhiều thì lâu hơn.
const TICK_LIMIT_MS = (Number(process.env.FB_TICK_LIMIT_SECONDS) || 15 * 60) * 1000;
const LAUNCH_LIMIT_MS = 2 * 60 * 1000;

// Bot chạy nền không ai trông: kẹt ở đâu đó (Chromium treo, kết nối điều khiển bị một bot cũ
// đóng băng giữ lại...) thì thoát để fb-poster.sh chạy lại từ đầu, còn hơn đứng im cả ngày.
// Thoát thẳng, không đóng trình duyệt — chính việc đóng cũng có thể treo. Bài đang dở được
// state.json báo đúng ở lần chạy sau ("không rõ" nếu đã bấm Đăng).
function withDeadline(promise, ms, what) {
  let timer;
  const deadline = new Promise(() => {
    timer = setTimeout(() => {
      const limit = ms >= 60_000 ? `${Math.round(ms / 60_000)} phút` : `${Math.round(ms / 1000)} giây`;
      log(`${what} kẹt quá ${limit} — thoát để chạy lại từ đầu.`);
      process.exit(3);
    }, ms);
  });
  return Promise.race([promise, deadline]).finally(() => clearTimeout(timer));
}

async function ask(prompt) {
  const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
  await rl.question(prompt);
  rl.close();
}

// ── login ────────────────────────────────────────────────────────────────────

async function cmdLogin(cfg) {
  const browser = await launch(cfg);
  await browser.page.goto('https://www.facebook.com/');
  log('Đăng nhập Facebook trong cửa sổ Chromium (kể cả mã 2 lớp nếu có).');
  await ask('Thấy bảng tin Facebook rồi thì quay lại đây bấm Enter... ');
  const uid = await accountId(browser.context);
  await browser.close();
  if (!uid) {
    log('Chưa thấy đăng nhập — chạy lại: node poster.mjs login');
    return 1;
  }
  log(`Đã đăng nhập (${accountLabel(uid)}).`);
  return 0;
}

// ── check ────────────────────────────────────────────────────────────────────

async function cmdCheck(cfg) {
  let failed = false;
  try {
    await new Api(cfg.baseUrl, cfg.token).ping();
    log(`Server OK, token đúng (${cfg.baseUrl})`);
  } catch (error) {
    failed = true;
    log(`Server LỖI: ${error.message}`);
  }
  try {
    const browser = await launch(cfg);
    const uid = await accountId(browser.context);
    log(uid ? `Trình duyệt OK, đã đăng nhập Facebook (${accountLabel(uid)})` : 'Trình duyệt OK nhưng CHƯA đăng nhập Facebook — chạy: node poster.mjs login');
    failed ||= !uid;
    await browser.close();
  } catch (error) {
    failed = true;
    log(`Trình duyệt LỖI: ${error.message}`);
  }
  return failed ? 1 : 0;
}

// ── dry-run ──────────────────────────────────────────────────────────────────

async function cmdDryRun(cfg, group, caption, images) {
  const browser = await launch(cfg);
  let result;
  try {
    const imagePaths = [];
    for (const image of images.slice(0, MAX_IMAGES)) {
      imagePaths.push(image.startsWith('https://') ? await downloadAsJpeg(image, path.join(cfg.dataDir, 'tmp'), browser.context) : image);
    }
    result = await postToGroup(browser.page, group, caption, imagePaths, { dryRun: true });
    log(`Kết quả: ${result.status} ${result.error || ''}`);
    if (result.status === 'dry_run') {
      await ask('Đã điền xong, KHÔNG bấm Đăng. Xem màn hình rồi bấm Enter để đóng (bài nháp bị bỏ)... ');
    }
  } finally {
    await browser.close();
  }
  return result.status === 'dry_run' ? 0 : 1;
}

// ── review ───────────────────────────────────────────────────────────────────

const TAB_NAMES = { pending: 'Đang chờ', published: 'Đã đăng', declined: 'Bị từ chối', removed: 'Đã gỡ' };

// Chạy tay, không gọi server: in ra những gì bot đọc được ở từng tab — để thử khi Facebook đổi
// giao diện hoặc đường dẫn các tab.
async function cmdReview(cfg, group, text) {
  const snippet = text ? [...fingerprint(text)].slice(0, 30).join('') : null;
  const browser = await launch(cfg);
  let opened = 0;
  try {
    const tabs = await scanTabs(browser.page, tabUrls(group));
    for (const [state, tab] of Object.entries(tabs)) {
      const name = TAB_NAMES[state] || state;
      if (!tab.ok) {
        log(`[${name}] KHÔNG mở được: ${tab.error}`);
        continue;
      }
      opened++;
      const posts = tab.items.filter((item) => !item.page);
      log(`[${name}] mở được — ${posts.length} bài dạng thẻ${posts.length ? '' : ' (server sẽ dò trong cả khối chữ của trang)'}`);
      for (const item of posts) log(`    • ${item.text.split(/\s+/).join(' ').slice(0, 100)}${item.url ? `  ${item.url}` : ''}`);
      if (snippet) {
        const hit = tab.items.some((item) => fingerprint(item.text).includes(snippet));
        log(hit ? '    → THẤY bài có đoạn đầu này ở tab này' : '    → không thấy bài có đoạn đầu này');
      }
    }
  } finally {
    await browser.close();
  }
  return opened ? 0 : 1;
}

// ── sync ─────────────────────────────────────────────────────────────────────

async function syncGroups(api, browser) {
  const uid = await accountId(browser.context);
  if (!uid) {
    log('Chưa đăng nhập Facebook — không lấy được nhóm.');
    return;
  }
  let groups;
  try {
    groups = await scrapeJoinedGroups(browser.page, { log });
  } catch (error) {
    if (error instanceof ScrapeError) {
      log(`Không lấy được nhóm: ${error.message}`);
      return;
    }
    throw error;
  }
  if (!groups.length) log('Không thấy nhóm nào trên trang Nhóm của bạn — có thể Facebook đổi giao diện.');
  const result = await api.uploadGroups(groups, accountLabel(uid));
  log(`Đã gửi ${groups.length} nhóm lên server: thêm mới ${result.created}, cập nhật ${result.updated}.`);
}

async function cmdSync(cfg) {
  const api = new Api(cfg.baseUrl, cfg.token);
  const browser = await launch(cfg);
  try {
    await syncGroups(api, browser);
  } finally {
    await browser.close();
  }
  return 0;
}

// ── run ──────────────────────────────────────────────────────────────────────

// Bài của lượt trước chưa báo được kết quả (bot tắt giữa chừng / mất mạng) — báo trước đã.
async function flushUnreported(api, state) {
  const inflight = state.inflight;
  if (!inflight) return;

  let status = inflight.final_status;
  let error = inflight.error;
  if (!status) {
    if (inflight.phase === 'submitting') [status, error] = ['ambiguous', 'Bot bị tắt đúng lúc bấm Đăng — có thể bài đã lên nhóm'];
    else [status, error] = ['failed', 'Bot bị tắt trước khi bấm Đăng'];
  }

  log(`Báo kết quả còn treo của bài #${inflight.post_id}: ${status}`);
  await api.report(inflight.post_id, inflight.claim_key, status, error);
  state.clear();
  state.newClaimKey();
}

async function doPost(api, state, cfg, browser, job) {
  const { post_id: postId, claim_key: claimKey } = job;
  state.start(postId, claimKey);
  log(`Đăng bài #${postId} vào nhóm ${job.group_name || job.group_url}`);

  // Server cũ chỉ gửi image_url; server mới gửi "images" (ảnh sản phẩm + ảnh admin tự tải lên).
  const refs = Array.isArray(job.images) ? job.images : job.image_url ? [job.image_url] : [];
  const imagePaths = [];
  const tmpDir = path.join(cfg.dataDir, 'tmp');
  let imageError = null;
  for (const ref of refs.slice(0, MAX_IMAGES)) {
    try {
      imagePaths.push(await fetchAsJpeg(ref, api, tmpDir, browser.context));
    } catch (error) {
      // Ảnh admin tự chọn mà thiếu thì đừng đăng — bài sẽ khác ý admin. Chưa bấm Đăng nên báo
      // "failed", admin bấm đăng lại được. Ảnh sản phẩm Shopee thì bỏ qua như trước.
      if (isUpload(ref)) {
        imageError = `Không tải được ảnh đã tải lên (${errorText(error)}) — chưa đăng.`;
        break;
      }
      log(`Không tải được ảnh sản phẩm (${errorText(error)}) — đăng không kèm ảnh này.`);
    }
  }

  let result;
  try {
    result = imageError
      ? { status: 'failed', error: imageError }
      : await postToGroup(browser.page, job.group_url, job.caption, imagePaths, { onSubmitting: () => state.submitting() });
  } catch (error) {
    const submitted = state.inflight?.phase === 'submitting';
    result = { status: submitted ? 'ambiguous' : 'failed', error: `Lỗi trình duyệt: ${error.message}` };
  } finally {
    for (const file of imagePaths) fs.rmSync(file, { force: true });
  }

  if (result.status !== 'posted') {
    const shot = path.join(cfg.dataDir, 'screenshots', `post-${postId}-${stamp().replace(/\D/g, '')}.png`);
    try {
      fs.mkdirSync(path.dirname(shot), { recursive: true });
      await browser.page.screenshot({ path: shot });
      log(`Ảnh chụp màn hình: ${shot}`);
    } catch {
      /* trình duyệt đã đóng */
    }
  }

  state.finish(result.status, result.error);
  log(`Kết quả bài #${postId}: ${result.status}${result.error ? ` — ${result.error}` : ''}`);
  await api.report(postId, claimKey, result.status, result.error);
  state.clear();
  state.newClaimKey();
}

// Facebook bắt xác minh/chặn lúc kiểm tra duyệt bài — báo ở lượt hỏi việc kế tiếp để server tạm
// dừng và báo admin (lượt đăng bài thì báo qua kết quả bài).
let pendingState = null;

async function doReview(api, browser, job) {
  log(`Kiểm tra bài đã đăng ở nhóm ${job.group_name || job.group_url}`);
  const tabs = await scanTabs(browser.page, job.tabs || {});
  const issue = Object.values(tabs).find((tab) => tab.issue);
  if (issue) {
    pendingState = issue.issue;
    log(`Facebook báo: ${issue.error}`);
  }
  for (const [state, tab] of Object.entries(tabs)) {
    if (!tab.ok) log(`  Tab ${TAB_NAMES[state] || state} không mở được: ${tab.error}`);
  }
  const payload = Object.fromEntries(Object.entries(tabs).map(([state, tab]) => [state, { ok: tab.ok, error: tab.error, items: tab.items }]));
  const summary = await api.reportReview(job.group_id, payload);
  log(`Kết quả kiểm tra: thấy ${summary.found}/${summary.checked} bài trong "Nội dung của bạn".`);
}

// Vì sao server chưa giao bài (reason trong FacebookGroupPostScheduler::poll/blockingReason).
const IDLE_REASONS = {
  empty: 'Chưa có bài nào trong hàng đợi — soạn bài ở /admin/fb-posts.',
  outside_window: 'Ngoài khung giờ đăng (giờ Việt Nam, chỉnh ở /admin/fb-groups) — đợi tới giờ.',
  daily_cap: 'Đã đăng đủ số bài hôm nay — mai đăng tiếp.',
  gap: 'Đang nghỉ giữa hai bài theo cài đặt.',
  paused: 'Server đang TẠM DỪNG bot — xem lý do và bấm "Chạy tiếp" ở /admin/fb-groups.',
  busy: 'Server còn chờ kết quả một bài khác.',
  preparing: 'Server đang chuẩn bị bài (tạo link mua)...',
};
let lastIdle = null;

// Chỉ in khi lý do đổi — không thì cứ 1–2 phút một dòng giống hệt nhau.
function logIdle(reason) {
  if (reason === lastIdle) return;
  lastIdle = reason;
  log(`Chưa có việc: ${IDLE_REASONS[reason] || reason || 'không rõ lý do'}`);
}

// Một lượt hỏi việc. Trả số giây nên ngủ trước lượt sau.
async function tick(api, state, cfg, browser) {
  await flushUnreported(api, state);

  const uid = await accountId(browser.context);
  const job = await api.poll(state.claimKey, pendingState ?? (uid ? 'ok' : 'logged_out'), accountLabel(uid));
  pendingState = null;

  if (job.type === 'post') {
    lastIdle = null;
    await doPost(api, state, cfg, browser, job);
    return rand(20, 40);
  }
  if (job.type === 'review_group') {
    lastIdle = null;
    await doReview(api, browser, job);
    return rand(20, 40);
  }
  if (job.type === 'sync_groups') {
    lastIdle = null;
    log('Server yêu cầu lấy danh sách nhóm.');
    await syncGroups(api, browser);
    return 20;
  }

  if (job.reason === 'paused' && !uid) {
    log('Nick Facebook đang bị đăng xuất — đăng nhập lại rồi bấm "Chạy tiếp" trên trang admin.');
  }
  logIdle(job.reason);
  return Number(job.retry_after) || 60;
}

// Hai bot cùng lúc thì cùng điều khiển một Chromium. Chuyện này dễ xảy ra trên điện thoại:
// vòng lặp fb-poster.sh tự bật lại bot vừa bị tắt, rồi người dùng lại chạy thêm một vòng nữa.
function otherRunner(pidFile) {
  let pid;
  try {
    pid = Number(fs.readFileSync(pidFile, 'utf8'));
  } catch {
    return null;
  }
  if (!pid || pid === process.pid) return null;
  try {
    process.kill(pid, 0);
  } catch {
    return null; // tiến trình đã chết, file còn sót lại
  }
  // PID có thể đã bị tiến trình khác dùng lại — chỉ tính khi đúng là bot.
  try {
    if (!fs.readFileSync(`/proc/${pid}/cmdline`, 'utf8').includes('poster.mjs')) return null;
  } catch {
    /* không có /proc (Windows, macOS): tin vào kill(0) */
  }
  return pid;
}

async function cmdRun(cfg) {
  const pidFile = path.join(cfg.dataDir, 'run.pid');
  const other = otherRunner(pidFile);
  if (other) {
    log(`Đã có bot khác đang chạy (PID ${other}) — không chạy thêm con thứ hai.`);
    return 4;
  }
  fs.writeFileSync(pidFile, String(process.pid));

  const api = new Api(cfg.baseUrl, cfg.token);
  const state = new State(path.join(cfg.dataDir, 'state.json'));
  let stopping = false;
  const requestStop = () => {
    stopping = true;
    log('Nhận lệnh dừng — thoát sau lượt đang làm.');
  };
  process.on('SIGTERM', requestStop);
  process.on('SIGINT', requestStop);

  log(`Bot đăng nhóm Facebook ${configModule.VERSION} — server ${cfg.baseUrl}`);
  const browser = await withDeadline(launch(cfg), LAUNCH_LIMIT_MS, 'Nối vào Chromium');
  log('Đã nối vào Chromium, bắt đầu hỏi việc.');
  let exitCode = 0;
  try {
    while (!stopping) {
      let wait;
      try {
        wait = await withDeadline(tick(api, state, cfg, browser), TICK_LIMIT_MS, 'Một lượt làm việc');
      } catch (error) {
        if (error instanceof AuthError) {
          log(error.message);
          exitCode = 2;
          break;
        }
        if (!(error instanceof ApiError)) throw error;
        log(`${error.message} — thử lại sau 1 phút.`);
        wait = 60;
      }

      wait = Math.max(20, Math.min(cfg.maxSleepSeconds, wait)) + rand(0, 10);
      const deadline = Date.now() + wait * 1000;
      while (!stopping && Date.now() < deadline) await sleep(1000);
    }
  } finally {
    await browser.close().catch(() => {});
    if (otherRunner(pidFile) === null) fs.rmSync(pidFile, { force: true });
  }
  return exitCode;
}

async function main() {
  const [command, ...rest] = process.argv.slice(2);
  const { values } = parseArgs({
    args: rest,
    options: {
      group: { type: 'string' },
      caption: { type: 'string', default: 'Bài thử (không đăng)\nDòng 2\nhttps://shopee.vn' },
      image: { type: 'string', multiple: true, default: [] },
      text: { type: 'string' },
    },
  });

  if (!['login', 'run', 'sync', 'check', 'dry-run', 'review'].includes(command)) {
    console.error('Dùng: node poster.mjs <login|run|sync|check|dry-run --group URL|review --group URL>');
    return 1;
  }
  if (['dry-run', 'review'].includes(command) && !GROUP_URL.test(values.group || '')) {
    console.error(`${command} cần --group https://www.facebook.com/groups/...`);
    return 1;
  }

  let cfg;
  try {
    cfg = configModule.load({ requireServer: ['run', 'sync', 'check'].includes(command) });
  } catch (error) {
    console.error(error.message);
    return 1;
  }

  fs.mkdirSync(path.join(cfg.dataDir, 'logs'), { recursive: true });
  logFile = path.join(cfg.dataDir, 'logs', 'poster.log');

  if (command === 'login') return cmdLogin(cfg);
  if (command === 'check') return cmdCheck(cfg);
  if (command === 'dry-run') return cmdDryRun(cfg, values.group, values.caption, values.image);
  if (command === 'review') return cmdReview(cfg, values.group, values.text);
  if (command === 'sync') return cmdSync(cfg);
  return cmdRun(cfg);
}

process.exitCode = await main();
