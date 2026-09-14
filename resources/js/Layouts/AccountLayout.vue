<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useAuthStore } from '@/Stores/useAuthStore'

// Khung dùng chung cho mọi trang trong mục Tài khoản: Tổng quan, Thông tin cá nhân, Mật khẩu,
// Lịch sử số dư ví, Thông báo. Vẫn nằm trong AppLayout (giữ header + BottomNav của cả site)
// chứ không tự vẽ header riêng như AdminLayout — khách đang ở trang khách, không phải trang
// quản trị, đổi hẳn khung ngoài là mất luôn cảm giác "vẫn ở web hoàn tiền".
//
// Trước đây mỗi trang trong này tự chỉnh cỡ chữ để không đơn cùng "Đăng xuất" bị nhét luôn vào
// Profile.vue, tài khoản dài dần thành một trang cuộn bất tận. Tách theo đúng mẫu sidebar của
// AdminLayout.vue: một danh sách mục cố định, thêm trang mới chỉ cần thêm một dòng vào navItems.
const page = usePage()
const auth = useAuthStore()
const current = computed(() => page.url)

const navItems = [
    { href: '/profile', icon: '🧾', label: 'Tổng quan' },
    { href: '/profile/thong-tin', icon: '🏦', label: 'Thông tin cá nhân' },
    { href: '/profile/mat-khau', icon: '🔒', label: 'Mật khẩu & Bảo mật' },
    { href: '/vi/lich-su', icon: '💳', label: 'Lịch sử số dư ví' },
    { href: '/thong-bao', icon: '🔔', label: 'Thông báo' },
]

// '/profile' phải khớp tuyệt đối (mọi href khác đều bắt đầu bằng '/profile/' nên startsWith
// sẽ luôn sáng đèn "Tổng quan" cùng lúc với mục đang đứng, ví dụ /profile/thong-tin sáng cả hai).
function isActive(href) {
    return href === '/profile' ? current.value === href : current.value.startsWith(href)
}
</script>

<template>
    <AppLayout>
        <div class="max-w-5xl mx-auto px-4 py-8 flex flex-col md:flex-row gap-6 items-start">
            <!-- Sidebar: thẻ người dùng + điều hướng + đăng xuất cuối cùng, y hệt vị trí trong
                 AdminLayout — không đặt "Đăng xuất" ở thanh trên cùng vì nó luôn hiện bất kể màn
                 hình rộng hẹp, trên điện thoại từng đẩy chuông thông báo lệch khỏi mép phải. -->
            <aside class="w-full md:w-64 flex-none md:sticky md:top-20">
                <div class="card-glass rounded-2xl p-4">
                    <div class="flex items-center gap-3 pb-4 mb-2 border-b border-[var(--color-line)]">
                        <div class="flex-none w-10 h-10 rounded-full bg-gradient-to-br from-[var(--color-accent)] to-[var(--color-accent-deep)] flex items-center justify-center text-white text-sm font-extrabold">
                            {{ (auth.user?.name || auth.user?.email || '?').charAt(0).toUpperCase() }}
                        </div>
                        <div class="min-w-0">
                            <p class="font-bold text-sm text-[var(--color-ink)] truncate">{{ auth.user?.name }}</p>
                            <p class="text-xs text-[var(--color-muted)] truncate">{{ auth.user?.email }}</p>
                        </div>
                    </div>

                    <!-- Cuộn ngang trên điện thoại (danh sách xếp hàng), cuộn dọc bình thường
                         trên desktop (xếp cột) — 5 mục chưa cần drawer trượt như AdminLayout. -->
                    <nav class="flex md:flex-col gap-1 overflow-x-auto md:overflow-visible -mx-1 px-1 pb-1 md:pb-0">
                        <Link
                            v-for="item in navItems"
                            :key="item.href"
                            :href="item.href"
                            class="flex-none md:flex-1 flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-semibold whitespace-nowrap transition"
                            :class="isActive(item.href)
                                ? 'bg-[var(--color-peach-soft)] text-[var(--color-accent)]'
                                : 'text-[var(--color-muted)] hover:bg-[var(--color-peach-soft)] hover:text-[var(--color-ink)]'"
                        >
                            <span>{{ item.icon }}</span>{{ item.label }}
                        </Link>
                    </nav>

                    <div class="mt-2 pt-3 border-t border-[var(--color-line)]">
                        <button
                            type="button"
                            @click="auth.logout()"
                            class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-semibold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30 transition"
                        >
                            <span>↩</span> Đăng xuất
                        </button>
                    </div>
                </div>
            </aside>

            <div class="flex-1 min-w-0 w-full">
                <slot />
            </div>
        </div>
    </AppLayout>
</template>
