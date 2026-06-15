<script setup>
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useAdminStore } from '@/Stores/useAdminStore'
import { useToast } from '@/composables/useToast'

const admin = useAdminStore()
const toast = useToast()

defineProps({
    orders: Object,
    filters: Object,
})

function vnd(n) {
    return '₫' + Number(n || 0).toLocaleString('vi-VN')
}

const statusColors = {
    pending: 'bg-yellow-100 text-yellow-700',
    approved: 'bg-green-100 text-green-700',
    paid: 'bg-blue-100 text-blue-700',
}

const statusLabels = {
    pending: 'Chờ duyệt',
    approved: 'Đã duyệt',
    paid: 'Đã trả',
}

function filter(status) {
    router.get('/admin/orders', { status }, { preserveState: true })
}

function approve(orderId) {
    admin.approveOrder(orderId, {
        onSuccess: () => toast.success('Đã duyệt đơn hàng thành công'),
        onError: () => toast.error('Không thể duyệt đơn hàng, vui lòng thử lại'),
    })
}
</script>

<template>
    <Head title="Admin — Đơn hàng" />
    <AdminLayout>
        <template #title>Quản lý đơn hàng</template>

        <!-- Filters -->
        <div class="flex gap-2 mb-6">
            <button @click="filter('')" :class="!filters?.status ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink)] border border-[var(--color-line)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)]'"
                class="px-4 py-2 rounded-xl text-sm font-semibold transition">Tất cả</button>
            <button v-for="s in ['pending','approved','paid']" :key="s" @click="filter(s)"
                :class="filters?.status === s ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink)] border border-[var(--color-line)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)]'"
                class="px-4 py-2 rounded-xl text-sm font-semibold transition">
                {{ statusLabels[s] }}
            </button>
        </div>

        <div class="bg-white rounded-2xl border border-[var(--color-line)] overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-peach-soft)]">
                    <tr class="text-left text-xs text-[var(--color-muted)]">
                        <th class="px-6 py-3 font-semibold">ID</th>
                        <th class="px-6 py-3 font-semibold">Người dùng</th>
                        <th class="px-6 py-3 font-semibold">Sản phẩm</th>
                        <th class="px-6 py-3 font-semibold">Hoa hồng</th>
                        <th class="px-6 py-3 font-semibold">Trạng thái</th>
                        <th class="px-6 py-3 font-semibold">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-line)]">
                    <tr v-for="order in orders?.data" :key="order.id">
                        <td class="px-6 py-4 text-[var(--color-muted)]">#{{ order.id }}</td>
                        <td class="px-6 py-4 font-medium text-[var(--color-ink)]">{{ order.user }}</td>
                        <td class="px-6 py-4 text-[var(--color-ink)]/70 max-w-[180px] truncate">{{ order.product }}</td>
                        <td class="px-6 py-4 font-semibold text-[var(--color-brand-green)]">{{ vnd(order.amount) }}</td>
                        <td class="px-6 py-4">
                            <span :class="statusColors[order.status]" class="px-2 py-1 rounded-full text-xs font-semibold">
                                {{ statusLabels[order.status] }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <button
                                v-if="order.status === 'pending'"
                                @click="approve(order.id)"
                                :disabled="admin.loadingOrders.includes(order.id)"
                                class="flex items-center gap-1.5 text-xs font-semibold text-[var(--color-brand-green)] hover:underline disabled:opacity-50 disabled:cursor-not-allowed transition"
                            >
                                <svg v-if="admin.loadingOrders.includes(order.id)" class="w-3 h-3 animate-spin shrink-0" viewBox="0 0 24 24" fill="none">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" stroke-dasharray="30 70" />
                                </svg>
                                {{ admin.loadingOrders.includes(order.id) ? 'Đang duyệt...' : 'Duyệt' }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!orders?.data?.length">
                        <td colspan="6" class="px-6 py-10 text-center text-[var(--color-muted)]">Không có đơn hàng.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
