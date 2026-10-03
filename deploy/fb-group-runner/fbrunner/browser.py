"""Mở Chromium với hồ sơ (profile) riêng của bot — đăng nhập một lần, lần sau tự vào lại."""

import re

from playwright.sync_api import BrowserContext, Page, Playwright

from . import ui
from .config import Config


def launch(pw: Playwright, cfg: Config, headless: bool = False) -> tuple[BrowserContext, Page]:
    cfg.profile_dir.mkdir(parents=True, exist_ok=True)
    context = pw.chromium.launch_persistent_context(
        user_data_dir=str(cfg.profile_dir),
        channel=cfg.browser_channel,
        headless=headless,
        locale="vi-VN",
        timezone_id="Asia/Ho_Chi_Minh",
        viewport={"width": 1280, "height": 900},
        slow_mo=60,
        args=["--disable-blink-features=AutomationControlled"],
    )
    page = context.pages[0] if context.pages else context.new_page()
    return context, page


def account_id(context: BrowserContext) -> str | None:
    """uid Facebook của nick đang đăng nhập (cookie c_user) — không có là chưa đăng nhập."""
    for cookie in context.cookies("https://www.facebook.com"):
        if cookie.get("name") == "c_user" and cookie.get("value"):
            return cookie["value"]
    return None


def overlay_text(page: Page) -> str:
    try:
        return "\n".join(page.locator(ui.OVERLAYS).all_inner_texts())[:20000]
    except Exception:
        return ""


def problem(page: Page) -> tuple[str, str] | None:
    """Nick có đang bị Facebook chặn/đăng xuất không — trả (status, mô tả) theo kết quả bot báo về."""
    url = page.url
    if "/checkpoint" in url:
        return "checkpoint", f"Facebook bắt xác minh tài khoản ({url[:120]})"
    if re.search(r"facebook\.com/(login|recover)", url) or _login_form_visible(page):
        return "checkpoint", "Nick Facebook đã bị đăng xuất"

    text = overlay_text(page)
    match = ui.BLOCKED.search(text)
    if match:
        return "blocked", f"Facebook báo: {_around(text, match)}"
    return None


def _login_form_visible(page: Page) -> bool:
    try:
        return page.locator('input[name="email"]').first.is_visible() and page.locator('input[name="pass"]').first.is_visible()
    except Exception:
        return False


def _around(text: str, match: re.Match) -> str:
    start = max(0, match.start() - 60)
    return " ".join(text[start : match.end() + 120].split())
