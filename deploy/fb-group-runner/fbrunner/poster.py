"""Đăng một bài (ảnh + chữ) vào một nhóm qua giao diện web Facebook.

Kết quả chia theo việc ĐÃ BẤM ĐĂNG HAY CHƯA:
  • chưa bấm mà hỏng → "failed" (chưa có gì lên nhóm, server cho đăng lại);
  • đã bấm mà không xác nhận được → "ambiguous" (có thể đã lên — không bao giờ tự đăng lại).
"""

import random
import re
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Callable

from playwright.sync_api import Locator, Page
from playwright.sync_api import TimeoutError as PlaywrightTimeout

from . import ui
from .browser import overlay_text, problem

# Chỉ đăng vào đúng link nhóm — server có bị chiếm quyền cũng không khiến bot đăng lên trang cá nhân.
GROUP_URL = re.compile(r"^https://www\.facebook\.com/groups/[A-Za-z0-9][A-Za-z0-9._-]*/?$")
MAX_CAPTION = 6000


@dataclass
class Result:
    status: str  # posted | pending_approval | failed | ambiguous | not_allowed | blocked | checkpoint | dry_run
    error: str | None = None


def pause(low: float = 0.6, high: float = 1.6) -> None:
    time.sleep(random.uniform(low, high))


def post_to_group(
    page: Page,
    group_url: str,
    caption: str,
    image_path: Path | None = None,
    dry_run: bool = False,
    on_submitting: Callable[[], None] | None = None,
) -> Result:
    if not GROUP_URL.match(group_url):
        return Result("failed", f"Link nhóm không đúng dạng facebook.com/groups/... — bỏ qua cho an toàn: {group_url[:120]}")
    if not caption or len(caption) > MAX_CAPTION:
        return Result("failed", "Nội dung bài rỗng hoặc quá dài")

    try:
        page.goto(group_url, wait_until="domcontentloaded", timeout=60_000)
    except PlaywrightTimeout:
        return Result("failed", "Không tải được trang nhóm (quá 60 giây)")
    pause(2.5, 4.5)

    issue = problem(page)
    if issue:
        return Result(*issue)

    opener = _find_composer(page)
    if opener is None:
        return _why_no_composer(page)
    opener.click()
    pause(1.5, 3.0)

    textbox = page.locator(ui.COMPOSER_TEXTBOX).last
    try:
        textbox.wait_for(state="visible", timeout=15_000)
    except PlaywrightTimeout:
        return Result("failed", 'Bấm vào ô soạn bài nhưng khung "Tạo bài viết" không mở')

    if image_path:
        error = _attach_image(page, image_path)
        if error:
            return Result("failed", error)

    textbox.click()
    pause(0.4, 0.9)
    # fill() giữ được xuống dòng và để Facebook tự biến URL thành link (đã thử thật 03/10/2026).
    textbox.fill(caption)
    pause(1.0, 2.0)

    if dry_run:
        return Result("dry_run")

    _click_next_if_any(page)
    button = _wait_post_button(page)
    if button is None:
        return Result("failed", "Nút Đăng không bấm được sau 60 giây (ảnh chưa tải xong?)")

    # ── Từ đây trở đi bài có thể đã lên nhóm ──
    if on_submitting:
        on_submitting()
    try:
        button.click(timeout=10_000)
    except Exception as error:  # noqa: BLE001 — bấm hỏng giữa chừng thì không biết đã gửi chưa
        return Result("ambiguous", f"Lỗi lúc bấm Đăng: {error}")

    return _confirm(page)


def _find_composer(page: Page) -> Locator | None:
    candidates = [
        page.get_by_role("button", name=ui.COMPOSER),
        page.locator('div[role="button"]').filter(has_text=ui.COMPOSER),
    ]
    for candidate in candidates:
        try:
            first = candidate.first
            first.wait_for(state="visible", timeout=8_000)
            return first
        except PlaywrightTimeout:
            continue
    return None


def _why_no_composer(page: Page) -> Result:
    try:
        if page.get_by_role("button", name=ui.JOIN_BUTTON).first.is_visible():
            return Result("not_allowed", 'Nick chưa là thành viên nhóm (thấy nút "Tham gia nhóm")')
    except Exception:
        pass
    text = page.evaluate("() => document.body ? document.body.innerText.slice(0, 30000) : ''")
    if ui.ADMIN_ONLY.search(text):
        return Result("not_allowed", "Nhóm chỉ cho quản trị viên đăng bài")
    return Result("failed", 'Không thấy ô "Bạn viết gì đi" trên trang nhóm')


def _attach_image(page: Page, image_path: Path) -> str | None:
    file_input = page.locator('div[role="dialog"] input[type="file"]')
    if file_input.count() == 0:
        photo = page.locator('div[role="dialog"]').get_by_role("button", name=ui.PHOTO_BUTTON)
        if photo.count():
            photo.first.click()
            pause(1.0, 2.0)
    if file_input.count() == 0:
        return "Không thấy chỗ tải ảnh trong khung soạn bài"

    file_input.first.set_input_files(str(image_path))
    pause(3.0, 5.0)
    return None


def _click_next_if_any(page: Page) -> None:
    """Một số nhóm có bước "Tiếp" (chọn chủ đề/ẩn danh) trước nút Đăng."""
    button = page.locator('div[role="dialog"]').get_by_role("button", name=ui.NEXT_BUTTON)
    try:
        if button.count() and button.last.is_visible() and button.last.get_attribute("aria-disabled") != "true":
            button.last.click()
            pause(1.5, 2.5)
    except Exception:
        pass


def _wait_post_button(page: Page, timeout: float = 60) -> Locator | None:
    deadline = time.time() + timeout
    while time.time() < deadline:
        button = page.locator('div[role="dialog"]').get_by_role("button", name=ui.POST_BUTTON)
        try:
            if button.count():
                last = button.last
                if last.is_visible() and last.get_attribute("aria-disabled") != "true":
                    return last
        except Exception:
            pass
        time.sleep(1)
    return None


def _confirm(page: Page, timeout: float = 90) -> Result:
    deadline = time.time() + timeout
    while time.time() < deadline:
        verdict = _verdict_from_overlays(page)
        if verdict:
            return verdict
        if not _composer_open(page):
            pause(1.5, 2.5)
            return _verdict_from_overlays(page) or Result("posted")
        time.sleep(1)
    return Result("ambiguous", f"Đã bấm Đăng nhưng sau {int(timeout)} giây khung soạn bài vẫn chưa đóng")


def _verdict_from_overlays(page: Page) -> Result | None:
    text = overlay_text(page)
    blocked = ui.BLOCKED.search(text)
    if blocked:
        start = max(0, blocked.start() - 60)
        return Result("blocked", "Facebook báo: " + " ".join(text[start : blocked.end() + 120].split()))
    if ui.APPROVAL.search(text):
        return Result("pending_approval")
    return None


def _composer_open(page: Page) -> bool:
    try:
        boxes = page.locator(ui.COMPOSER_TEXTBOX)
        return boxes.count() > 0 and boxes.last.is_visible()
    except Exception:
        return False
