<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useAuthStore } from '@/Stores/useAuthStore'

const page = usePage()
const auth = useAuthStore()
const current = computed(() => page.url)

const navItems = [
    { href: '/admin/dashboard', icon: '📊', label: 'Dashboard' },
    { href: '/admin/orders', icon: '📦', label: 'Đơn hàng' },
    { href: '/admin/api-config', icon: '⚙️', label: 'Cấu hình API' },
    { href: '/', icon: '🌐', label: 'Trang chủ' },
]
</script>

<template>
    <div class="flex min-h-screen bg-gray-50">
        <!-- Sidebar -->
        <aside class="w-[248px] flex-none bg-[--color-side] text-white flex flex-col py-6 px-4 fixed h-screen z-40">
            <Link href="/" class="flex items-center gap-2 font-extrabold text-lg text-white mb-10 px-2">
                <span class="w-8 h-8 rounded-lg bg-[--color-accent] flex items-center justify-center text-sm">M</span>
                Mã Giảm Giá
            </Link>

            <nav class="flex-1 space-y-1">
                <Link
                    v-for="item in navItems"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold transition"
                    :class="current.startsWith(item.href) && item.href !== '/'
                        ? 'bg-[--color-side-soft] text-white'
                        : 'text-white/60 hover:text-white hover:bg-[--color-side-soft]'"
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
        <div class="flex-1 ml-[248px] flex flex-col min-h-screen">
            <header class="sticky top-0 z-30 bg-white border-b border-gray-200 px-6 h-14 flex items-center justify-between">
                <h1 class="font-bold text-gray-800 text-base">
                    <slot name="title">Admin</slot>
                </h1>
                <div class="text-sm text-gray-500">{{ new Date().toLocaleDateString('vi-VN') }}</div>
            </header>
            <main class="flex-1 p-6">
                <slot />
            </main>
        </div>
    </div>
</template>
