#!/usr/bin/env node
// Bot đăng deal vào nhóm Facebook cho tietkiemvi (bản Node, chạy trên điện thoại/Termux). Xem README.md.
//
//   node poster.mjs login                 đăng nhập Facebook một lần
//   node poster.mjs run                   chạy thật: hỏi server có bài thì đăng
//   node poster.mjs sync                  lấy danh sách nhóm đã tham gia gửi lên server
//   node poster.mjs check                 kiểm tra kết nối server + trình duyệt + đã đăng nhập chưa
//   node poster.mjs dry-run --group URL   thử trên một nhóm: điền hết nhưng KHÔNG bấm Đăng
import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline/promises';
import { parseArgs } from 'node:util';

import { Api, ApiError, AuthError, errorText } from './lib/api.mjs';
import { accountId, launch } from './lib/browser.mjs';
import * as configModule from './lib/config.mjs';
import { ScrapeError, scrapeJoinedGroups } from './lib/groups.mjs';
import { downloadAsJpeg } from './lib/images.mjs';
import { postToGroup } from './lib/post.mjs';
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

async function cmdDryRun(cfg, group, caption, image) {
  const browser = await launch(cfg);
  let result;
  try {
    let imagePath = null;
    if (image) imagePath = image.startsWith('https://') ? await downloadAsJpeg(image, path.join(cfg.dataDir, 'tmp'), browser.context) : image;
    result = await postToGroup(browser.page, group, caption, imagePath, { dryRun: true });
    log(`Kết quả: ${result.status} ${result.error || ''}`);
    if (result.status === 'dry_run') {
      await ask('Đã điền xong, KHÔNG bấm Đăng. Xem màn hình rồi bấm Enter để đóng (bài nháp bị bỏ)... ');
    }
  } finally {
    await browser.close();
  }
  return result.status === 'dry_run' ? 0 : 1;
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

  let imagePath = null;
  const tmpDir = path.join(cfg.dataDir, 'tmp');
  if (job.image_url) {
    try {
      imagePath = await downloadAsJpeg(job.image_url, tmpDir, browser.context);
    } catch (error) {
      log(`Không tải được ảnh sản phẩm (${errorText(error)}) — đăng không kèm ảnh.`);
    }
  }

  let result;
  try {
    result = await postToGroup(browser.page, job.group_url, job.caption, imagePath, { onSubmitting: () => state.submitting() });
  } catch (error) {
    const submitted = state.inflight?.phase === 'submitting';
    result = { status: submitted ? 'ambiguous' : 'failed', error: `Lỗi trình duyệt: ${error.message}` };
  } finally {
    if (imagePath) fs.rmSync(imagePath, { force: true });
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
  const job = await api.poll(state.claimKey, uid ? 'ok' : 'logged_out', accountLabel(uid));

  if (job.type === 'post') {
    lastIdle = null;
    await doPost(api, state, cfg, browser, job);
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

async function cmdRun(cfg) {
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
      image: { type: 'string' },
    },
  });

  if (!['login', 'run', 'sync', 'check', 'dry-run'].includes(command)) {
    console.error('Dùng: node poster.mjs <login|run|sync|check|dry-run --group URL>');
    return 1;
  }
  if (command === 'dry-run' && !values.group) {
    console.error('dry-run cần --group https://www.facebook.com/groups/...');
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
  if (command === 'sync') return cmdSync(cfg);
  return cmdRun(cfg);
}

process.exitCode = await main();
