<script setup>
import { ref } from 'vue'
import { Head, router, Link, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps({
    orders: Object,
    filters: Object,
    stats: Object,
    cashbackRate: { type: Number, default: 0 },
})

const toast = useToast()
const fileInput = ref(null)
const form = useForm({ report: null })

function vnd(n) {
    return '₫' + Number(n || 0).toLocaleString('vi-VN')
}

const statusColors = {
    pending: 'bg-yellow-100 text-yellow-700',
    completed: 'bg-green-100 text-green-700',
    cancelled: 'bg-red-100 text-red-700',
}

const statusLabels = {
    pending: 'Đang chờ',
    completed: 'Hoàn thành',
    cancelled: 'Đã huỷ',
}

function pick(event) {
    const file = event.target.files?.[0]
    if (!file) return

    form.report = file
    form.post('/admin/shopee-orders/import', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: (page) => toast.success(page.props.flash?.success || 'Đã nhập báo cáo.'),
        onError: (errors) => toast.error(errors.report || 'Không nhập được báo cáo.'),
        onFinish: () => {
            form.reset()
            // Xoá giá trị input để chọn LẠI ĐÚNG file vừa chọn vẫn kích hoạt change —
            // sau khi sửa lỗi rồi tải lại cùng một file là thao tác rất hay dùng.
            if (fileInput.value) fileInput.value.value = ''
        },
    })
}

function filter(params) {
    router.get('/admin/shopee-orders', params, { preserveState: true })
}

function goPage(url) {
    if (url) router.get(url, {}, { preserveState: true })
}
</script>

<template>
    <Head title="Admin — Báo cáo Shopee" />
    <AdminLayout>
        <template #title>Báo cáo hoa hồng Shopee</template>

        <!-- Nhập file -->
        <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6 mb-6">
            <h2 class="font-bold text-[var(--color-ink)] mb-1">Nhập báo cáo</h2>
            <p class="text-sm text-[var(--color-muted)] leading-relaxed mb-4">
                Vào trang affiliate Shopee → <strong class="text-[var(--color-ink)]">Báo cáo hoa hồng</strong> → xuất file CSV rồi tải lên đây.
                Nhập lại cùng một file bao nhiêu lần cũng được: dòng cũ được <strong class="text-[var(--color-ink)]">cập nhật</strong>, không nhân bản.
                Chỉ đơn <strong class="text-[var(--color-ink)]">Hoàn thành</strong> mới sinh ra tiền cho khách — đơn đang chờ vẫn còn huỷ được.
            </p>

            <div class="flex flex-col sm:flex-row gap-3 sm:items-center">
                <input
                    ref="fileInput"
                    type="file"
                    accept=".csv,text/csv"
                    @change="pick"
                    :disabled="form.processing"
                    class="flex-1 min-w-0 text-sm text-[var(--color-ink)] file:mr-3 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-[var(--color-peach)] file:text-[var(--color-ink)] hover:file:bg-[var(--color-peach-soft)] file:cursor-pointer disabled:opacity-60"
                />
                <span v-if="form.processing" class="flex-none text-sm font-semibold text-[var(--color-muted)]">Đang xử lý...</span>
            </div>

            <p v-if="form.errors.report" class="text-red-500 text-sm mt-3 leading-relaxed">{{ form.errors.report }}</p>

            <div
                v-if="cashbackRate <= 0"
                class="mt-4 pt-4 border-t border-[var(--color-line)] text-sm text-amber-700 bg-amber-50 -mx-6 -mb-6 px-6 py-4 rounded-b-2xl leading-relaxed"
            >
                ⚠️ <strong>Chưa đặt tỉ lệ hoàn tiền</strong> — nhập báo cáo vẫn chạy nhưng không đồng nào vào ví khách.
                Đặt tỉ lệ ở <Link href="/admin/settings" class="font-semibold underline">Cài đặt</Link>.
            </div>
            <div v-else class="mt-4 pt-4 border-t border-[var(--color-line)] text-sm text-[var(--color-muted)]">
                Đang hoàn <strong class="text-[var(--color-brand-green)]">{{ cashbackRate }}%</strong> hoa hồng ròng cho khách.
            </div>
        </div>

        <!-- Thống kê -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4">
                <p class="text-xs text-[var(--color-muted)] mb-1">Tổng đơn</p>
                <p class="text-xl font-extrabold text-[var(--color-ink)] tabular-nums">{{ stats?.orders ?? 0 }}</p>
                <p class="text-xs text-[var(--color-muted)] mt-1">{{ stats?.rows ?? 0 }} dòng sản phẩm</p>
            </div>
            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4">
                <p class="text-xs text-[var(--color-muted)] mb-1">Hoàn thành</p>
                <p class="text-xl font-extrabold text-[var(--color-brand-green)] tabular-nums">{{ stats?.completed_orders ?? 0 }}</p>
                <p class="text-xs text-[var(--color-muted)] mt-1">{{ stats?.pending_orders ?? 0 }} chờ · {{ stats?.cancelled_orders ?? 0 }} huỷ</p>
            </div>
            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4">
                <p class="text-xs text-[var(--color-muted)] mb-1">Hoa hồng ròng (đã hoàn thành)</p>
                <p class="text-xl font-extrabold text-[var(--color-brand-green)] tabular-nums">{{ vnd(stats?.completed_commission) }}</p>
            </div>
            <button
                @click="filter({ unmatched: filters?.unmatched ? undefined : 1 })"
                class="bg-[var(--color-surface)] rounded-2xl border p-4 text-left transition"
                :class="filters?.unmatched ? 'border-[var(--color-accent)]' : 'border-[var(--color-line)] hover:border-[var(--color-accent)]'"
            >
                <p class="text-xs text-[var(--color-muted)] mb-1">Có mã nhưng chưa khớp khách</p>
                <p class="text-xl font-extrabold tabular-nums" :class="(stats?.unmatched_rows ?? 0) > 0 ? 'text-red-600' : 'text-[var(--color-ink)]'">
                    {{ stats?.unmatched_rows ?? 0 }}
                </p>
                <p class="text-xs text-[var(--color-muted)] mt-1">{{ stats?.no_code_rows ?? 0 }} dòng không có mã</p>
            </button>
        </div>

        <!-- Lọc theo trạng thái -->
        <div class="flex flex-wrap gap-2 mb-6">
            <button @click="filter({})"
                :class="!filters?.status && !filters?.unmatched ? 'bg-[var(--color-accent)] text-white' : 'bg-[var(--color-surface)] text-[var(--color-ink)] border border-[var(--color-line)] hover:border-[var(--color-accent)]'"
                class="px-4 py-2 rounded-xl text-sm font-semibold transition">Tất cả</button>
            <button v-for="s in ['completed','pending','cancelled']" :key="s" @click="filter({ status: s })"
                :class="filters?.status === s ? 'bg-[var(--color-accent)] text-white' : 'bg-[var(--color-surface)] text-[var(--color-ink)] border border-[var(--color-line)] hover:border-[var(--color-accent)]'"
                class="px-4 py-2 rounded-xl text-sm font-semibold transition">
                {{ statusLabels[s] }}
            </button>
        </div>

        <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] overflow-hidden overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-peach-soft)]">
                    <tr class="text-left text-xs text-[var(--color-muted)]">
                        <th class="px-4 py-3 font-semibold">Đơn</th>
                        <th class="px-4 py-3 font-semibold">Sản phẩm</th>
                        <th class="px-4 py-3 font-semibold">Hoa hồng ròng</th>
                        <th class="px-4 py-3 font-semibold">Trạng thái</th>
                        <th class="px-4 py-3 font-semibold">Sub_id</th>
                        <th class="px-4 py-3 font-semibold">Khách</th>
                        <th class="px-4 py-3 font-semibold">Đặt lúc</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-line)]">
                    <tr v-for="o in orders?.data" :key="o.id">
                        <td class="px-4 py-3 font-mono text-xs text-[var(--color-muted)] whitespace-nowrap">{{ o.order_id }}</td>
                        <td class="px-4 py-3 text-[var(--color-ink)]/80 max-w-[240px] truncate" :title="o.product_name">
                            {{ o.product_name }}
                            <span v-if="o.shop_name" class="block text-xs text-[var(--color-muted)] truncate">{{ o.shop_name }}</span>
                        </td>
                        <td class="px-4 py-3 font-semibold tabular-nums whitespace-nowrap"
                            :class="Number(o.net_commission) > 0 ? 'text-[var(--color-brand-green)]' : 'text-[var(--color-muted)]'">
                            {{ vnd(o.net_commission) }}
                        </td>
                        <td class="px-4 py-3">
                            <span :class="statusColors[o.status]" class="px-2 py-1 rounded-full text-xs font-semibold whitespace-nowrap">
                                {{ statusLabels[o.status] }}
                            </span>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-[var(--color-muted)] whitespace-nowrap" :title="o.sub_id_raw">
                            {{ o.user_sub_id || '—' }}
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span v-if="o.user" class="text-[var(--color-ink)] font-medium">{{ o.user }}</span>
                            <span v-else-if="o.user_sub_id" class="text-red-600 text-xs font-semibold">không khớp tài khoản</span>
                            <span v-else class="text-[var(--color-muted)]">—</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-[var(--color-muted)] whitespace-nowrap">{{ o.ordered_at }}</td>
                    </tr>
                    <tr v-if="!orders?.data?.length">
                        <td colspan="7" class="px-6 py-10 text-center text-[var(--color-muted)]">
                            Chưa có dữ liệu. Tải lên file báo cáo CSV ở trên để bắt đầu.
                        </td>
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
    </AdminLayout>
</template>
