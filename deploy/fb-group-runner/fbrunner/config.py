"""Cấu hình bot: ~/.config/tietkiemvi-fb-runner/config.json (chứa token — phải chmod 600)."""

import json
import os
import stat
from dataclasses import dataclass
from pathlib import Path

CONFIG_PATH = Path(os.environ.get("FB_RUNNER_CONFIG", "~/.config/tietkiemvi-fb-runner/config.json")).expanduser()
DEFAULT_DATA_DIR = Path(os.environ.get("FB_RUNNER_DATA", "~/.local/share/tietkiemvi-fb-runner")).expanduser()


class ConfigError(Exception):
    pass


@dataclass
class Config:
    base_url: str
    token: str
    data_dir: Path
    profile_dir: Path
    browser_channel: str | None
    max_sleep_seconds: int


def load(require_server: bool = True) -> Config:
    raw: dict = {}
    if CONFIG_PATH.exists():
        # Token trong file này khiến được nick Facebook của bạn đăng bài — không để ai khác đọc.
        if CONFIG_PATH.stat().st_mode & (stat.S_IRWXG | stat.S_IRWXO):
            raise ConfigError(f"{CONFIG_PATH} đang cho tài khoản khác đọc được. Chạy: chmod 600 {CONFIG_PATH}")
        try:
            raw = json.loads(CONFIG_PATH.read_text("utf-8"))
        except ValueError as error:
            raise ConfigError(f"{CONFIG_PATH} không phải JSON hợp lệ: {error}") from error
    elif require_server:
        raise ConfigError(
            f"Chưa có {CONFIG_PATH}. Vào /admin/fb-groups bấm \"Tạo token\" rồi dán nội dung vào file này (xem README)."
        )

    base_url = str(raw.get("base_url", "")).rstrip("/")
    token = str(raw.get("token", ""))
    if require_server:
        local = base_url.startswith(("http://localhost", "http://127.0.0.1"))
        if not (base_url.startswith("https://") or local) or not token:
            raise ConfigError("config.json thiếu base_url (https://...) hoặc token.")

    data_dir = Path(raw.get("data_dir") or DEFAULT_DATA_DIR).expanduser()
    return Config(
        base_url=base_url,
        token=token,
        data_dir=data_dir,
        profile_dir=Path(raw.get("profile_dir") or data_dir / "profile").expanduser(),
        browser_channel=raw.get("browser_channel") or None,
        max_sleep_seconds=int(raw.get("max_sleep_seconds") or 120),
    )
