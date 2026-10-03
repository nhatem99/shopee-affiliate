"""Tải ảnh sản phẩm về file JPEG tạm để đính kèm bài. Chỉ tải từ CDN ảnh của Shopee — server có
bị chiếm quyền cũng không bắt được nick cá nhân đăng ảnh tuỳ ý."""

import io
import tempfile
import urllib.request
from pathlib import Path
from urllib.parse import urlparse

from PIL import Image

from . import VERSION

ALLOWED_HOSTS = ("cf.shopee.vn",)
ALLOWED_SUFFIXES = (".susercontent.com",)
MAX_BYTES = 10 * 1024 * 1024


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

    # Shopee hay trả .webp — đổi sang JPEG cho chắc Facebook nhận.
    image = Image.open(io.BytesIO(body))
    image = image.convert("RGB")
    directory.mkdir(parents=True, exist_ok=True)
    handle = tempfile.NamedTemporaryFile(prefix="deal-", suffix=".jpg", dir=directory, delete=False)
    handle.close()
    image.save(handle.name, "JPEG", quality=90)
    return Path(handle.name)
