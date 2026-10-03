#!/data/data/com.termux/files/usr/bin/bash
# Mở Termux:X11 + Chromium có giao diện, bật cổng điều khiển 127.0.0.1:9222 cho poster.mjs nối vào.
# Không chạy headless: Chromium headless tự xưng "HeadlessChrome" — Facebook nhận ra ngay.
set -u

PORT="${FB_CDP_PORT:-9222}"
PROFILE="${FB_CHROMIUM_PROFILE:-$HOME/.local/share/tietkiemvi-fb-runner/chromium-profile}"
export DISPLAY="${DISPLAY:-:0}"

if curl -s "http://127.0.0.1:$PORT/json/version" >/dev/null 2>&1; then
  echo "Chromium đã chạy sẵn ở cổng $PORT."
  exit 0
fi

if ! pgrep -f "termux-x11" >/dev/null; then
  termux-x11 "$DISPLAY" >/dev/null 2>&1 &
  sleep 3
fi

BIN="$(command -v chromium-browser || command -v chromium || true)"
if [ -z "$BIN" ]; then
  echo "Chưa cài Chromium: pkg install x11-repo && pkg install chromium" >&2
  exit 1
fi

mkdir -p "$PROFILE"
# --no-sandbox: Android không cho Chromium trong Termux dựng sandbox.
# --remote-debugging-port chỉ nghe 127.0.0.1 (mặc định của Chromium), và không bật
# navigator.webdriver (chỉ --enable-automation/--headless/--remote-debugging-pipe bật) — nên
# KHÔNG thêm --disable-blink-features=AutomationControlled: thừa, lại hiện thanh cảnh báo vàng.
"$BIN" \
  --no-sandbox \
  --no-first-run \
  --no-default-browser-check \
  --lang=vi-VN \
  --user-data-dir="$PROFILE" \
  --remote-debugging-port="$PORT" \
  --window-size=1280,900 \
  https://www.facebook.com/ >"$HOME/.local/share/tietkiemvi-fb-runner/chromium.log" 2>&1 &

for _ in $(seq 1 30); do
  if curl -s "http://127.0.0.1:$PORT/json/version" >/dev/null 2>&1; then
    echo "Chromium sẵn sàng ở cổng $PORT. Mở app Termux:X11 để xem cửa sổ."
    exit 0
  fi
  sleep 1
done
echo "Chromium không mở được cổng $PORT — xem ~/.local/share/tietkiemvi-fb-runner/chromium.log" >&2
exit 1
