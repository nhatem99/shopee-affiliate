<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import UserAvatar from '@/Components/UserAvatar.vue'
import { useAuthStore } from '@/Stores/useAuthStore'
import { accountNavItemsFor, isAccountNavActive } from '@/accountNavItems'

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

// Danh sách mục + logic active dùng chung với AccountDrawer.vue (menu trượt từ icon hamburger
// trên mọi trang) — xem resources/js/accountNavItems.js.
const navItems = computed(() => accountNavItemsFor(page.props.settings))

function isActive(href) {
    return isAccountNavActive(current.value, href)
}
</script>

<template>
    <AppLayout>
        <div class="max-w-5xl mx-auto px-4 py-8 flex flex-col md:flex-row gap-6 items-start">
            <!-- Sidebar: thẻ người dùng + điều hướng + đăng xuất cuối cùng, y hệt vị trí trong
                 AdminLayout — không đặt "Đăng xuất" ở thanh trên cùng vì nó luôn hiện bất kể màn
                 hình rộng hẹp, trên điện thoại từng đẩy chuông thông báo lệch khỏi mép phải.

                 Ẩn hẳn trên điện thoại (hidden md:block): AccountDrawer.vue giờ đã có cùng đúng
                 danh sách này, mở được từ MỌI trang qua icon hamburger — hiện lại y hệt ở đầu
                 /profile là bấm vào "Tài khoản" ở BottomNav để rồi thấy lại nguyên cái list vừa
                 đóng, phải cuộn qua hết mới tới nội dung Tổng quan thật. Trên desktop không có
                 hamburger (md:hidden ở AppLayout) nên sidebar này vẫn là đường DUY NHẤT tới các
                 trang con (Thông tin cá nhân, Mật khẩu...) — giữ nguyên. -->
            <aside class="hidden md:block md:w-64 flex-none md:sticky md:top-20">
                <div class="card-glass rounded-2xl p-4">
                    <div class="flex items-center gap-3 pb-4 mb-2 border-b border-[var(--color-line)]">
                        <UserAvatar
                            :src="auth.user?.avatar"
                            :name="auth.user?.name || auth.user?.email"
                            class="flex-none w-10 h-10 text-sm"
                        />
                        <div class="min-w-0">
                            <p class="font-bold text-sm text-[var(--color-ink)] truncate">{{ auth.user?.name }}</p>
                            <p class="text-xs text-[var(--color-muted)] truncate">{{ auth.user?.email }}</p>
                        </div>
                    </div>

                    <nav class="flex flex-col gap-0.5">
                        <Link
                            v-for="item in navItems"
                            :key="item.href"
                            :href="item.href"
                            class="flex items-center gap-3 px-2 py-2.5 rounded-xl text-sm font-semibold transition"
                            :class="isActive(item.href)
                                ? 'bg-[var(--color-peach-soft)] text-[var(--color-accent)]'
                                : 'text-[var(--color-ink)] hover:bg-[var(--color-peach-soft)]/60'"
                        >
                            <span
                                class="flex-none w-9 h-9 rounded-full flex items-center justify-center text-base"
                                :class="item.badge"
                            >{{ item.icon }}</span>
                            {{ item.label }}
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
