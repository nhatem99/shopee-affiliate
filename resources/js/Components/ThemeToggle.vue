<script setup>
import { onMounted } from 'vue'
import { useThemeStore } from '@/Stores/useThemeStore'

const theme = useThemeStore()

onMounted(() => theme.init())
</script>

<template>
    <button
        type="button"
        @click="theme.toggle()"
        :aria-label="theme.isDark ? 'Chuyển sang giao diện sáng' : 'Chuyển sang giao diện tối'"
        :title="theme.isDark ? 'Giao diện sáng' : 'Giao diện tối'"
        class="relative w-9 h-9 inline-flex items-center justify-center rounded-xl border border-[var(--color-line)] text-[var(--color-muted)] hover:text-[var(--color-accent)] hover:border-[var(--color-accent)] transition-colors"
    >
        <!-- Mặt trời (hiện ở dark mode -> bấm để về sáng) -->
        <svg
            v-if="theme.isDark"
            xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
            class="w-5 h-5"
        >
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41" />
        </svg>
        <!-- Mặt trăng (hiện ở light mode -> bấm để sang tối) -->
        <svg
            v-else
            xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
            class="w-5 h-5"
        >
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
        </svg>
    </button>
</template>
