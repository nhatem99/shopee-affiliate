"""Kiểm tra phần không cần trình duyệt. Chạy trong thư mục deploy/fb-group-runner:
    .venv/bin/python -m unittest discover -s tests
"""

import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from fbrunner import ui  # noqa: E402
from fbrunner.groups import group_key  # noqa: E402
from fbrunner.images import allowed, is_upload  # noqa: E402
from fbrunner.poster import GROUP_URL  # noqa: E402
from fbrunner.state import State  # noqa: E402


class GroupKeyTest(unittest.TestCase):
    def test_same_rules_as_server(self):
        self.assertEqual(group_key("https://www.facebook.com/groups/123456789012345/"), "123456789012345")
        self.assertEqual(group_key("https://m.facebook.com/groups/SanSale/permalink/1/?x=y"), "sansale")
        self.assertIsNone(group_key("https://www.facebook.com/groups/joins/"))
        self.assertIsNone(group_key("https://www.facebook.com/groups/feed/"))
        self.assertIsNone(group_key("https://facebook.com.evil.test/groups/abc"))
        self.assertIsNone(group_key("https://www.facebook.com/zuck"))


class SafetyTest(unittest.TestCase):
    def test_only_posts_to_plain_group_urls(self):
        self.assertTrue(GROUP_URL.match("https://www.facebook.com/groups/shopee1111/"))
        self.assertFalse(GROUP_URL.match("https://www.facebook.com/zuck"))
        self.assertFalse(GROUP_URL.match("https://www.facebook.com/groups/abc/../../zuck"))
        self.assertFalse(GROUP_URL.match("https://www.facebook.com/groups/../"))
        self.assertIsNone(group_key("https://www.facebook.com/groups/../"))
        self.assertFalse(GROUP_URL.match("https://evil.test/groups/abc"))

    def test_only_downloads_shopee_images(self):
        self.assertTrue(allowed("https://down-vn.img.susercontent.com/file/abc.webp"))
        self.assertTrue(allowed("https://cf.shopee.vn/file/abc"))
        self.assertFalse(allowed("http://cf.shopee.vn/file/abc"))
        self.assertFalse(allowed("https://evil.test/susercontent.com.jpg"))
        self.assertFalse(allowed("https://susercontent.com.evil.test/a.jpg"))

    def test_uploads_only_from_own_server_path(self):
        name = "0123456789abcdef0123456789abcdef"
        self.assertTrue(is_upload(f"/runner/fb/images/{name}.jpg"))
        self.assertTrue(is_upload(f"/runner/fb/images/{name}.webp"))
        self.assertFalse(is_upload(f"https://evil.test/runner/fb/images/{name}.jpg"))
        self.assertFalse(is_upload(f"//evil.test/runner/fb/images/{name}.jpg"))
        self.assertFalse(is_upload("/runner/fb/poll"))
        self.assertFalse(is_upload("/runner/fb/images/../poll"))
        self.assertFalse(is_upload(f"/runner/fb/images/{name}.jpg\n"))


class UiTextTest(unittest.TestCase):
    def test_composer_and_buttons(self):
        self.assertTrue(ui.COMPOSER.search("Bạn viết gì đi..."))
        self.assertTrue(ui.COMPOSER.search("Tạo bài viết công khai..."))
        self.assertTrue(ui.POST_BUTTON.match("Đăng"))
        self.assertFalse(ui.POST_BUTTON.match("Đăng ẩn danh"))

    def test_result_texts(self):
        self.assertTrue(ui.APPROVAL.search("Bài viết của bạn đang chờ quản trị viên phê duyệt"))
        self.assertTrue(ui.APPROVAL.search("Sent for approval"))
        self.assertTrue(ui.BLOCKED.search("Bạn tạm thời bị chặn"))
        self.assertTrue(ui.BLOCKED.search("You're Temporarily Blocked"))
        self.assertFalse(ui.BLOCKED.search("Bài viết của bạn đã được đăng"))


class StateTest(unittest.TestCase):
    def test_claim_key_survives_restart_until_post_finishes(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "state.json"
            state = State(path)
            key = state.claim_key
            state.start(7, key)
            state.submitting()

            restarted = State(path)
            self.assertEqual(restarted.claim_key, key)
            self.assertEqual(restarted.inflight["phase"], "submitting")

            restarted.finish("posted", None)
            restarted.clear()
            restarted.new_claim_key()
            self.assertIsNone(State(path).inflight)
            self.assertNotEqual(State(path).claim_key, key)


if __name__ == "__main__":
    unittest.main()
