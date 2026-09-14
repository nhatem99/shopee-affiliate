<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

const page = usePage()
// Chưa đặt link ở Admin → Cài đặt thì ẩn hẳn, không hiện nút chết dẫn về đâu cả.
const messengerUrl = computed(() => page.props.settings?.messengerUrl ?? null)
</script>

<template>
    <a
        v-if="messengerUrl"
        :href="messengerUrl"
        target="_blank"
        rel="noopener noreferrer"
        aria-label="Nhắn tin qua Messenger"
        title="Nhắn tin qua Messenger"
        class="fixed right-4 md:right-6 bottom-20 md:bottom-6 z-40 w-12 h-12 md:w-14 md:h-14 rounded-full ring-4 ring-[var(--color-bg)] shadow-[0_6px_20px_rgba(0,90,255,0.35)] flex items-center justify-center transition-transform hover:scale-105 active:scale-95"
        style="background: linear-gradient(135deg, #00B2FF 0%, #006AFF 50%, #A033FF 100%);"
    >
        <svg viewBox="0 0 36 36" class="w-6 h-6 md:w-8 md:h-8" fill="white">
            <path d="M18 0C8.05 0 0 7.4 0 16.5c0 5.2 2.62 9.83 6.72 12.85V36l6.14-3.37c1.64.45 3.38.7 5.14.7 9.95 0 18-7.4 18-16.5S27.95 0 18 0zm1.8 22.2-4.59-4.9-8.96 4.9L16.2 11l4.7 4.9L29.76 11 19.8 22.2z"/>
        </svg>
    </a>
</template>
