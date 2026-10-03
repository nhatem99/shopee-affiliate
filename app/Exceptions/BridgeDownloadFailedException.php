<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Cầu nối Zalo trả 422 — ít nhất một URL ảnh không tải được (HTTP 4xx/5xx, không phải
 * image/*, quá 15 MB, timeout...). Lỗi này mang tính xác định: cùng URL sẽ fail lần sau.
 * Caller rơi về chế độ text-only thay vì retry gửi ảnh.
 */
class BridgeDownloadFailedException extends RuntimeException {}
