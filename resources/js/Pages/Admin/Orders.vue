<script setup>
import { computed, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useAdminStore } from '@/Stores/useAdminStore'
import { useToast } from '@/composables/useToast'

const admin = useAdminStore()
const toast = useToast()

const props = defineProps({
    orders: Object,
    filters: Object,
    statusCounts: Object,
})

const totalCount = computed(() => Object.values(props.statusCounts || {}).reduce((a, b) => a + Number(b), 0))

function vnd(n) {
    return '₫' + Number(n || 0).toLocaleString('vi-VN')
}

const statusColors = {
    pending: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    approved: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    paid: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
}

const statusLabels = {
    pending: 'Chờ duyệt',
    approved: 'Đã duyệt',
    paid: 'Đã trả',
}

function filter(status) {
    router.get('/admin/orders', { status }, { preserveState: true })
}

function goPage(url) {
    if (url) router.get(url, {}, { preserveState: true })
}

const confirming = ref(null)

function approve(orderId) {
    admin.approveOrder(orderId, {
        onSuccess: () => { confirming.value = null; toast.success('Đã duyệt đơn hàng thành công') },
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
            <button @click="filter('')" :class="!filters?.status ? 'bg-[var(--color-accent)] text-white' : 'bg-[var(--color-surface)] text-[var(--color-ink)] border border-[var(--color-line)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)]'"
                class="px-4 py-2 rounded-xl text-sm font-semibold transition">Tất cả ({{ totalCount }})</button>
            <button v-for="s in ['pending','approved','paid']" :key="s" @click="filter(s)"
                :class="filters?.status === s ? 'bg-[var(--color-accent)] text-white' : 'bg-[var(--color-surface)] text-[var(--color-ink)] border border-[var(--color-line)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)]'"
                class="px-4 py-2 rounded-xl text-sm font-semibold transition">
                {{ statusLabels[s] }} ({{ statusCounts?.[s] ?? 0 }})
            </button>
        </div>

        <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] overflow-hidden overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-peach-soft)]">
                    <tr class="text-left text-xs text-[var(--color-muted)]">
                        <th class="px-6 py-3 font-semibold">ID</th>
                        <th class="px-6 py-3 font-semibold">Người dùng</th>
                        <th class="px-6 py-3 font-semibold">Sản phẩm</th>
                        <th class="px-6 py-3 font-semibold">Hoa hồng</th>
                        <th class="px-6 py-3 font-semibold">Ngày</th>
                        <th class="px-6 py-3 font-semibold">Trạng thái</th>
                        <th class="px-6 py-3 font-semibold">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-line)]">
                    <tr v-for="order in orders?.data" :key="order.id">
                        <td class="px-6 py-4 text-[var(--color-muted)]">#{{ order.id }}</td>
                        <td class="px-6 py-4 font-medium text-[var(--color-ink)]">{{ order.user }}</td>
                        <td class="px-6 py-4 text-[var(--color-ink)]/70 max-w-[180px] truncate">
                            <span v-if="order.type === 'welcome_bonus'" class="px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">🎁 Thưởng người mới</span>
                            <template v-else>{{ order.product }}</template>
                        </td>
                        <td class="px-6 py-4 font-semibold text-[var(--color-brand-green)]">{{ vnd(order.amount) }}</td>
                        <td class="px-6 py-4 text-[var(--color-muted)] text-xs whitespace-nowrap">{{ order.created_at }}</td>
                        <td class="px-6 py-4">
                            <span :class="statusColors[order.status]" class="px-2 py-1 rounded-full text-xs font-semibold">
                                {{ statusLabels[order.status] }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <button
                                v-if="order.status === 'pending'"
                                @click="confirming = order"
                                :disabled="admin.loadingOrders.includes(order.id)"
                                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-[var(--color-brand-green)] hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition"
                            >
                                <svg v-if="admin.loadingOrders.includes(order.id)" class="w-3 h-3 animate-spin shrink-0" viewBox="0 0 24 24" fill="none">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" stroke-dasharray="30 70" />
                                </svg>
                                {{ admin.loadingOrders.includes(order.id) ? 'Đang duyệt...' : 'Duyệt' }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!orders?.data?.length">
                        <td colspan="7" class="px-6 py-10 text-center text-[var(--color-muted)]">Không có đơn hàng.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-center gap-3 mt-4" v-if="orders?.prev_page_url || orders?.next_page_url">
            <button @click="goPage(orders.prev_page_url)" :disabled="!orders.prev_page_url"
                class="flex-1 md:flex-none px-4 py-2.5 md:py-2 rounded-xl text-sm font-semibold bg-[var(--color-surface)] border border-[var(--color-line)] disabled:opacity-40 disabled:cursor-not-allowed">
                ← Trước
            </button>
            <span class="flex-none text-xs text-[var(--color-muted)] tabular-nums">
                {{ orders?.current_page }} / {{ orders?.last_page }}
            </span>
            <button @click="goPage(orders.next_page_url)" :disabled="!orders.next_page_url"
                class="flex-1 md:flex-none px-4 py-2.5 md:py-2 rounded-xl text-sm font-semibold bg-[var(--color-surface)] border border-[var(--color-line)] disabled:opacity-40 disabled:cursor-not-allowed">
                Sau →
            </button>
        </div>

        <!-- Xác nhận duyệt đơn: cộng tiền vào ví, không hoàn tác được -->
        <div v-if="confirming" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="bg-[var(--color-surface)] rounded-2xl p-6 w-full max-w-md">
                <h2 class="font-extrabold text-[var(--color-ink)] mb-1">Duyệt đơn hàng</h2>
                <p class="text-xs text-[var(--color-muted)] mb-5">
                    #{{ confirming.id }} · {{ confirming.user }} · <span class="font-semibold text-[var(--color-brand-green)]">{{ vnd(confirming.amount) }}</span>
                    — số tiền này sẽ được cộng vào ví khả dụng của khách.
                </p>
                <div class="flex gap-3">
                    <button @click="approve(confirming.id)" :disabled="admin.loadingOrders.includes(confirming.id)"
                        class="flex-1 bg-[var(--color-brand-green)] hover:opacity-90 text-white font-semibold py-2.5 rounded-xl text-sm transition disabled:opacity-60">
                        Xác nhận duyệt
                    </button>
                    <button type="button" @click="confirming = null"
                        class="px-6 bg-[var(--color-peach-soft)] text-[var(--color-ink)] font-semibold py-2.5 rounded-xl text-sm hover:bg-[var(--color-peach)] transition">
                        Hủy
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
