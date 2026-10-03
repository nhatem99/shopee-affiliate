// Trạng thái lưu đĩa để bot tắt giữa chừng vẫn báo đúng kết quả bài đang dở — thà báo "không rõ"
// còn hơn để server tưởng chưa đăng rồi đăng lại.
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

export class State {
  constructor(file) {
    this.file = file;
    try {
      this.data = JSON.parse(fs.readFileSync(file, 'utf8'));
    } catch {
      this.data = {};
    }
  }

  // Key của lượt hỏi việc hiện tại — giữ nguyên qua các lần khởi động lại cho tới khi xong bài,
  // để server trả lại đúng bài đã giao nếu bot chết sau khi nhận.
  get claimKey() {
    if (!this.data.claim_key) this.newClaimKey();
    return this.data.claim_key;
  }

  newClaimKey() {
    this.data.claim_key = crypto.randomUUID().replaceAll('-', '');
    this.save();
  }

  get inflight() {
    return this.data.inflight || null;
  }

  start(postId, claimKey) {
    this.data.inflight = { post_id: postId, claim_key: claimKey, phase: 'claimed' };
    this.save();
  }

  // Ngay trước khi bấm Đăng — từ đây bài có thể đã lên nhóm.
  submitting() {
    if (this.inflight) {
      this.inflight.phase = 'submitting';
      this.save();
    }
  }

  finish(status, error) {
    if (this.inflight) {
      Object.assign(this.inflight, { final_status: status, error });
      this.save();
    }
  }

  clear() {
    delete this.data.inflight;
    this.save();
  }

  save() {
    fs.mkdirSync(path.dirname(this.file), { recursive: true });
    const tmp = `${this.file}.tmp`;
    fs.writeFileSync(tmp, JSON.stringify(this.data));
    fs.renameSync(tmp, this.file);
  }
}
