// Gọi server tietkiemvi: hỏi việc, báo kết quả, gửi danh sách nhóm.
import { VERSION } from './config.mjs';

export class AuthError extends Error {}
export class ApiError extends Error {}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

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
      throw new ApiError(`Không gọi được server: ${error.message}`);
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

  async poll(claimKey, state, account) {
    const [status, data] = await this.post('/runner/fb/poll', { claim_key: claimKey, state, version: VERSION, account });
    if (status !== 200) throw new ApiError(`Hỏi việc lỗi HTTP ${status}: ${data.message || ''}`);
    return data;
  }

  // Báo kết quả, thử lại tới khi server nhận. 409 = lượt này server đã chốt/không khớp.
  async report(postId, claimKey, status, error) {
    const payload = { claim_key: claimKey, status, error: error ? String(error).slice(0, 1000) : null };
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

  async uploadGroups(groups, account) {
    const [status, data] = await this.post('/runner/fb/groups', { groups, account });
    if (status !== 200) throw new ApiError(`Gửi danh sách nhóm lỗi HTTP ${status}: ${JSON.stringify(data)}`);
    return data;
  }
}
