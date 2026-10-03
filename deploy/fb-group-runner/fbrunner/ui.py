"""Chữ trên giao diện Facebook mà bot dựa vào (tiếng Việt + tiếng Anh). Facebook đổi giao diện thì
thường chỉ cần sửa ở đây. Đã kiểm tra thật ngày 03/10/2026 trên nhóm công khai và nhóm riêng tư."""

import re

# Ô "Bạn viết gì đi..." trên trang nhóm; nhóm công khai có khi ghi "Tạo bài viết công khai...".
COMPOSER = re.compile(r"^\s*(Bạn viết gì đi|Viết gì đó|Tạo bài viết công khai|Write something|Create a public post)", re.I)
POST_BUTTON = re.compile(r"^\s*(Đăng|Post)\s*$", re.I)
NEXT_BUTTON = re.compile(r"^\s*(Tiếp|Next)\s*$", re.I)
PHOTO_BUTTON = re.compile(r"^\s*(Ảnh/video|Photo/video)\s*$", re.I)
JOIN_BUTTON = re.compile(r"^\s*(Tham gia nhóm|Join group)\s*$", re.I)

# Sau khi bấm Đăng ở nhóm bắt duyệt bài.
APPROVAL = re.compile(
    r"(chờ (quản trị viên )?(phê )?duyệt|đã gửi để phê duyệt|pending (admin )?approval|sent for approval|submitted for (admin )?approval)",
    re.I,
)
# Facebook chặn tạm tính năng đăng bài — tín hiệu nguy hiểm nhất, phải dừng hẳn.
BLOCKED = re.compile(
    r"(tạm thời bị chặn|bạn đang bị chặn|bị hạn chế đăng|temporarily blocked|you can't use this feature|we limit how often|giới hạn tần suất)",
    re.I,
)
ADMIN_ONLY = re.compile(r"(chỉ quản trị viên (và người kiểm duyệt )?(mới )?(có thể|được) đăng|only admins (and moderators )?can post)", re.I)

# Các lớp phủ để đọc thông báo (không đọc cả trang: bài trong nhóm có thể chứa đúng mấy chữ trên).
OVERLAYS = '[role="dialog"], [role="alertdialog"], [role="alert"], [role="status"]'
COMPOSER_TEXTBOX = 'div[role="dialog"] div[role="textbox"][contenteditable="true"]'
