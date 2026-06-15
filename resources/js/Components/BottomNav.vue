<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

const page = usePage()
const current = computed(() => page.url)

const items = [
    { href: '/', icon: '⌂', label: 'Trang chủ' },
    { href: '/history', icon: '📋', label: 'Lịch sử' },
]
</script>

<template>
    <nav class="md:hidden fixed bottom-0 left-0 right-0 z-50 bg-[var(--color-surface)] border-t border-[var(--color-line)] flex safe-area-inset-bottom">
        <Link
            v-for="item in items"
            :key="item.href"
            :href="item.href"
            class="flex-1 flex flex-col items-center justify-center py-3 gap-0.5 text-xs font-semibold transition"
            :class="current === item.href ? 'text-[var(--color-accent)]' : 'text-[var(--color-muted)]'"
        >
            <span class="text-xl">{{ item.icon }}</span>
            <span>{{ item.label }}</span>
        </Link>
    </nav>
</template>
