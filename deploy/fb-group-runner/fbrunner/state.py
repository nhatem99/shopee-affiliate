"""Trạng thái lưu đĩa để bot tắt giữa chừng (Ctrl+C, mất điện, launchd khởi động lại) vẫn báo
đúng kết quả bài đang dở — thà báo "không rõ" còn hơn để server tưởng chưa đăng rồi đăng lại."""

import json
import os
import uuid
from pathlib import Path


class State:
    def __init__(self, path: Path):
        self.path = path
        self.data: dict = {}
        if path.exists():
            try:
                self.data = json.loads(path.read_text("utf-8"))
            except ValueError:
                self.data = {}

    @property
    def claim_key(self) -> str:
        """Key của lượt hỏi việc hiện tại — giữ nguyên qua các lần khởi động lại cho tới khi
        xong bài, để server trả lại đúng bài đã giao nếu bot chết sau khi nhận."""
        if not self.data.get("claim_key"):
            self.new_claim_key()
        return self.data["claim_key"]

    def new_claim_key(self) -> None:
        self.data["claim_key"] = uuid.uuid4().hex
        self.save()

    @property
    def inflight(self) -> dict | None:
        return self.data.get("inflight")

    def start(self, post_id: int, claim_key: str) -> None:
        self.data["inflight"] = {"post_id": post_id, "claim_key": claim_key, "phase": "claimed"}
        self.save()

    def submitting(self) -> None:
        """Ngay trước khi bấm Đăng — từ đây bài có thể đã lên nhóm."""
        if self.inflight:
            self.inflight["phase"] = "submitting"
            self.save()

    def finish(self, status: str, error: str | None) -> None:
        if self.inflight:
            self.inflight.update({"final_status": status, "error": error})
            self.save()

    def clear(self) -> None:
        self.data.pop("inflight", None)
        self.save()

    def save(self) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        tmp = self.path.with_suffix(".tmp")
        tmp.write_text(json.dumps(self.data, ensure_ascii=False), "utf-8")
        os.replace(tmp, self.path)
