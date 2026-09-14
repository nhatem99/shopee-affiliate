<script setup>
import { Head } from '@inertiajs/vue3'
import AccountLayout from '@/Layouts/AccountLayout.vue'

defineProps({
    available: Number,
    events: Array,
})

function vnd(n) {
    return '₫' + Number(n || 0).toLocaleString('vi-VN')
}

function signed(n) {
    const v = Number(n || 0)
    if (v === 0) return '0'
    return (v > 0 ? '+' : '−') + vnd(Math.abs(v))
}

function deltaClass(v) {
    if (v > 0) return 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
    if (v < 0) return 'bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-300'
    return 'bg-[var(--color-peach-soft)] text-[var(--color-muted)]'
}

const kindStyles = {
    bonus: { ring: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300', icon: '🎁' },
    cashback: { ring: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300', icon: '↑' },
    withdrawal: { ring: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300', icon: '↓' },
}
</script>

<template>
    <Head title="Lịch sử số dư ví" />
    <AccountLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-extrabold text-[var(--color-ink)]">Lịch sử số dư ví</h1>
                <p class="text-sm text-[var(--color-muted)] mt-1">Từng lần tiền vào, tiền ra và số dư trước/sau mỗi lần.</p>
            </div>

            <div class="rounded-2xl p-6 bg-gradient-to-br from-[var(--color-accent)] to-[var(--color-accent-deep)] text-white">
                <p class="text-xs font-bold uppercase tracking-wide opacity-90">Số dư hiện tại</p>
                <p class="text-3xl font-extrabold mt-1">{{ vnd(available) }}</p>
            </div>

            <div class="card-glass rounded-2xl overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-[var(--color-line)]">
                    <h2 class="font-bold text-[var(--color-ink)]">Biến động</h2>
                    <span class="text-xs text-[var(--color-muted)]">Mới nhất ở trên</span>
                </div>

                <ul v-if="events?.length" class="divide-y divide-[var(--color-line)]">
                    <li v-for="e in events" :key="e.key" class="px-6 py-4 flex flex-wrap md:flex-nowrap items-center gap-4">
                        <span
                            class="flex-none w-10 h-10 rounded-full flex items-center justify-center text-lg font-extrabold"
                            :class="kindStyles[e.kind]?.ring"
                        >{{ kindStyles[e.kind]?.icon }}</span>

                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-sm text-[var(--color-ink)]">{{ e.title }}</p>
                            <p class="text-xs text-[var(--color-muted)]">{{ e.at }}</p>
                            <p v-if="e.note" class="text-xs text-[var(--color-muted)] mt-0.5">{{ e.note }}</p>
                        </div>

                        <div class="flex-none px-3 py-1.5 rounded-xl text-sm font-extrabold tabular-nums" :class="deltaClass(e.delta)">
                            {{ signed(e.delta) }}
                        </div>

                        <div class="w-full md:w-auto flex gap-2 text-xs tabular-nums">
                            <div class="flex-1 md:flex-none rounded-lg bg-[var(--color-peach-soft)] px-3 py-1.5 flex justify-between gap-3">
                                <span class="text-[var(--color-muted)] font-semibold">Trước</span>
                                <span class="text-[var(--color-ink)] font-bold">{{ vnd(e.before) }}</span>
                            </div>
                            <div class="flex-1 md:flex-none rounded-lg bg-[var(--color-peach-soft)] px-3 py-1.5 flex justify-between gap-3">
                                <span class="text-[var(--color-muted)] font-semibold">Sau</span>
                                <span class="text-[var(--color-ink)] font-bold">{{ vnd(e.after) }}</span>
                            </div>
                        </div>
                    </li>
                </ul>
                <p v-else class="px-6 py-12 text-center text-sm text-[var(--color-muted)]">
                    Ví chưa có biến động nào.
                </p>

                <p v-if="events?.length" class="px-6 py-3 text-center text-xs text-[var(--color-muted)] border-t border-[var(--color-line)]">
                    Đã hiển thị tất cả biến động
                </p>
            </div>
        </div>
    </AccountLayout>
</template>
