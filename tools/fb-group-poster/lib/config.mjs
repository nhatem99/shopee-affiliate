// Cấu hình bot: ~/.config/tietkiemvi-fb-runner/config.json (chứa token — phải chmod 600).
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

// Server chỉ giao bài có ảnh tự tải lên cho bot từ 1.1.0 (FacebookGroupPostScheduler::UPLOADS_MIN_VERSION),
// việc kiểm tra duyệt bài từ 1.2.0 (FacebookGroupReviewChecker::MIN_VERSION), bài của các page
// ngoài nick chính từ 1.3.0 (FacebookGroupPostScheduler::PROFILES_MIN_VERSION).
export const VERSION = '1.3.1-node';

const home = (p) => (p.startsWith('~') ? path.join(os.homedir(), p.slice(1)) : p);

export const CONFIG_PATH = home(process.env.FB_RUNNER_CONFIG || '~/.config/tietkiemvi-fb-runner/config.json');
const DEFAULT_DATA_DIR = home(process.env.FB_RUNNER_DATA || '~/.local/share/tietkiemvi-fb-runner');

export class ConfigError extends Error {}

const onTermux = () => (process.env.PREFIX || '').includes('com.termux');

export function load({ requireServer = true } = {}) {
  let raw = {};
  if (fs.existsSync(CONFIG_PATH)) {
    // Token trong file này khiến được nick Facebook của bạn đăng bài — không để ai khác đọc.
    if (process.platform !== 'win32' && (fs.statSync(CONFIG_PATH).mode & 0o077) !== 0) {
      throw new ConfigError(`${CONFIG_PATH} đang cho tài khoản khác đọc được. Chạy: chmod 600 ${CONFIG_PATH}`);
    }
    try {
      raw = JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
    } catch (error) {
      throw new ConfigError(`${CONFIG_PATH} không phải JSON hợp lệ: ${error.message}`);
    }
  } else if (requireServer) {
    throw new ConfigError(`Chưa có ${CONFIG_PATH}. Vào /admin/fb-groups bấm "Tạo token" rồi dán nội dung vào file này (xem README).`);
  }

  const baseUrl = String(raw.base_url || '').replace(/\/+$/, '');
  const token = String(raw.token || '');
  if (requireServer) {
    const local = baseUrl.startsWith('http://localhost') || baseUrl.startsWith('http://127.0.0.1');
    if (!(baseUrl.startsWith('https://') || local) || !token) {
      throw new ConfigError('config.json thiếu base_url (https://...) hoặc token.');
    }
  }

  const dataDir = home(raw.data_dir || DEFAULT_DATA_DIR);
  return {
    baseUrl,
    token,
    dataDir,
    profileDir: home(raw.profile_dir || path.join(dataDir, 'profile')),
    // Nối vào Chromium đã mở sẵn (phone/start-chromium.sh) thay vì tự mở trình duyệt. Trong
    // Termux mặc định bật — Playwright không tự mở được Chromium trên Android.
    cdp: raw.cdp || (onTermux() ? 'http://127.0.0.1:9222' : null),
    browserChannel: raw.browser_channel || null,
    executablePath: raw.executable_path || null,
    maxSleepSeconds: Number(raw.max_sleep_seconds) || 120,
  };
}
