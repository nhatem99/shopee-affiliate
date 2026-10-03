<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Cầu nối Zalo cũ chỉ nhận đường dẫn file cục bộ (/send-attachment với trường 'paths'),
 * chưa hỗ trợ trường 'urls' (tải về từ URL từ xa). Ném ngoại lệ này để caller rơi về
 * chế độ gửi text-only thay vì báo lỗi hẳn — nhóm vẫn nhận được nội dung, chỉ thiếu ảnh.
 */
class BridgeUrlsNotSupportedException extends RuntimeException {}
