// Danh sách mục điều hướng trong khu vực Tài khoản — dùng chung giữa AccountLayout.vue (sidebar
// tĩnh trên trang /profile*) và AccountDrawer.vue (menu trượt từ trái, mở từ icon hamburger trên
// mọi trang). Gom một chỗ để thêm/sửa mục chỉ cần sửa một nơi, hai UI không lệch nhau.
export const accountNavItems = [
    { href: '/profile', icon: '📄', label: 'Tổng quan', badge: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300' },
    { href: '/profile/thong-tin', icon: '🏦', label: 'Thông tin cá nhân', badge: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' },
    { href: '/don-hang', icon: '🛒', label: 'Lịch sử đơn hàng', badge: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300' },
    { href: '/history', icon: '🕐', label: 'Sản phẩm vừa xem', badge: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300' },
    { href: '/vi/lich-su', icon: '💳', label: 'Lịch sử số dư ví', badge: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' },
    { href: '/thong-bao', icon: '🔔', label: 'Thông báo', badge: 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-300' },
    { href: '/profile/mat-khau', icon: '🔒', label: 'Mật khẩu & Bảo mật', badge: 'bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300' },
]

// '/profile' phải khớp tuyệt đối (mọi href khác đều bắt đầu bằng '/profile/' nên startsWith
// sẽ luôn sáng đèn "Tổng quan" cùng lúc với mục đang đứng, ví dụ /profile/thong-tin sáng cả hai).
export function isAccountNavActive(currentUrl, href) {
    return href === '/profile' ? currentUrl === href : currentUrl.startsWith(href)
}
