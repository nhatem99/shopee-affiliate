import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

import { groupKey } from '../lib/groups.mjs';
import { allowed } from '../lib/images.mjs';
import { GROUP_URL } from '../lib/post.mjs';
import { State } from '../lib/state.mjs';
import * as ui from '../lib/ui.mjs';

test('groupKey nhận link nhóm và bỏ trang không phải nhóm', () => {
  assert.equal(groupKey('https://www.facebook.com/groups/SanSale.VN/'), 'sansale.vn');
  assert.equal(groupKey('https://m.facebook.com/groups/12345?ref=x'), '12345');
  assert.equal(groupKey('https://www.facebook.com/groups/joins/'), null);
  assert.equal(groupKey('https://www.facebook.com/groups/feed'), null);
  assert.equal(groupKey('https://evil.com/groups/abc'), null);
  assert.equal(groupKey('https://www.facebook.com/profile.php'), null);
});

test('GROUP_URL chỉ cho đúng link nhóm', () => {
  assert.ok(GROUP_URL.test('https://www.facebook.com/groups/abc.def/'));
  assert.ok(GROUP_URL.test('https://www.facebook.com/groups/123456'));
  assert.ok(!GROUP_URL.test('https://www.facebook.com/me'));
  assert.ok(!GROUP_URL.test('https://www.facebook.com/groups/abc/posts/1'));
  assert.ok(!GROUP_URL.test('http://www.facebook.com/groups/abc'));
});

test('allowed chỉ nhận ảnh từ CDN Shopee', () => {
  assert.ok(allowed('https://cf.shopee.vn/file/abc'));
  assert.ok(allowed('https://down-vn.img.susercontent.com/file/abc'));
  assert.ok(!allowed('http://cf.shopee.vn/file/abc'));
  assert.ok(!allowed('https://evil.com/susercontent.com'));
  assert.ok(!allowed('https://susercontent.com.evil.com/x'));
});

test('State giữ claim_key và trạng thái bài đang dở qua lần khởi động lại', () => {
  const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'fbstate-')), 'state.json');
  const a = new State(file);
  const key = a.claimKey;
  assert.match(key, /^[0-9a-f]{32}$/);

  a.start(7, key);
  a.submitting();

  const b = new State(file);
  assert.equal(b.claimKey, key);
  assert.deepEqual(b.inflight, { post_id: 7, claim_key: key, phase: 'submitting' });

  b.clear();
  b.newClaimKey();
  assert.equal(new State(file).inflight, null);
  assert.notEqual(new State(file).claimKey, key);
});

test('ui nhận đúng chữ tiếng Việt và tiếng Anh', () => {
  assert.ok(ui.COMPOSER.test('Bạn viết gì đi...'));
  assert.ok(ui.COMPOSER.test('Write something...'));
  assert.ok(ui.POST_BUTTON.test('Đăng'));
  assert.ok(!ui.POST_BUTTON.test('Đăng nhập'));
  assert.ok(ui.BLOCKED.test('Bạn tạm thời bị chặn'));
  assert.ok(ui.APPROVAL.test('Bài viết đang chờ phê duyệt'));
});
