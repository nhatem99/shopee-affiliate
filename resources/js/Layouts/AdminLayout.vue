<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { useAuthStore } from '@/Stores/useAuthStore'
import ToastContainer from '@/Components/ToastContainer.vue'
import ThemeToggle from '@/Components/ThemeToggle.vue'

const page = usePage()
const auth = useAuthStore()
const current = computed(() => page.url)

const mobileOpen = ref(false)
// Đóng sidebar mobile mỗi khi chuyển trang, tránh bị kẹt mở đè lên nội dung mới.
watch(current, () => { mobileOpen.value = false })

const navItems = [
    { href: '/admin/dashboard', icon: '📊', label: 'Dashboard' },
    { href: '/admin/activities', icon: '🕵️', label: 'Theo dõi' },
    { href: '/admin/orders', icon: '📦', label: 'Đơn hàng' },
    { href: '/admin/withdrawals', icon: '💸', label: 'Rút tiền' },
    { href: '/admin/vouchers', icon: '🎫', label: 'Voucher FB/YT' },
    { href: '/admin/voucher-buttons', icon: '🔘', label: 'Nút Voucher' },
    { href: '/admin/api-config', icon: '⚙️', label: 'Cấu hình API' },
    { href: '/admin/settings', icon: '🔧', label: 'Cài đặt' },
    { href: '/admin/blocked-ips', icon: '🚫', label: 'Chặn IP' },
    { href: '/admin/logs', icon: '🐞', label: 'Nhật ký lỗi' },
]
</script>

<template>
    <div class="flex min-h-screen bg-[var(--color-bg)]">
        <!-- Lớp phủ tối phía sau sidebar khi mở trên mobile, bấm vào để đóng -->
        <div
            v-if="mobileOpen"
            @click="mobileOpen = false"
            class="fixed inset-0 bg-black/60 z-40 md:hidden"
        ></div>

        <!-- Sidebar: cố định hiện trên desktop, dạng drawer trượt trên mobile -->
        <aside
            class="w-[248px] flex-none bg-[var(--color-side)] text-white flex flex-col py-6 px-4 fixed h-screen z-50 transition-transform duration-200 md:translate-x-0"
            :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <Link href="/" class="flex items-center gap-2 font-extrabold text-lg text-white mb-10 px-2">
                <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-[var(--color-accent)] to-[var(--color-accent-deep)] flex items-center justify-center text-base font-extrabold">%</span>
                Mã Giảm Giá
            </Link>

            <nav class="flex-1 space-y-1">
                <Link
                    v-for="item in navItems"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold transition"
                    :class="current.startsWith(item.href) && item.href !== '/'
                        ? 'bg-[var(--color-side-soft)] text-white'
                        : 'text-white/60 hover:text-white hover:bg-[var(--color-side-soft)]'"
                >
                    <span>{{ item.icon }}</span>
                    {{ item.label }}
                </Link>
            </nav>

            <div class="border-t border-white/10 pt-4 px-2">
                <p class="text-xs text-white/40 mb-1">Đăng nhập với tư cách</p>
                <p class="text-sm font-semibold text-white">{{ auth.user?.name }}</p>
                <button @click="auth.logout()" class="mt-2 text-xs text-white/50 hover:text-white transition">Đăng xuất</button>
            </div>
        </aside>

        <!-- Main content -->
        <div class="flex-1 md:ml-[248px] flex flex-col min-h-screen min-w-0">
            <header class="sticky top-0 z-30 bg-[var(--color-surface)] border-b border-[var(--color-line)] px-4 md:px-6 h-14 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <button
                        @click="mobileOpen = true"
                        class="md:hidden flex-none w-9 h-9 rounded-lg flex items-center justify-center text-[var(--color-ink)] hover:bg-[var(--color-line)] transition"
                        aria-label="Mở menu"
                    >
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <h1 class="font-bold text-[var(--color-ink)] text-base truncate">
                        <slot name="title">Admin</slot>
                    </h1>
                </div>
                <div class="flex items-center gap-3 flex-none">
                    <span class="hidden sm:block text-sm text-[var(--color-muted)]">{{ new Date().toLocaleDateString('vi-VN') }}</span>
                    <ThemeToggle />
                </div>
            </header>
            <main class="flex-1 p-4 md:p-6 min-w-0">
                <slot />
            </main>
        </div>
        <ToastContainer />
    </div>
</template>
