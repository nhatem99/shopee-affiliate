// Chữ trên giao diện Facebook mà bot dựa vào (tiếng Việt + tiếng Anh). Facebook đổi giao diện thì
// thường chỉ cần sửa ở đây. Giữ đồng bộ với deploy/fb-group-runner/fbrunner/ui.py.

export const COMPOSER = /^\s*(Bạn viết gì đi|Viết gì đó|Tạo bài viết công khai|Write something|Create a public post)/i;
export const POST_BUTTON = /^\s*(Đăng|Post)\s*$/i;
export const NEXT_BUTTON = /^\s*(Tiếp|Next)\s*$/i;
export const PHOTO_BUTTON = /^\s*(Ảnh\/video|Photo\/video)\s*$/i;
export const JOIN_BUTTON = /^\s*(Tham gia nhóm|Join group)\s*$/i;
// Trên trang của một Trang mình quản trị (đang dùng nick chính): nút chuyển sang dùng Trang đó.
export const SWITCH_BUTTON = /^\s*(Chuyển ngay|Chuyển|Switch now|Switch)\s*$/i;
// Bấm nút trên xong Facebook có thể hỏi lại trong hộp thoại ("Chuyển sang <Trang>?") — nút xác nhận.
// Chỉ tìm trong hộp thoại nên nới hơn SWITCH_BUTTON được.
export const SWITCH_CONFIRM = /^\s*(Chuyển( ngay| trang cá nhân| sang .{1,80})?|Switch( now| profiles?| to .{1,80})?)\s*$/i;

// Sau khi bấm Đăng ở nhóm bắt duyệt bài.
export const APPROVAL = /(chờ (quản trị viên )?(phê )?duyệt|đã gửi để phê duyệt|pending (admin )?approval|sent for approval|submitted for (admin )?approval)/i;
// Facebook chặn tạm tính năng đăng bài — tín hiệu nguy hiểm nhất, phải dừng hẳn.
export const BLOCKED = /(tạm thời bị chặn|bạn đang bị chặn|bị hạn chế đăng|temporarily blocked|you can't use this feature|we limit how often|giới hạn tần suất)/i;
// Trang con trong nhóm không mở được (đường dẫn Facebook đổi, nhóm không cho xem...).
export const UNAVAILABLE = /(nội dung này hiện không (hiển thị|khả dụng)|trang này không (hiển thị|khả dụng)|không xem được nội dung này|this content isn't available|this page isn't available)/i;
export const ADMIN_ONLY = /(chỉ quản trị viên (và người kiểm duyệt )?(mới )?(có thể|được) đăng|only admins (and moderators )?can post)/i;

// Các lớp phủ để đọc thông báo (không đọc cả trang: bài trong nhóm có thể chứa đúng mấy chữ trên).
export const OVERLAYS = '[role="dialog"], [role="alertdialog"], [role="alert"], [role="status"]';
export const COMPOSER_TEXTBOX = 'div[role="dialog"] div[role="textbox"][contenteditable="true"]';
