// Gọi server tietkiemvi: hỏi việc, báo kết quả, gửi danh sách nhóm.
import { VERSION } from './config.mjs';

export class AuthError extends Error {}
export class ApiError extends Error {}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// fetch() của Node chỉ báo "fetch failed" — lý do thật (ENOTFOUND, ECONNRESET, hết giờ...) nằm
// trong error.cause. Thiếu nó thì không phân biệt được lỗi mạng điện thoại với lỗi server.
export function errorText(error) {
  const cause = error?.cause?.code || error?.cause?.message;
  return cause ? `${error.message} (${cause})` : String(error?.message ?? error);
}

export class Api {
  // Lượt nhận bài ở chế độ mã YTB mất tới ~45 giây phía server.
  constructor(baseUrl, token, timeoutMs = 90_000) {
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.token = token;
    this.timeoutMs = timeoutMs;
  }

  async post(path, payload) {
    let response;
    try {
      response = await fetch(this.baseUrl + path, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'User-Agent': `tietkiemvi-fb-runner/${VERSION}`,
          'X-Runner-Token': this.token,
        },
        body: JSON.stringify(payload),
        signal: AbortSignal.timeout(this.timeoutMs),
      });
    } catch (error) {
      throw new ApiError(`Không gọi được server: ${errorText(error)}`);
    }
    if (response.status === 403) {
      throw new AuthError('Server từ chối token (403) — kiểm tra token trong config.json hoặc tạo token mới.');
    }
    let data = {};
    try {
      const parsed = await response.json();
      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) data = parsed;
    } catch {
      /* thân không phải JSON */
    }
    return [response.status, data];
  }

  // Kiểm tra token mà KHÔNG nhận bài: gửi lượt hỏi việc thiếu claim_key — token đúng thì server
  // trả 422 (thiếu dữ liệu), token sai thì 403. Gọi poll thật sẽ giữ một bài mà không đăng.
  async ping() {
    const [status, data] = await this.post('/runner/fb/poll', {});
    if (status !== 422) throw new ApiError(`Server trả HTTP ${status}: ${data.message || ''}`);
  }

  // who: { accountId, actorId } từ browser.identity() — server nhận ra page nào đang mở.
  async poll(claimKey, state, account, who = {}) {
    const [status, data] = await this.post('/runner/fb/poll', {
      claim_key: claimKey,
      state,
      version: VERSION,
      account,
      account_id: who.accountId ?? null,
      actor_id: who.actorId ?? null,
    });
    if (status !== 200) throw new ApiError(`Hỏi việc lỗi HTTP ${status}: ${data.message || ''}`);
    return data;
  }

  // Báo kết quả, thử lại tới khi server nhận. 409 = lượt này server đã chốt/không khớp.
  // actorId: uid page đã đăng bài — server điền uid cho page thêm bằng link tên rút gọn.
  // postUrl: link bài bắt được lúc bấm Đăng — server dùng để giao việc bình luận link.
  async report(postId, claimKey, status, error, actorId = null, postUrl = null) {
    const payload = { claim_key: claimKey, status, error: error ? String(error).slice(0, 1000) : null, actor_id: actorId, post_url: postUrl };
    let last = null;
    for (let attempt = 0; attempt < 6; attempt++) {
      try {
        const [code, data] = await this.post(`/runner/fb/posts/${postId}/result`, payload);
        if ([200, 404, 409].includes(code)) return code;
        if (code === 422) throw new ApiError(`Server không nhận kết quả: ${JSON.stringify(data)}`);
        last = new ApiError(`HTTP ${code}`);
      } catch (caught) {
        if (caught instanceof AuthError) throw caught;
        last = caught;
      }
      await sleep(Math.min(60, 5 * 2 ** attempt) * 1000);
    }
    throw new ApiError(`Không báo được kết quả bài #${postId}: ${last?.message}`);
  }

  // Kết quả việc "comment". Trả mã HTTP: 409 = bài không còn chờ bình luận (đã chốt), 422 = server
  // không nhận dữ liệu — cả hai gửi lại cũng vậy. Lỗi mạng thì thử lại vài lần.
  async reportComment(postId, status, error, postUrl = null) {
    const payload = { status, error: error ? String(error).slice(0, 1000) : null, post_url: postUrl };
    let last = null;
    for (let attempt = 0; attempt < 3; attempt++) {
      try {
        const [code] = await this.post(`/runner/fb/posts/${postId}/comment`, payload);
        if ([200, 404, 409, 422].includes(code)) return code;
        last = new ApiError(`HTTP ${code}`);
      } catch (caught) {
        if (caught instanceof AuthError) throw caught;
        last = caught;
      }
      await sleep(5000 * (attempt + 1));
    }
    throw new ApiError(`Không báo được kết quả bình luận bài #${postId}: ${last?.message}`);
  }

  // Ảnh admin tự tải lên cho bài — chỉ server của mình có, phải kèm token. `path` đã được
  // images.mjs kiểm đúng dạng /runner/fb/images/<tên> nên không gọi được đường nào khác.
  async download(path, maxBytes) {
    let response;
    try {
      response = await fetch(this.baseUrl + path, {
        headers: { 'User-Agent': `tietkiemvi-fb-runner/${VERSION}`, 'X-Runner-Token': this.token },
        signal: AbortSignal.timeout(60_000),
        redirect: 'error',
      });
    } catch (error) {
      throw new ApiError(`Không tải được ảnh: ${errorText(error)}`);
    }
    if (response.status === 403) throw new AuthError('Server từ chối token (403) khi tải ảnh.');
    if (!response.ok) throw new ApiError(`Tải ảnh lỗi HTTP ${response.status}`);
    const body = Buffer.from(await response.arrayBuffer());
    if (body.length > maxBytes) throw new ApiError('Ảnh quá lớn');
    return body;
  }

  // Báo những gì thấy ở các tab "Nội dung của bạn" của nhóm. Lỗi thì thôi — server giao lại sau.
  async reportReview(groupId, tabs, profileId = null) {
    const [status, data] = await this.post(`/runner/fb/groups/${groupId}/review`, { tabs, profile_id: profileId });
    if (status !== 200) throw new ApiError(`Báo kết quả kiểm tra nhóm lỗi HTTP ${status}: ${data.message || ''}`);
    return data;
  }

  // Nhóm của một page: profileId khi server giao lấy nhóm theo page; lấy tay thì gửi uid để server
  // tự nhận ra page đang mở.
  async uploadGroups(groups, account, { profileId = null, accountId = null, actorId = null } = {}) {
    const [status, data] = await this.post('/runner/fb/groups', {
      groups,
      account,
      profile_id: profileId,
      account_id: accountId,
      actor_id: actorId,
    });
    if (status !== 200) throw new ApiError(`Gửi danh sách nhóm lỗi HTTP ${status}: ${JSON.stringify(data)}`);
    return data;
  }
}
