"""Tải ảnh của bài về file JPEG tạm để đính kèm. Chỉ hai nguồn:
  • ảnh sản phẩm trên CDN ảnh của Shopee (link https đầy đủ);
  • ảnh admin tự tải lên ở /admin/fb-posts — server gửi đường dẫn /runner/fb/images/<tên>, bot
    tải từ chính server đã cấu hình, kèm token.
Không tải link lạ nào khác — server có bị chiếm quyền cũng không biến bot thành máy đi tải bậy."""

from __future__ import annotations

import io
import re
import tempfile
import urllib.request
from pathlib import Path
from typing import TYPE_CHECKING
from urllib.parse import urlparse

from PIL import Image

from . import VERSION

if TYPE_CHECKING:
    from .api import Api

ALLOWED_HOSTS = ("cf.shopee.vn",)
ALLOWED_SUFFIXES = (".susercontent.com",)
MAX_BYTES = 10 * 1024 * 1024

# Trùng FacebookPostImages::MAX_PER_POST và NAME_PATTERN phía server.
MAX_IMAGES = 5
UPLOAD_PATH = re.compile(r"^/runner/fb/images/[a-f0-9]{32}\.(?:jpg|png|webp)\Z")


def is_upload(ref: str) -> bool:
    return bool(UPLOAD_PATH.match(str(ref)))


def fetch_as_jpeg(ref: str, api: Api, directory: Path) -> Path:
    """Một ảnh trong danh sách "images" của lượt nhận bài → file JPEG tạm."""
    if is_upload(ref):
        return _to_jpeg(api.download(ref, MAX_BYTES), directory)
    return download_as_jpeg(ref, directory)


def allowed(url: str) -> bool:
    parsed = urlparse(url)
    host = (parsed.hostname or "").lower()
    return parsed.scheme == "https" and (host in ALLOWED_HOSTS or host.endswith(ALLOWED_SUFFIXES))


def download_as_jpeg(url: str, directory: Path) -> Path:
    if not allowed(url):
        raise ValueError(f"Ảnh không nằm trên CDN Shopee, bỏ qua: {url[:120]}")

    request = urllib.request.Request(url, headers={"User-Agent": f"Mozilla/5.0 tietkiemvi-fb-runner/{VERSION}"})
    with urllib.request.urlopen(request, timeout=30) as response:
        body = response.read(MAX_BYTES + 1)
    if len(body) > MAX_BYTES:
        raise ValueError("Ảnh lớn hơn 10 MB")
    return _to_jpeg(body, directory)


def _to_jpeg(body: bytes, directory: Path) -> Path:
    # Shopee hay trả .webp — đổi sang JPEG cho chắc Facebook nhận.
    image = Image.open(io.BytesIO(body))
    image = image.convert("RGB")
    directory.mkdir(parents=True, exist_ok=True)
    handle = tempfile.NamedTemporaryFile(prefix="deal-", suffix=".jpg", dir=directory, delete=False)
    handle.close()
    image.save(handle.name, "JPEG", quality=90)
    return Path(handle.name)
