<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

// Nút nổi dẫn vào chat hỗ trợ trong web (/ho-tro), thay chỗ của MessengerButton khi admin đã bật
// chat — xem AppLayout.vue. Số chưa đọc do HandleInertiaRequests chia sẻ.
const page = usePage()
const unread = computed(() => page.props.chat?.unread ?? 0)
// Đang ở chính trang chat thì ẩn: nút nổi lúc đó vừa thừa vừa che mất nút Gửi trên điện thoại.
const show = computed(() => !page.url.startsWith('/ho-tro'))
</script>

<template>
    <Link
        v-if="show"
        href="/ho-tro"
        aria-label="Chat với hỗ trợ"
        title="Chat với hỗ trợ"
        class="fixed right-4 md:right-6 bottom-20 md:bottom-6 z-40 w-12 h-12 md:w-14 md:h-14 rounded-full ring-4 ring-[var(--color-bg)] shadow-[0_6px_20px_rgba(var(--color-accent-rgb),0.4)] flex items-center justify-center transition-transform hover:scale-105 active:scale-95 bg-gradient-to-br from-[var(--color-accent)] to-[var(--color-accent-deep)]"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-6 h-6 md:w-7 md:h-7">
            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
        </svg>
        <span
            v-if="unread"
            class="absolute -top-1 -right-1 min-w-[20px] h-[20px] px-1 rounded-full bg-[var(--color-brand-green)] text-white text-[11px] font-extrabold flex items-center justify-center ring-2 ring-[var(--color-bg)]"
        >{{ unread > 9 ? '9+' : unread }}</span>
    </Link>
</template>
