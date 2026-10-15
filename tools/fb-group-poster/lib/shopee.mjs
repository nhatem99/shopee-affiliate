// Việc "shopee_video": mở trang sản phẩm Shopee bằng chính Chromium của bot (đã đăng nhập Shopee một
// lần), bắt phản hồi API chi tiết sản phẩm Shopee gọi lúc tải trang rồi đọc link mp4 của video.
//
// Server không tự làm được: Shopee trả 403 lỗi 90309999 cho mọi lời gọi API sản phẩm không đến từ
// trình duyệt thật (đo 2026-10-15). Bot chỉ gửi link — server tự tải file về.

// API chi tiết sản phẩm: get_pc (bản máy tính — Chromium trên điện thoại chạy bản Linux nên là bản
// này), get_rw (bản điện thoại), item/get (bản cũ).
export const PRODUCT_API = /\/api\/v4\/(?:pdp\/get_pc|pdp\/get_rw|pdp\/get|item\/get)(?:\?|$)/;

// Link video Shopee: CDN *.vod.susercontent.com (…/mms/….mp4) hay cvf.shopee.vn. Server kiểm lại host.
const VIDEO_HOST = /^https:\/\/[^/]*(?:susercontent\.com|shopee\.vn|shopee\.com|shopeemobile\.com)\//i;
const VIDEO_PATH = /\.mp4(?:[?#]|$)/i;

// Shopee chặn trình duyệt lạ: trả lỗi này, trang chuyển sang /verify/traffic hay /verify/captcha.
const BLOCKED_ERROR = 90309999;
const LOGIN_PAGE = /shopee\.vn\/buyer\/login/;
const VERIFY_PAGE = /shopee\.vn\/verify\//;

export function isVideoUrl(value) {
  return typeof value === 'string' && VIDEO_HOST.test(value) && VIDEO_PATH.test(value);
}

// Link mp4 trong một phản hồi API, kèm kích thước (để server chọn bản rõ nhất) và độ dài nếu có.
// Không bám vào đường dẫn cố định (data.item.video_info_list, data.product_images.video...) vì Shopee
// đổi cấu trúc thường xuyên — đi hết cây JSON, gặp chuỗi nào là link mp4 thì lấy.
export function extractVideos(json) {
  const found = new Map();
  const walk = (node, duration) => {
    if (Array.isArray(node)) {
      for (const item of node) walk(item, duration);
      return;
    }
    if (!node || typeof node !== 'object') return;
    const here = Number.isFinite(node.duration) && node.duration > 0 ? node.duration : duration;
    for (const [key, value] of Object.entries(node)) {
      if (isVideoUrl(value) && !found.has(value)) {
        // Kích thước chỉ tin khi chuỗi nằm ở khoá url của một object định dạng (có width/height).
        const sized = key === 'url' && Number.isInteger(node.width) && Number.isInteger(node.height);
        found.set(value, {
          url: value,
          width: sized ? node.width : null,
          height: sized ? node.height : null,
          // Shopee ghi giây; số quá lớn là mili giây.
          duration: here ? (here > 10_000 ? here / 1000 : here) : null,
        });
      } else if (value && typeof value === 'object') {
        walk(value, here);
      }
    }
  };
  walk(json, null);
  return [...found.values()];
}

// Mở trang sản phẩm, trả { status, videos, error, responses }:
//   found          — có ít nhất một link video;
//   no_video       — đọc được dữ liệu sản phẩm nhưng không có video;
//   login_required — Shopee bắt đăng nhập;
//   blocked        — Shopee bắt xác minh / chặn;
//   failed         — trang không tải, không thấy dữ liệu sản phẩm.
// responses: phản hồi API bắt được (lệnh thử --dump ghi ra file để người sửa code xem).
export async function fetchShopeeVideo(browser, url, { log = () => {}, waitMs = 30_000 } = {}) {
  // Mở tab riêng để không làm mất trang Facebook đang mở; không mở được (CDP trên vài máy) thì
  // dùng luôn tab chính — việc sau tự goto lại Facebook.
  let page = null;
  let ownTab = true;
  try {
    page = await browser.context.newPage();
  } catch (error) {
    log(`Không mở được tab mới (${error.message}) — dùng tab đang mở.`);
    page = browser.page;
    ownTab = false;
  }

  const videos = new Map();
  const responses = [];
  let productSeen = false;
  let apiError = null;

  const onResponse = async (response) => {
    if (!PRODUCT_API.test(response.url())) return;
    let json;
    try {
      json = await response.json();
    } catch {
      return;
    }
    responses.push({ url: response.url(), status: response.status(), json });
    if (json?.error) {
      apiError = json.error;
      return;
    }
    productSeen = true;
    for (const video of extractVideos(json)) if (!videos.has(video.url)) videos.set(video.url, video);
  };
  page.on('response', onResponse);

  try {
    try {
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    } catch (error) {
      return { status: 'failed', videos: [], error: `Không mở được trang Shopee: ${error.message}`, responses };
    }

    const deadline = Date.now() + waitMs;
    while (Date.now() < deadline && !productSeen && apiError === null) {
      if (LOGIN_PAGE.test(page.url()) || VERIFY_PAGE.test(page.url())) break;
      await page.waitForTimeout(500);
    }
    // Trang gọi vài API một lượt — chờ thêm chút cho đủ.
    if (productSeen) await page.waitForTimeout(2000);

    // Dự phòng: thẻ <video> trên trang đã có link mp4 thật (không phải blob:).
    if (!videos.size) {
      const sources = await page
        .$$eval('video, video source', (nodes) => nodes.map((node) => node.currentSrc || node.src).filter(Boolean))
        .catch(() => []);
      for (const source of sources) if (isVideoUrl(source)) videos.set(source, { url: source, width: null, height: null, duration: null });
    }

    const current = page.url();
    if (videos.size) return { status: 'found', videos: [...videos.values()], error: null, responses };
    if (LOGIN_PAGE.test(current)) return { status: 'login_required', videos: [], error: null, responses };
    if (VERIFY_PAGE.test(current) || apiError === BLOCKED_ERROR) {
      return { status: 'blocked', videos: [], error: `Shopee trả lỗi ${apiError ?? '—'} ở ${current.slice(0, 120)}`, responses };
    }
    if (productSeen) return { status: 'no_video', videos: [], error: null, responses };
    return {
      status: 'failed',
      videos: [],
      error: apiError !== null ? `Shopee trả lỗi ${apiError}` : `Không thấy dữ liệu sản phẩm sau ${Math.round(waitMs / 1000)} giây (${current.slice(0, 120)})`,
      responses,
    };
  } finally {
    page.off('response', onResponse);
    if (ownTab) await page.close().catch(() => {});
  }
}
