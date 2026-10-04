#!/usr/bin/env python3
"""Bot đăng deal vào nhóm Facebook cho tietkiemvi. Xem README.md.

  python runner.py login                 đăng nhập Facebook một lần (mở cửa sổ Chromium)
  python runner.py run                   chạy thật: hỏi server có bài thì đăng
  python runner.py sync                  lấy danh sách nhóm đã tham gia gửi lên server
  python runner.py dry-run --group URL   thử trên một nhóm: điền hết nhưng KHÔNG bấm Đăng
                                         (thêm --image <ảnh> tối đa 5 lần để thử kèm ảnh)
"""

import argparse
import random
import signal
import sys
import time
from datetime import datetime
from pathlib import Path

from playwright.sync_api import Error as PlaywrightError
from playwright.sync_api import sync_playwright

from fbrunner import VERSION
from fbrunner import config as config_module
from fbrunner.api import Api, ApiError, AuthError
from fbrunner.browser import account_id, launch
from fbrunner.groups import ScrapeError, scrape_joined_groups
from fbrunner.images import MAX_IMAGES, download_as_jpeg, fetch_as_jpeg, is_upload
from fbrunner.poster import Result, post_to_group
from fbrunner.state import State

_log_file: Path | None = None


def log(message: str) -> None:
    line = f"[{datetime.now():%Y-%m-%d %H:%M:%S}] {message}"
    print(line, flush=True)
    if _log_file:
        with _log_file.open("a", encoding="utf-8") as handle:
            handle.write(line + "\n")


def account_label(uid: str | None) -> str | None:
    return f"uid {uid}" if uid else None


# ── login ────────────────────────────────────────────────────────────────────

def cmd_login(cfg: config_module.Config) -> int:
    with sync_playwright() as pw:
        context, page = launch(pw, cfg)
        page.goto("https://www.facebook.com/")
        log("Đăng nhập Facebook trong cửa sổ Chromium vừa mở (kể cả mã 2 lớp nếu có).")
        input("Thấy bảng tin Facebook rồi thì quay lại đây bấm Enter... ")
        uid = account_id(context)
        context.close()
    if not uid:
        log("Chưa thấy đăng nhập — chạy lại: python runner.py login")
        return 1
    log(f"Đã đăng nhập ({account_label(uid)}). Hồ sơ lưu ở {cfg.profile_dir}")
    return 0


# ── dry-run ──────────────────────────────────────────────────────────────────

def cmd_dry_run(cfg: config_module.Config, group: str, caption: str, images: list[str]) -> int:
    with sync_playwright() as pw:
        context, page = launch(pw, cfg)
        image_paths = [
            download_as_jpeg(image, cfg.data_dir / "tmp") if image.startswith("https://") else Path(image)
            for image in images[:MAX_IMAGES]
        ]
        result = post_to_group(page, group, caption, image_paths, dry_run=True)
        log(f"Kết quả: {result.status} {result.error or ''}")
        if result.status == "dry_run":
            input("Đã điền xong, KHÔNG bấm Đăng. Xem cửa sổ Chromium rồi bấm Enter để đóng (bài nháp bị bỏ)... ")
        context.close()
    return 0 if result.status == "dry_run" else 1


# ── sync ─────────────────────────────────────────────────────────────────────

def sync_groups(api: Api, context, page) -> None:
    uid = account_id(context)
    if not uid:
        log("Chưa đăng nhập Facebook — không lấy được nhóm.")
        return
    try:
        groups = scrape_joined_groups(page, log=log)
    except ScrapeError as error:
        log(f"Không lấy được nhóm: {error}")
        return
    if not groups:
        log("Không thấy nhóm nào trên trang Nhóm của bạn — có thể Facebook đổi giao diện.")
    result = api.upload_groups(groups, account_label(uid))
    log(f"Đã gửi {len(groups)} nhóm lên server: thêm mới {result.get('created')}, cập nhật {result.get('updated')}.")


def cmd_sync(cfg: config_module.Config) -> int:
    api = Api(cfg.base_url, cfg.token)
    with sync_playwright() as pw:
        context, page = launch(pw, cfg)
        try:
            sync_groups(api, context, page)
        finally:
            context.close()
    return 0


# ── run ──────────────────────────────────────────────────────────────────────

def flush_unreported(api: Api, state: State) -> None:
    """Bài của lượt trước chưa báo được kết quả (bot tắt giữa chừng / mất mạng) — báo trước đã."""
    inflight = state.inflight
    if not inflight:
        return

    status, error = inflight.get("final_status"), inflight.get("error")
    if not status:
        if inflight.get("phase") == "submitting":
            status, error = "ambiguous", "Bot bị tắt đúng lúc bấm Đăng — có thể bài đã lên nhóm"
        else:
            status, error = "failed", "Bot bị tắt trước khi bấm Đăng"

    log(f"Báo kết quả còn treo của bài #{inflight['post_id']}: {status}")
    api.report(inflight["post_id"], inflight["claim_key"], status, error)
    state.clear()
    state.new_claim_key()


def do_post(api: Api, state: State, cfg: config_module.Config, page, job: dict) -> None:
    post_id, claim_key = job["post_id"], job["claim_key"]
    state.start(post_id, claim_key)
    log(f"Đăng bài #{post_id} vào nhóm {job.get('group_name') or job['group_url']}")

    # Server cũ chỉ gửi image_url; server mới gửi "images" (ảnh sản phẩm + ảnh admin tự tải lên).
    refs = job["images"] if isinstance(job.get("images"), list) else ([job["image_url"]] if job.get("image_url") else [])
    image_paths: list[Path] = []
    image_error = None
    for ref in refs[:MAX_IMAGES]:
        try:
            image_paths.append(fetch_as_jpeg(ref, api, cfg.data_dir / "tmp"))
        except Exception as error:  # noqa: BLE001
            # Ảnh admin tự chọn mà thiếu thì đừng đăng — bài sẽ khác ý admin. Chưa bấm Đăng nên báo
            # "failed", admin bấm đăng lại được. Ảnh sản phẩm Shopee thì bỏ qua như trước.
            if is_upload(ref):
                image_error = f"Không tải được ảnh đã tải lên ({error}) — chưa đăng."
                break
            log(f"Không tải được ảnh sản phẩm ({error}) — đăng không kèm ảnh này.")

    try:
        if image_error:
            result = Result("failed", image_error)
        else:
            result = post_to_group(page, job["group_url"], job["caption"], image_paths, on_submitting=state.submitting)
    except PlaywrightError as error:
        submitted = (state.inflight or {}).get("phase") == "submitting"
        result = Result("ambiguous" if submitted else "failed", f"Lỗi trình duyệt: {error}")
    finally:
        for image_path in image_paths:
            image_path.unlink(missing_ok=True)

    if result.status != "posted":
        shot = cfg.data_dir / "screenshots" / f"post-{post_id}-{datetime.now():%Y%m%d-%H%M%S}.png"
        shot.parent.mkdir(parents=True, exist_ok=True)
        try:
            page.screenshot(path=str(shot), full_page=False)
            log(f"Ảnh chụp màn hình: {shot}")
        except PlaywrightError:
            pass

    state.finish(result.status, result.error)
    log(f"Kết quả bài #{post_id}: {result.status}{' — ' + result.error if result.error else ''}")
    api.report(post_id, claim_key, result.status, result.error)
    state.clear()
    state.new_claim_key()


def tick(api: Api, state: State, cfg: config_module.Config, context, page) -> int:
    """Một lượt hỏi việc. Trả số giây nên ngủ trước lượt sau."""
    flush_unreported(api, state)

    uid = account_id(context)
    job = api.poll(state.claim_key, "ok" if uid else "logged_out", account_label(uid))

    if job.get("type") == "post":
        do_post(api, state, cfg, page, job)
        return random.randint(20, 40)
    if job.get("type") == "sync_groups":
        log("Server yêu cầu lấy danh sách nhóm.")
        sync_groups(api, context, page)
        return 20

    reason = job.get("reason")
    if reason == "paused" and not uid:
        log("Nick Facebook đang bị đăng xuất — đăng nhập lại trong cửa sổ Chromium rồi bấm \"Chạy tiếp\" trên trang admin.")
    return int(job.get("retry_after") or 60)


def cmd_run(cfg: config_module.Config) -> int:
    api = Api(cfg.base_url, cfg.token)
    state = State(cfg.data_dir / "state.json")
    stopping = False

    def request_stop(*_):
        nonlocal stopping
        stopping = True
        log("Nhận lệnh dừng — thoát sau lượt đang làm.")

    signal.signal(signal.SIGTERM, request_stop)
    log(f"Bot đăng nhóm Facebook {VERSION} — server {cfg.base_url}")

    with sync_playwright() as pw:
        context, page = launch(pw, cfg)
        try:
            while not stopping:
                try:
                    wait = tick(api, state, cfg, context, page)
                except AuthError as error:
                    log(str(error))
                    time.sleep(300)
                    return 2
                except ApiError as error:
                    log(f"{error} — thử lại sau 1 phút.")
                    wait = 60

                wait = max(20, min(cfg.max_sleep_seconds, wait)) + random.randint(0, 10)
                deadline = time.time() + wait
                while not stopping and time.time() < deadline:
                    time.sleep(1)
        except KeyboardInterrupt:
            log("Dừng (Ctrl+C).")
        finally:
            try:
                context.close()
            except PlaywrightError:
                pass
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Bot đăng deal vào nhóm Facebook cho tietkiemvi")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("login", help="Đăng nhập Facebook một lần")
    sub.add_parser("run", help="Chạy thật: hỏi server có bài thì đăng")
    sub.add_parser("sync", help="Lấy danh sách nhóm đã tham gia gửi lên server")
    dry = sub.add_parser("dry-run", help="Thử trên một nhóm, KHÔNG bấm Đăng")
    dry.add_argument("--group", required=True, help="Link nhóm: https://www.facebook.com/groups/...")
    dry.add_argument("--caption", default="Bài thử (không đăng)\nDòng 2\nhttps://shopee.vn")
    dry.add_argument("--image", action="append", default=[], help="Đường dẫn ảnh trên máy hoặc link ảnh Shopee (lặp lại để thêm ảnh)")
    args = parser.parse_args()

    needs_server = args.command in ("run", "sync")
    try:
        cfg = config_module.load(require_server=needs_server)
    except config_module.ConfigError as error:
        print(error, file=sys.stderr)
        return 1

    global _log_file
    (cfg.data_dir / "logs").mkdir(parents=True, exist_ok=True)
    _log_file = cfg.data_dir / "logs" / "runner.log"

    if args.command == "login":
        return cmd_login(cfg)
    if args.command == "dry-run":
        return cmd_dry_run(cfg, args.group, args.caption, args.image)
    if args.command == "sync":
        return cmd_sync(cfg)
    return cmd_run(cfg)


if __name__ == "__main__":
    sys.exit(main())
