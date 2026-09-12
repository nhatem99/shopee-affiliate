<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useAuthStore } from '@/Stores/useAuthStore'
import { useCashback } from '@/composables/useCashback'

const page = usePage()
const auth = useAuthStore()
const current = computed(() => page.url)
const { cashbackOn } = useCashback()

const items = computed(() => [
    ...(auth.isAdmin ? [] : [
        { href: '/', icon: '⌂', label: 'Trang chủ' },
        // Khách vãng lai trước đây chỉ sinh đúng MỘT mục, chiếm nguyên chiều ngang trông như
        // thanh bị lỗi. Mục này vừa lấp chỗ đó vừa đưa đúng nhóm cần đọc nhất tới phần giải
        // thích — họ là người sẽ mất tiền nếu bấm mua mà chưa đăng nhập.
        // Khách đã đăng nhập không cần: họ đã có Lịch sử + Tài khoản, và số dư nói rõ hơn.
        ...(cashbackOn.value && !auth.isLoggedIn ? [
            { href: '/hoan-tien', icon: '💰', label: 'Hoàn tiền' },
        ] : []),
        ...(auth.isLoggedIn ? [
            { href: '/history', icon: '📋', label: 'Lịch sử' },
            { href: '/profile', icon: '👤', label: 'Tài khoản' },
        ] : []),
    ]),
])
</script>

<template>
    <nav v-if="items.length" class="md:hidden fixed bottom-0 left-0 right-0 z-50 bg-[var(--color-surface)]/95 backdrop-blur-md border-t border-[var(--color-line)] flex safe-area-inset-bottom dark:shadow-[0_-4px_24px_rgba(0,0,0,0.4)]">
        <Link
            v-for="item in items"
            :key="item.href"
            :href="item.href"
            class="flex-1 flex flex-col items-center justify-center py-3 gap-0.5 text-xs font-semibold transition"
            :class="current === item.href ? 'text-[var(--color-accent)] dark:drop-shadow-[0_0_8px_rgba(var(--color-accent-rgb),0.5)]' : 'text-[var(--color-muted)]'"
        >
            <span class="text-xl">{{ item.icon }}</span>
            <span>{{ item.label }}</span>
        </Link>
    </nav>
</template>
