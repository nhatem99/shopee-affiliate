<script setup>
import { computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps({
    stats: Object,
    daily_history: { type: Array, default: () => [] },
    range: { type: Number, default: 7 },
    recent_orders: Array,
})

function vnd(n) {
    return '₫' + Number(n || 0).toLocaleString('vi-VN')
}

// Dạng gọn cho nhãn trục/cột: 2,5tr · 850k · 0
function vndShort(n) {
    const v = Number(n || 0)
    if (v >= 1_000_000) return (v / 1_000_000).toFixed(v % 1_000_000 === 0 ? 0 : 1).replace('.', ',') + 'tr'
    if (v >= 1_000) return Math.round(v / 1_000) + 'k'
    return String(v)
}

const maxTotal = computed(() => Math.max(...props.daily_history.map(d => d.total), 1))
const rowsDesc = computed(() => [...props.daily_history].reverse())
const totalOrders = computed(() => props.daily_history.reduce((s, d) => s + d.orders, 0))
const totalRevenue = computed(() => props.daily_history.reduce((s, d) => s + d.total, 0))

// Hiện nhãn trục X: 7 ngày -> tất cả; 30 ngày -> thưa (mỗi 5 cột + cột cuối)
function showLabel(i) {
    if (props.range === 7) return true
    return i % 5 === 0 || i === props.daily_history.length - 1
}

function setRange(r) {
    if (r === props.range) return
    router.get('/admin/dashboard', { range: r }, { preserveScroll: true, preserveState: true, replace: true })
}

const statusColors = {
    pending: 'bg-yellow-100 text-yellow-700',
    approved: 'bg-green-100 text-green-700',
    paid: 'bg-blue-100 text-blue-700',
}
</script>

<template>
    <Head title="Admin Dashboard" />
    <AdminLayout>
        <template #title>Dashboard</template>

        <!-- Stat cards -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5">
                <p class="text-xs text-[var(--color-muted)] font-semibold uppercase tracking-wide mb-1">Tổng mã đã quét</p>
                <p class="text-2xl font-extrabold text-[var(--color-ink)]">{{ stats?.total_codes?.toLocaleString('vi-VN') || 0 }}</p>
            </div>
            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5">
                <p class="text-xs text-[var(--color-muted)] font-semibold uppercase tracking-wide mb-1">Đơn chờ duyệt</p>
                <p class="text-2xl font-extrabold text-[var(--color-accent)]">{{ stats?.pending_orders || 0 }}</p>
            </div>
            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5">
                <p class="text-xs text-[var(--color-muted)] font-semibold uppercase tracking-wide mb-1">Hoa hồng tuần</p>
                <p class="text-2xl font-extrabold text-[var(--color-brand-green)]">{{ vnd(stats?.weekly_commission) }}</p>
            </div>
            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5">
                <p class="text-xs text-[var(--color-muted)] font-semibold uppercase tracking-wide mb-1">Người dùng</p>
                <p class="text-2xl font-extrabold text-[var(--color-ink)]">{{ stats?.total_users || 0 }}</p>
            </div>
        </div>

        <!-- Daily history -->
        <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6 mb-8">
            <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
                <div>
                    <h2 class="font-extrabold text-[var(--color-ink)]">Lịch sử theo ngày</h2>
                    <p class="text-xs text-[var(--color-muted)] mt-0.5">
                        {{ totalOrders }} đơn · {{ vnd(totalRevenue) }} trong {{ range }} ngày qua
                    </p>
                </div>
                <div class="inline-flex rounded-xl border border-[var(--color-line)] p-0.5 text-sm font-semibold">
                    <button
                        v-for="r in [7, 30]" :key="r"
                        @click="setRange(r)"
                        class="px-3.5 py-1.5 rounded-lg transition"
                        :class="range === r
                            ? 'bg-[var(--color-accent)] text-white'
                            : 'text-[var(--color-muted)] hover:text-[var(--color-ink)]'"
                    >
                        {{ r }} ngày
                    </button>
                </div>
            </div>

            <!-- Bar chart -->
            <div class="flex items-end gap-1 h-44 mb-2">
                <div
                    v-for="d in daily_history" :key="d.date"
                    class="group relative flex-1 h-full flex items-end justify-center"
                >
                    <!-- Tooltip -->
                    <div class="pointer-events-none absolute bottom-full mb-2 left-1/2 -translate-x-1/2 z-10 hidden group-hover:block whitespace-nowrap rounded-lg bg-[var(--color-side)] text-white text-xs px-2.5 py-1.5 shadow-lg">
                        <div class="font-semibold">{{ d.label }}</div>
                        <div class="text-[var(--color-green-soft)]">{{ vnd(d.total) }}</div>
                        <div class="text-white/70">{{ d.orders }} đơn</div>
                    </div>
                    <!-- Bar -->
                    <div
                        class="w-full max-w-[28px] rounded-t-md transition-all duration-300"
                        :class="d.total > 0
                            ? 'bg-[var(--color-accent)] group-hover:bg-[var(--color-accent-deep)]'
                            : 'bg-[var(--color-line)] opacity-50'"
                        :style="{ height: d.total > 0 ? `calc(${(d.total / maxTotal) * 100}% + 2px)` : '2px' }"
                    ></div>
                </div>
            </div>
            <!-- X axis labels -->
            <div class="flex gap-1">
                <div
                    v-for="(d, i) in daily_history" :key="d.date"
                    class="flex-1 text-center text-[10px] text-[var(--color-muted)] tabular-nums"
                >
                    <span v-if="showLabel(i)">{{ d.label }}</span>
                </div>
            </div>

            <!-- Detail table -->
            <div class="mt-6 rounded-xl border border-[var(--color-line)] overflow-hidden">
                <div :class="range === 30 ? 'max-h-80 overflow-y-auto' : ''">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0">
                            <tr class="text-left text-xs text-[var(--color-muted)] bg-[var(--color-peach-soft)]">
                                <th class="px-4 py-2.5 font-semibold">Ngày</th>
                                <th class="px-4 py-2.5 font-semibold text-right">Số đơn</th>
                                <th class="px-4 py-2.5 font-semibold text-right">Doanh thu</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-line)]">
                            <tr v-for="d in rowsDesc" :key="d.date">
                                <td class="px-4 py-2.5 font-medium text-[var(--color-ink)]">{{ d.label }}</td>
                                <td class="px-4 py-2.5 text-right" :class="d.orders ? 'text-[var(--color-ink)]' : 'text-[var(--color-muted)]'">
                                    {{ d.orders || '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-right font-semibold" :class="d.total ? 'text-[var(--color-brand-green)]' : 'text-[var(--color-muted)]'">
                                    {{ d.total ? vnd(d.total) : '—' }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-[var(--color-peach-soft)] font-bold text-[var(--color-ink)]">
                                <td class="px-4 py-2.5">Tổng</td>
                                <td class="px-4 py-2.5 text-right">{{ totalOrders }}</td>
                                <td class="px-4 py-2.5 text-right text-[var(--color-brand-green)]">{{ vnd(totalRevenue) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent orders table -->
        <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-4">Đơn hàng gần đây</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-[var(--color-muted)] border-b border-[var(--color-line)]">
                            <th class="pb-3 font-semibold">ID</th>
                            <th class="pb-3 font-semibold">Người dùng</th>
                            <th class="pb-3 font-semibold">Sản phẩm</th>
                            <th class="pb-3 font-semibold">Hoa hồng</th>
                            <th class="pb-3 font-semibold">Trạng thái</th>
                            <th class="pb-3 font-semibold">Ngày</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        <tr v-for="order in recent_orders" :key="order.id" class="py-3">
                            <td class="py-3 text-[var(--color-muted)]">#{{ order.id }}</td>
                            <td class="py-3 font-medium text-[var(--color-ink)]">{{ order.user || '—' }}</td>
                            <td class="py-3 text-[var(--color-ink)]/70 max-w-[200px] truncate">{{ order.product || '—' }}</td>
                            <td class="py-3 font-semibold text-[var(--color-brand-green)]">{{ vnd(order.amount) }}</td>
                            <td class="py-3">
                                <span :class="statusColors[order.status]" class="px-2 py-1 rounded-full text-xs font-semibold">
                                    {{ order.status === 'pending' ? 'Chờ duyệt' : order.status === 'approved' ? 'Đã duyệt' : 'Đã trả' }}
                                </span>
                            </td>
                            <td class="py-3 text-[var(--color-muted)]">{{ order.created_at }}</td>
                        </tr>
                        <tr v-if="!recent_orders?.length">
                            <td colspan="6" class="py-8 text-center text-[var(--color-muted)]">Chưa có đơn hàng nào.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>
