<script setup>
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useAdminStore } from '@/Stores/useAdminStore'

const admin = useAdminStore()

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
</script>

<template>
    <Head title="Admin — Đơn hàng" />
    <AdminLayout>
        <template #title>Quản lý đơn hàng</template>

        <!-- Filters -->
        <div class="flex gap-2 mb-6">
            <button @click="filter('')" :class="!filters?.status ? 'bg-gray-800 text-white' : 'bg-white text-gray-600 border border-gray-200'"
                class="px-4 py-2 rounded-xl text-sm font-semibold transition">Tất cả</button>
            <button v-for="s in ['pending','approved','paid']" :key="s" @click="filter(s)"
                :class="filters?.status === s ? 'bg-gray-800 text-white' : 'bg-white text-gray-600 border border-gray-200'"
                class="px-4 py-2 rounded-xl text-sm font-semibold transition capitalize">
                {{ statusLabels[s] }}
            </button>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-xs text-gray-500">
                        <th class="px-6 py-3 font-semibold">ID</th>
                        <th class="px-6 py-3 font-semibold">Người dùng</th>
                        <th class="px-6 py-3 font-semibold">Sản phẩm</th>
                        <th class="px-6 py-3 font-semibold">Hoa hồng</th>
                        <th class="px-6 py-3 font-semibold">Trạng thái</th>
                        <th class="px-6 py-3 font-semibold">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <tr v-for="order in orders?.data" :key="order.id">
                        <td class="px-6 py-4 text-gray-500">#{{ order.id }}</td>
                        <td class="px-6 py-4 font-medium text-gray-800">{{ order.user }}</td>
                        <td class="px-6 py-4 text-gray-600 max-w-[180px] truncate">{{ order.product }}</td>
                        <td class="px-6 py-4 font-semibold text-green-600">{{ vnd(order.amount) }}</td>
                        <td class="px-6 py-4">
                            <span :class="statusColors[order.status]" class="px-2 py-1 rounded-full text-xs font-semibold">
                                {{ statusLabels[order.status] }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <button v-if="order.status === 'pending'" @click="admin.approveOrder(order.id)"
                                class="text-xs font-semibold text-green-600 hover:underline">Duyệt</button>
                        </td>
                    </tr>
                    <tr v-if="!orders?.data?.length">
                        <td colspan="6" class="px-6 py-10 text-center text-gray-400">Không có đơn hàng.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
