#!/data/data/com.termux/files/usr/bin/bash
# Chạy bot đăng nhóm Facebook trên điện thoại, tự chạy lại khi bị tắt.
# Chép vào ~/.termux/boot/ để Termux:Boot bật lên mỗi lần khởi động máy (xem README).
set -u

APP_DIR="${FB_POSTER_DIR:-$HOME/shopee-affiliate/tools/fb-group-poster}"
LOG_DIR="$HOME/.local/share/tietkiemvi-fb-runner/logs"
mkdir -p "$LOG_DIR"

# Giữ CPU thức khi tắt màn hình — không có cái này Android sẽ cho Termux ngủ.
termux-wake-lock

sleep 20  # đợi wifi lên sau khi khởi động máy

while true; do
  # Gọi qua bash: không phụ thuộc quyền thực thi của file (git checkout có thể làm mất).
  if ! bash "$APP_DIR/phone/start-chromium.sh" >>"$LOG_DIR/boot.log" 2>&1; then
    sleep 60
    continue
  fi

  (cd "$APP_DIR" && node poster.mjs run) >>"$LOG_DIR/boot.log" 2>&1
  code=$?

  if [ "$code" -eq 2 ]; then
    # Server từ chối token: chạy lại liên tục cũng vô ích — đợi 1 giờ cho bạn kịp dán token mới.
    echo "[$(date '+%F %T')] Token bị từ chối — đợi 1 giờ." >>"$LOG_DIR/boot.log"
    sleep 3600
  else
    sleep 30
  fi
done
