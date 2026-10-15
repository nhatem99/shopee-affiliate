import assert from 'node:assert/strict';
import test from 'node:test';

import { PRODUCT_API, extractVideos, isVideoUrl } from '../lib/shopee.mjs';

const MP4_720 = 'https://down-bs-sg.vod.susercontent.com/api/v4/11110105/mms/vn-11110105-6ke15-abc.16000081703.mp4';
const MP4_360 = 'https://down-bs-sg.vod.susercontent.com/api/v4/11110105/mms/vn-11110105-6ke15-abc.16000051703.mp4';

test('PRODUCT_API nhận API chi tiết sản phẩm, bỏ API khác', () => {
  assert.ok(PRODUCT_API.test('https://shopee.vn/api/v4/pdp/get_pc?item_id=1&shop_id=2'));
  assert.ok(PRODUCT_API.test('https://shopee.vn/api/v4/pdp/get_rw?item_id=1&shop_id=2&tz_offset_in_minutes=420'));
  assert.ok(PRODUCT_API.test('https://shopee.vn/api/v4/item/get?itemid=1&shopid=2'));
  assert.ok(!PRODUCT_API.test('https://shopee.vn/api/v4/pdp/get_shop_info?shop_id=2'));
  assert.ok(!PRODUCT_API.test('https://shopee.vn/api/v4/pdp/get_pc_extra?item_id=1'));
});

test('isVideoUrl chỉ nhận mp4 trên host Shopee', () => {
  assert.ok(isVideoUrl(MP4_720));
  assert.ok(isVideoUrl('https://cvf.shopee.vn/file/api/v4/11110105/mms/abc.mp4?x=1'));
  assert.ok(!isVideoUrl('http://down-bs-sg.vod.susercontent.com/a.mp4'));
  assert.ok(!isVideoUrl('https://evil.com/shopee.vn/a.mp4'));
  assert.ok(!isVideoUrl('https://down-vn.img.susercontent.com/file/abc'));
  assert.ok(!isVideoUrl('blob:https://shopee.vn/123'));
  assert.ok(!isVideoUrl(null));
});

test('extractVideos lấy mọi bản mp4 kèm kích thước và độ dài', () => {
  const json = {
    error: null,
    data: {
      item: {
        item_id: 27690212870,
        video_info_list: [
          {
            video_id: 'abc',
            duration: 31,
            thumb_url: 'https://down-vn.img.susercontent.com/file/thumb',
            default_format: { format: 1600008, defn: 'V720P', url: MP4_720, width: 720, height: 1280 },
            formats: [
              { format: 1600008, defn: 'V720P', url: MP4_720, width: 720, height: 1280 },
              { format: 1600005, defn: 'V360P', url: MP4_360, width: 360, height: 640 },
            ],
          },
        ],
      },
      product_images: { images: ['https://down-vn.img.susercontent.com/file/a'] },
    },
  };

  assert.deepEqual(extractVideos(json), [
    { url: MP4_720, width: 720, height: 1280, duration: 31 },
    { url: MP4_360, width: 360, height: 640, duration: 31 },
  ]);
});

test('extractVideos đổi mili giây ra giây, link không ở khoá url thì không có kích thước', () => {
  const json = { data: { video: { duration: 15500, mms_data: MP4_720, width: 720, height: 1280 } } };

  assert.deepEqual(extractVideos(json), [{ url: MP4_720, width: null, height: null, duration: 15.5 }]);
});

test('extractVideos trả rỗng khi sản phẩm không có video', () => {
  assert.deepEqual(extractVideos({ data: { item: { video_info_list: [], images: ['x'] } } }), []);
  assert.deepEqual(extractVideos(null), []);
});
