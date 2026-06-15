<script setup>
import { Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

defineProps({
    stats: Object,
    daily_revenue: Array,
    recent_orders: Array,
})

function vnd(n) {
    return '₫' + Number(n || 0).toLocaleString('vi-VN')
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
