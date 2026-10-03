"""Lấy danh sách nhóm nick đã tham gia từ trang "Nhóm của bạn" (facebook.com/groups/joins/)."""

import random
import re
from urllib.parse import unquote, urlparse

from playwright.sync_api import Page

from .browser import problem

JOINS_URL = "https://www.facebook.com/groups/joins/?nav_source=tab"

# Giống FacebookGroup::NOT_GROUP_KEYS phía server — trang của Facebook nằm dưới /groups/ nhưng không phải nhóm.
NOT_GROUP_KEYS = {
    "joins", "feed", "discover", "create", "search", "notifications", "your_groups",
    "categories", "category", "invites", "browse", "membership", "manage", "settings",
    "saved", "you", "pending", "posts", "permalink",
}
KEY_PATTERN = re.compile(r"^[a-z0-9][a-z0-9._-]{1,149}$")


def group_key(url: str) -> str | None:
    parsed = urlparse(url if "://" in url else "https://" + url)
    host = (parsed.hostname or "").lower()
    if host != "facebook.com" and not host.endswith(".facebook.com"):
        return None
    segments = [s for s in parsed.path.split("/") if s]
    if len(segments) < 2 or segments[0] != "groups":
        return None
    key = unquote(segments[1]).lower()
    if not KEY_PATTERN.match(key) or key in NOT_GROUP_KEYS:
        return None
    return key


class ScrapeError(Exception):
    def __init__(self, status: str, message: str):
        super().__init__(message)
        self.status = status


def scrape_joined_groups(page: Page, max_groups: int = 2000, log=print) -> list[dict]:
    page.goto(JOINS_URL, wait_until="domcontentloaded", timeout=60_000)
    page.wait_for_timeout(random.randint(3000, 5000))
    issue = problem(page)
    if issue:
        raise ScrapeError(*issue)

    found: dict[str, dict] = {}
    unchanged = 0
    while unchanged < 3 and len(found) < max_groups:
        before = len(found)
        links = page.evaluate(
            """() => Array.from(document.querySelectorAll('a[href*="/groups/"]'))
                .map(a => ({ href: a.href, text: (a.innerText || a.getAttribute('aria-label') || '').trim() }))"""
        )
        for link in links:
            key = group_key(link["href"])
            if not key:
                continue
            name = next((line.strip() for line in link["text"].splitlines() if line.strip()), None)
            entry = found.setdefault(key, {"url": f"https://www.facebook.com/groups/{key}/", "name": None})
            # Link đầu tiên của một nhóm có khi chỉ là ảnh đại diện (không có chữ) — lấy tên ở link sau.
            if not entry["name"] and name:
                entry["name"] = name[:255]

        unchanged = unchanged + 1 if len(found) == before else 0
        page.mouse.wheel(0, 4000)
        page.wait_for_timeout(random.randint(1500, 2500))

    log(f"Tìm thấy {len(found)} nhóm.")
    return list(found.values())
