// Tải ảnh của bài về file JPEG tạm để đính kèm. Chỉ hai nguồn:
//   • ảnh sản phẩm trên CDN ảnh của Shopee (link https đầy đủ);
//   • ảnh admin tự tải lên ở /admin/fb-posts — server gửi đường dẫn /runner/fb/images/<tên>, bot
//     tải từ chính server đã cấu hình, kèm token.
// Không tải link lạ nào khác — server có bị chiếm quyền cũng không biến bot thành máy đi tải bậy.
//
// Shopee hay trả .webp, Facebook nên nhận JPEG. Không dùng thư viện ảnh native (khó cài trên
// Termux): nhờ chính Chromium đổi định dạng bằng canvas.
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { VERSION } from './config.mjs';

const ALLOWED_HOSTS = ['cf.shopee.vn'];
const ALLOWED_SUFFIXES = ['.susercontent.com'];
const MAX_BYTES = 10 * 1024 * 1024;

// Trùng FacebookPostImages::MAX_PER_POST và NAME_PATTERN phía server.
export const MAX_IMAGES = 5;
export const UPLOAD_PATH = /^\/runner\/fb\/images\/[a-f0-9]{32}\.(?:jpg|png|webp)$/;

export const isUpload = (ref) => UPLOAD_PATH.test(String(ref));

// Một ảnh trong danh sách "images" của lượt nhận bài → file JPEG tạm.
export async function fetchAsJpeg(ref, api, directory, context) {
  if (isUpload(ref)) return toJpeg(context, await api.download(ref, MAX_BYTES), directory);
  return downloadAsJpeg(ref, directory, context);
}

export function allowed(url) {
  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    return false;
  }
  const host = parsed.hostname.toLowerCase();
  return parsed.protocol === 'https:' && (ALLOWED_HOSTS.includes(host) || ALLOWED_SUFFIXES.some((s) => host.endsWith(s)));
}

export async function downloadAsJpeg(url, directory, context) {
  if (!allowed(url)) throw new Error(`Ảnh không nằm trên CDN Shopee, bỏ qua: ${url.slice(0, 120)}`);

  const response = await fetch(url, {
    headers: { 'User-Agent': `Mozilla/5.0 tietkiemvi-fb-runner/${VERSION}` },
    signal: AbortSignal.timeout(30_000),
    redirect: 'error',
  });
  if (!response.ok) throw new Error(`Tải ảnh lỗi HTTP ${response.status}`);
  const body = Buffer.from(await response.arrayBuffer());
  if (body.length > MAX_BYTES) throw new Error('Ảnh lớn hơn 10 MB');

  return toJpeg(context, body, directory);
}

export async function toJpeg(context, body, directory) {
  const page = await context.newPage();
  try {
    const base64 = await page.evaluate(async (data) => {
      const bytes = Uint8Array.from(atob(data), (c) => c.charCodeAt(0));
      const bitmap = await createImageBitmap(new Blob([bytes]));
      const canvas = document.createElement('canvas');
      canvas.width = bitmap.width;
      canvas.height = bitmap.height;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(bitmap, 0, 0);
      const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
      const buffer = new Uint8Array(await blob.arrayBuffer());
      let binary = '';
      for (let i = 0; i < buffer.length; i += 0x8000) binary += String.fromCharCode(...buffer.subarray(i, i + 0x8000));
      return btoa(binary);
    }, body.toString('base64'));

    fs.mkdirSync(directory, { recursive: true });
    const file = path.join(directory, `deal-${crypto.randomBytes(6).toString('hex')}.jpg`);
    fs.writeFileSync(file, Buffer.from(base64, 'base64'));
    return file;
  } finally {
    await page.close().catch(() => {});
  }
}
