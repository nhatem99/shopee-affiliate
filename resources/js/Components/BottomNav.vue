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
        // Trang mã đứng ngay cạnh Trang chủ: đây là lối vào KHÔNG cần dán link sản phẩm,
        // dùng được cả với khách vãng lai lẫn khách chưa có sẵn thứ muốn mua.
        { href: '/ma-giam-gia', icon: '🎟️', label: 'Mã giảm giá' },
        { href: '/flashsale', icon: '⚡', label: 'Flash Sale' },
        // Khách vãng lai trước đây chỉ sinh đúng MỘT mục, chiếm nguyên chiều ngang trông như
        // thanh bị lỗi. Mục này vừa lấp chỗ đó vừa đưa đúng nhóm cần đọc nhất tới phần giải
        // thích — họ là người sẽ mất tiền nếu bấm mua mà chưa đăng nhập.
        // Khách đã đăng nhập không cần: họ đã có Lịch sử + Tài khoản, và số dư nói rõ hơn.
        ...(cashbackOn.value && !auth.isLoggedIn ? [
            { href: '/hoan-tien', icon: '💰', label: 'Hoàn tiền' },
        ] : []),
        ...(auth.isLoggedIn ? [
            { href: '/history', icon: '📋', label: 'Lịch sử' },
            // Chỗ khách đi tìm câu trả lời "mua rồi, tiền đâu" — để cạnh ví chứ không giấu
            // trong trang Tài khoản.
            ...(cashbackOn.value ? [{ href: '/don-hang', icon: '🧾', label: 'Đơn hàng' }] : []),
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
            class="flex-1 flex flex-col items-center justify-center py-2 gap-0.5 text-[10.5px] font-semibold transition min-w-0 px-0.5"
            :class="current === item.href ? 'text-[var(--color-accent)] dark:drop-shadow-[0_0_8px_rgba(var(--color-accent-rgb),0.5)]' : 'text-[var(--color-muted)]'"
        >
            <span
                class="flex flex-col items-center justify-center gap-0.5 px-3 py-1 rounded-xl transition-colors"
                :class="current === item.href ? 'bg-[var(--color-peach-soft)]' : ''"
            >
                <span class="text-xl">{{ item.icon }}</span>
                <!-- whitespace-nowrap: có tới 6 mục khi khách vừa đăng nhập vừa bật hoàn tiền
                     (Trang chủ/Mã giảm giá/Flash Sale/Lịch sử/Đơn hàng/Tài khoản) — "Mã giảm
                     giá" từng vỡ xuống 2 dòng ở đó, đẩy lệch icon so với các mục còn lại. Ép
                     một dòng + thu cỡ chữ (text-[10.5px]) thay vì rút ngắn chữ, để nhãn khớp
                     với nav desktop và H1 của từng trang. -->
                <span class="whitespace-nowrap">{{ item.label }}</span>
            </span>
        </Link>
    </nav>
</template>
