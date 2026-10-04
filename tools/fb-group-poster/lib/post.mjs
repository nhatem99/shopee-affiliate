// Đăng một bài (chữ + tối đa vài ảnh) vào một nhóm qua giao diện web Facebook.
//
// Kết quả chia theo việc ĐÃ BẤM ĐĂNG HAY CHƯA:
//   • chưa bấm mà hỏng → "failed" (chưa có gì lên nhóm, server cho đăng lại);
//   • đã bấm mà không xác nhận được → "ambiguous" (có thể đã lên — không bao giờ tự đăng lại).
import { overlayText, problem, around } from './browser.mjs';
import * as ui from './ui.mjs';

// Chỉ đăng vào đúng link nhóm — server có bị chiếm quyền cũng không khiến bot đăng lên trang cá nhân.
export const GROUP_URL = /^https:\/\/www\.facebook\.com\/groups\/[A-Za-z0-9][A-Za-z0-9._-]*\/?$/;
const MAX_CAPTION = 6000;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const pause = (low = 0.6, high = 1.6) => sleep((low + Math.random() * (high - low)) * 1000);
const result = (status, error = null) => ({ status, error });

// imagePaths: một đường dẫn, mảng đường dẫn, hoặc null — theo thứ tự hiện trên bài.
export async function postToGroup(page, groupUrl, caption, imagePaths = null, { dryRun = false, onSubmitting = null } = {}) {
  const images = [imagePaths].flat().filter(Boolean);
  if (!GROUP_URL.test(groupUrl)) {
    return result('failed', `Link nhóm không đúng dạng facebook.com/groups/... — bỏ qua cho an toàn: ${groupUrl.slice(0, 120)}`);
  }
  if (!caption || caption.length > MAX_CAPTION) return result('failed', 'Nội dung bài rỗng hoặc quá dài');

  try {
    await page.goto(groupUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });
  } catch (error) {
    if (error.name === 'TimeoutError') return result('failed', 'Không tải được trang nhóm (quá 60 giây)');
    throw error;
  }
  await pause(2.5, 4.5);

  const issue = await problem(page);
  if (issue) return result(...issue);

  const opener = await findComposer(page);
  if (!opener) return whyNoComposer(page);
  await opener.click();
  await pause(1.5, 3.0);

  const textbox = page.locator(ui.COMPOSER_TEXTBOX).last();
  try {
    await textbox.waitFor({ state: 'visible', timeout: 15_000 });
  } catch {
    return result('failed', 'Bấm vào ô soạn bài nhưng khung "Tạo bài viết" không mở');
  }

  if (images.length) {
    const error = await attachImages(page, images);
    if (error) return result('failed', error);
  }

  await textbox.click();
  await pause(0.4, 0.9);
  // fill() giữ được xuống dòng và để Facebook tự biến URL thành link.
  await textbox.fill(caption);
  await pause(1.0, 2.0);

  if (dryRun) return result('dry_run');

  await clickNextIfAny(page);
  // Mỗi ảnh thêm chút thời gian: mạng điện thoại tải 5 ảnh lên Facebook có khi quá một phút.
  const waitSeconds = 60 + 15 * images.length;
  const button = await waitPostButton(page, waitSeconds);
  if (!button) return result('failed', `Nút Đăng không bấm được sau ${waitSeconds} giây (ảnh chưa tải xong?)`);

  // ── Từ đây trở đi bài có thể đã lên nhóm ──
  if (onSubmitting) onSubmitting();
  try {
    await button.click({ timeout: 10_000 });
  } catch (error) {
    return result('ambiguous', `Lỗi lúc bấm Đăng: ${error.message}`);
  }

  return confirm(page);
}

async function findComposer(page) {
  const candidates = [
    page.getByRole('button', { name: ui.COMPOSER }),
    page.locator('div[role="button"]').filter({ hasText: ui.COMPOSER }),
  ];
  for (const candidate of candidates) {
    try {
      const first = candidate.first();
      await first.waitFor({ state: 'visible', timeout: 8_000 });
      return first;
    } catch {
      /* thử cách tiếp theo */
    }
  }
  return null;
}

async function whyNoComposer(page) {
  try {
    if (await page.getByRole('button', { name: ui.JOIN_BUTTON }).first().isVisible()) {
      return result('not_allowed', 'Nick chưa là thành viên nhóm (thấy nút "Tham gia nhóm")');
    }
  } catch {
    /* bỏ qua */
  }
  const text = await page.evaluate(() => (document.body ? document.body.innerText.slice(0, 30000) : ''));
  if (ui.ADMIN_ONLY.test(text)) return result('not_allowed', 'Nhóm chỉ cho quản trị viên đăng bài');
  return result('failed', 'Không thấy ô "Bạn viết gì đi" trên trang nhóm');
}

async function attachImages(page, imagePaths) {
  const fileInput = page.locator('div[role="dialog"] input[type="file"]');
  if ((await fileInput.count()) === 0) {
    const photo = page.locator('div[role="dialog"]').getByRole('button', { name: ui.PHOTO_BUTTON });
    if (await photo.count()) {
      await photo.first().click();
      await pause(1.0, 2.0);
    }
  }
  if ((await fileInput.count()) === 0) return 'Không thấy chỗ tải ảnh trong khung soạn bài';

  // Ô chọn ảnh của Facebook thường nhận nhiều file một lần. Ô nào không có "multiple" thì thêm
  // từng tấm — sau mỗi tấm Facebook có thể dựng lại ô, nên tìm lại ô mỗi lượt.
  const input = fileInput.first();
  if (imagePaths.length === 1 || (await input.getAttribute('multiple')) !== null) {
    await input.setInputFiles(imagePaths);
  } else {
    for (const file of imagePaths) {
      await page.locator('div[role="dialog"] input[type="file"]').first().setInputFiles(file);
      await pause(2.0, 3.5);
    }
  }
  // Nút Đăng còn mờ tới khi ảnh tải xong (waitPostButton đợi tiếp) — ở đây chỉ nghỉ cho giống người.
  await pause(2.0 + imagePaths.length, 4.0 + imagePaths.length);
  return null;
}

// Một số nhóm có bước "Tiếp" (chọn chủ đề/ẩn danh) trước nút Đăng.
async function clickNextIfAny(page) {
  const button = page.locator('div[role="dialog"]').getByRole('button', { name: ui.NEXT_BUTTON });
  try {
    if ((await button.count()) && (await button.last().isVisible()) && (await button.last().getAttribute('aria-disabled')) !== 'true') {
      await button.last().click();
      await pause(1.5, 2.5);
    }
  } catch {
    /* không có bước Tiếp */
  }
}

async function waitPostButton(page, timeoutSeconds = 60) {
  const deadline = Date.now() + timeoutSeconds * 1000;
  while (Date.now() < deadline) {
    const button = page.locator('div[role="dialog"]').getByRole('button', { name: ui.POST_BUTTON });
    try {
      if (await button.count()) {
        const last = button.last();
        if ((await last.isVisible()) && (await last.getAttribute('aria-disabled')) !== 'true') return last;
      }
    } catch {
      /* thử lại */
    }
    await sleep(1000);
  }
  return null;
}

async function confirm(page, timeoutSeconds = 90) {
  const deadline = Date.now() + timeoutSeconds * 1000;
  while (Date.now() < deadline) {
    const verdict = await verdictFromOverlays(page);
    if (verdict) return verdict;
    if (!(await composerOpen(page))) {
      await pause(1.5, 2.5);
      return (await verdictFromOverlays(page)) ?? result('posted');
    }
    await sleep(1000);
  }
  return result('ambiguous', `Đã bấm Đăng nhưng sau ${timeoutSeconds} giây khung soạn bài vẫn chưa đóng`);
}

async function verdictFromOverlays(page) {
  const text = await overlayText(page);
  const blocked = ui.BLOCKED.exec(text);
  if (blocked) return result('blocked', `Facebook báo: ${around(text, blocked)}`);
  if (ui.APPROVAL.test(text)) return result('pending_approval');
  return null;
}

async function composerOpen(page) {
  try {
    const boxes = page.locator(ui.COMPOSER_TEXTBOX);
    return (await boxes.count()) > 0 && (await boxes.last().isVisible());
  } catch {
    return false;
  }
}
