"""Gọi server tietkiemvi: hỏi việc, báo kết quả, gửi danh sách nhóm. Chỉ dùng thư viện chuẩn."""

import json
import time
import urllib.error
import urllib.request

from . import VERSION


class AuthError(Exception):
    """Server từ chối token — dừng hẳn, chạy tiếp cũng vô ích."""


class ApiError(Exception):
    pass


class Api:
    def __init__(self, base_url: str, token: str, timeout: int = 90):
        self.base_url = base_url.rstrip("/")
        self._token = token
        # Lượt nhận bài ở chế độ mã YTB mất tới ~45 giây phía server.
        self.timeout = timeout

    def _post(self, path: str, payload: dict) -> tuple[int, dict]:
        request = urllib.request.Request(
            self.base_url + path,
            data=json.dumps(payload).encode("utf-8"),
            method="POST",
            headers={
                "Content-Type": "application/json",
                "Accept": "application/json",
                "User-Agent": f"tietkiemvi-fb-runner/{VERSION}",
                "X-Runner-Token": self._token,
            },
        )
        try:
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                return response.status, _json(response.read())
        except urllib.error.HTTPError as error:
            if error.code == 403:
                raise AuthError("Server từ chối token (403) — kiểm tra token trong config.json hoặc tạo token mới.") from None
            return error.code, _json(error.read())
        except (urllib.error.URLError, TimeoutError, ConnectionError, OSError) as error:
            raise ApiError(f"Không gọi được server: {error}") from None

    def poll(self, claim_key: str, state: str, account: str | None) -> dict:
        status, data = self._post(
            "/runner/fb/poll",
            {"claim_key": claim_key, "state": state, "version": VERSION, "account": account},
        )
        if status != 200:
            raise ApiError(f"Hỏi việc lỗi HTTP {status}: {data.get('message', '')}")
        return data

    def report(self, post_id: int, claim_key: str, status: str, error: str | None) -> int:
        """Báo kết quả, thử lại tới khi server nhận. 409 = lượt này server đã chốt/không khớp."""
        payload = {"claim_key": claim_key, "status": status, "error": (error or None) and error[:1000]}
        last_error: Exception | None = None
        for attempt in range(6):
            try:
                code, data = self._post(f"/runner/fb/posts/{post_id}/result", payload)
                if code in (200, 404, 409):
                    return code
                if code == 422:
                    raise ApiError(f"Server không nhận kết quả: {data}")
                last_error = ApiError(f"HTTP {code}")
            except ApiError as error:
                last_error = error
            time.sleep(min(60, 5 * 2**attempt))
        raise ApiError(f"Không báo được kết quả bài #{post_id}: {last_error}")

    def upload_groups(self, groups: list[dict], account: str | None) -> dict:
        status, data = self._post("/runner/fb/groups", {"groups": groups, "account": account})
        if status != 200:
            raise ApiError(f"Gửi danh sách nhóm lỗi HTTP {status}: {data}")
        return data


def _json(body: bytes) -> dict:
    try:
        data = json.loads(body or b"{}")
        return data if isinstance(data, dict) else {}
    except ValueError:
        return {"message": body[:200].decode("utf-8", "replace")}
