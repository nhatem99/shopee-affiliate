import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

import { groupKey } from '../lib/groups.mjs';
import { allowed, isUpload } from '../lib/images.mjs';
import { identity } from '../lib/browser.mjs';
import { GROUP_URL } from '../lib/post.mjs';
import { PROFILE_URL, profileFromInput } from '../lib/profiles.mjs';
import { TAB_URL, fingerprint, tabUrls } from '../lib/review.mjs';
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

test('isUpload chỉ nhận đường dẫn ảnh tự tải trên chính server', () => {
  assert.ok(isUpload('/runner/fb/images/0123456789abcdef0123456789abcdef.jpg'));
  assert.ok(isUpload('/runner/fb/images/0123456789abcdef0123456789abcdef.webp'));
  assert.ok(!isUpload('https://evil.com/runner/fb/images/0123456789abcdef0123456789abcdef.jpg'));
  assert.ok(!isUpload('//evil.com/runner/fb/images/0123456789abcdef0123456789abcdef.jpg'));
  assert.ok(!isUpload('/runner/fb/poll'));
  assert.ok(!isUpload('/runner/fb/images/../poll'));
  assert.ok(!isUpload('/runner/fb/images/0123456789abcdef0123456789abcdef.jpg?x=1'));
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

test('tabUrls dựng đúng 4 tab "Nội dung của bạn" và TAB_URL chỉ cho trang con của nhóm', () => {
  const tabs = tabUrls('https://www.facebook.com/groups/sansale.vn/');
  assert.equal(tabs.pending, 'https://www.facebook.com/groups/sansale.vn/my_pending_content/');
  assert.equal(tabs.removed, 'https://www.facebook.com/groups/sansale.vn/my_removed_content/');
  for (const url of Object.values(tabs)) assert.ok(TAB_URL.test(url));
  assert.ok(!TAB_URL.test('https://www.facebook.com/me'));
  assert.ok(!TAB_URL.test('https://www.facebook.com/groups/abc/posts/123'));
  assert.ok(!TAB_URL.test('https://evil.com/groups/abc/my_pending_content/'));
  assert.ok(!TAB_URL.test('https://www.facebook.com/groups/abc/../../settings/'));
});

test('fingerprint giống phía server: bỏ emoji, dấu cách, chữ hoa, khác dạng Unicode', () => {
  assert.equal(fingerprint('🔥 GIẢM giá\n  sập-sàn!!'), 'giảmgiásậpsàn');
  assert.equal(fingerprint('Giảm giá Sập Sàn'.normalize('NFD')), 'giảmgiásậpsàn');
});

test('ui nhận ra trang con không xem được', () => {
  assert.ok(ui.UNAVAILABLE.test('Nội dung này hiện không hiển thị'));
  assert.ok(ui.UNAVAILABLE.test("This content isn't available right now"));
  assert.ok(!ui.UNAVAILABLE.test('Không có bài viết nào để hiển thị'));
});

test('PROFILE_URL chỉ cho trang page trên facebook.com', () => {
  assert.ok(PROFILE_URL.test('https://www.facebook.com/profile.php?id=61551112223334'));
  assert.ok(PROFILE_URL.test('https://www.facebook.com/tietkiemvi.deal'));
  assert.ok(!PROFILE_URL.test('https://www.facebook.com/groups/abc'));
  assert.ok(!PROFILE_URL.test('https://www.facebook.com/profile.php?id=1&next=https://evil.com'));
  assert.ok(!PROFILE_URL.test('https://evil.com/tietkiemvi.deal'));
  assert.ok(!PROFILE_URL.test('http://www.facebook.com/tietkiemvi.deal'));
});

test('profileFromInput nhận uid, link và "primary"', () => {
  assert.deepEqual(profileFromInput('61551112223334'), {
    primary: false, name: 'https://www.facebook.com/profile.php?id=61551112223334',
    url: 'https://www.facebook.com/profile.php?id=61551112223334', fb_id: '61551112223334',
  });
  assert.equal(profileFromInput('https://www.facebook.com/tietkiemvi.deal').fb_id, null);
  assert.equal(profileFromInput('primary').primary, true);
});

test('SWITCH_BUTTON chỉ khớp đúng chữ trên nút', () => {
  assert.ok(ui.SWITCH_BUTTON.test('Chuyển ngay'));
  assert.ok(ui.SWITCH_BUTTON.test(' Switch now '));
  assert.ok(!ui.SWITCH_BUTTON.test('Chuyển tiền'));
  assert.ok(!ui.SWITCH_BUTTON.test('Chuyển sang trang khác'));
});

test('SWITCH_CONFIRM nhận nút xác nhận trong hộp thoại, không nhận nút khác', () => {
  for (const text of ['Chuyển', 'Chuyển ngay', 'Chuyển sang Shop Test', 'Chuyển trang cá nhân', 'Switch', 'Switch profiles', 'Switch to Shop Test']) {
    assert.ok(ui.SWITCH_CONFIRM.test(text), text);
  }
  for (const text of ['Hủy', 'Cancel', 'Chuyển tiền', 'Không chuyển']) {
    assert.ok(!ui.SWITCH_CONFIRM.test(text), text);
  }
});

test('identity: i_user là page đang dùng, không có thì là nick chính', async () => {
  const context = (cookies) => ({ cookies: async () => cookies.map(([name, value]) => ({ name, value })) });
  assert.deepEqual(await identity(context([['c_user', '100'], ['xs', 'x']])), { accountId: '100', actorId: '100' });
  assert.deepEqual(await identity(context([['c_user', '100'], ['i_user', '615']])), { accountId: '100', actorId: '615' });
  assert.deepEqual(await identity(context([['i_user', '615']])), { accountId: null, actorId: null });
});
