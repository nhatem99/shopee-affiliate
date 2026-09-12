<script setup>
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
    orders: Object,
    summary: Object,
    cashbackRate: { type: Number, default: 0 },
})

function vnd(n) {
    return '₫' + Number(n || 0).toLocaleString('vi-VN')
}

// Mỗi trạng thái phải trả lời đúng một câu hỏi: "tiền của đơn này đang ở đâu?".
const statusMeta = {
    waiting: {
        label: 'Chờ Shopee xác nhận',
        badge: 'bg-yellow-100 text-yellow-700',
        note: 'Đơn chưa sang trạng thái Hoàn thành bên Shopee. Tiền chỉ vào ví sau khi hết hạn đổi trả.',
    },
    reconciling: {
        label: 'Đang đối soát',
        badge: 'bg-gray-200 text-gray-700',
        note: 'Đơn đã hoàn thành, bên mình đang đối chiếu với báo cáo Shopee. Tiền sẽ vào ví ở kỳ đối soát gần nhất.',
    },
    credited: {
        label: 'Đã cộng vào ví',
        badge: 'bg-green-100 text-green-700',
        note: null,
    },
    paid: {
        label: 'Đã rút về ví của bạn',
        badge: 'bg-blue-100 text-blue-700',
        note: null,
    },
    cancelled: {
        label: 'Đơn đã huỷ',
        badge: 'bg-red-100 text-red-600',
        note: 'Đơn bị huỷ nên không được hoàn tiền. Nếu trước đó đã cộng vào ví thì phần đó được trừ lại.',
    },
}
</script>

<template>
    <Head title="Đơn hàng của tôi" />
    <AppLayout>
        <div class="max-w-4xl mx-auto px-4 py-10">
            <h1 class="text-2xl font-extrabold text-[var(--color-ink)] mb-1">Đơn hàng của tôi</h1>
            <p class="text-sm text-[var(--color-muted)] mb-8">
                Từng đơn mua qua link của bạn và số tiền hoàn tương ứng.
            </p>

            <!-- Tổng quan: ba con số trả lời "đã có bao nhiêu, đang chờ bao nhiêu, rơi mất bao nhiêu" -->
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-8">
                <div class="card-glass rounded-2xl p-4">
                    <p class="text-xs text-[var(--color-muted)] mb-1">Đã cộng vào ví</p>
                    <p class="text-xl font-extrabold text-[var(--color-brand-green)]">{{ vnd(summary?.credited) }}</p>
                </div>
                <div class="card-glass rounded-2xl p-4">
                    <p class="text-xs text-[var(--color-muted)] mb-1">Đang chờ (dự kiến)</p>
                    <p class="text-xl font-extrabold text-[var(--color-ink)]">
                        {{ summary?.pending_estimate === null ? '—' : vnd(summary?.pending_estimate) }}
                    </p>
                </div>
                <div class="card-glass rounded-2xl p-4 col-span-2 md:col-span-1">
                    <p class="text-xs text-[var(--color-muted)] mb-1">Đơn bị huỷ</p>
                    <p class="text-xl font-extrabold text-[var(--color-ink)]">{{ summary?.cancelled_count ?? 0 }}</p>
                </div>
            </div>

            <div v-if="orders?.data?.length" class="space-y-4">
                <div v-for="o in orders.data" :key="o.order_id" class="card-glass rounded-2xl p-5">
                    <div class="flex gap-4 items-start">
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <span :class="statusMeta[o.status].badge" class="text-xs font-bold px-2 py-0.5 rounded-md">
                                    {{ statusMeta[o.status].label }}
                                </span>
                                <span v-if="o.ordered_at" class="text-xs text-[var(--color-muted)]">Đặt ngày {{ o.ordered_at }}</span>
                            </div>
                            <p class="font-semibold text-[var(--color-ink)] text-sm line-clamp-2">
                                {{ o.product_name || 'Đơn hàng Shopee' }}
                            </p>
                            <p class="text-xs text-[var(--color-muted)] mt-0.5">
                                <span v-if="o.shop_name">{{ o.shop_name }}</span>
                                <span v-if="o.shop_name && o.other_items"> · </span>
                                <span v-if="o.other_items">và {{ o.other_items }} sản phẩm khác</span>
                            </p>
                        </div>
                        <div class="flex-none text-right">
                            <p class="text-xs text-[var(--color-muted)] mb-0.5">
                                {{ o.is_estimate ? 'Dự kiến hoàn' : 'Được hoàn' }}
                            </p>
                            <p class="font-extrabold"
                                :class="o.status === 'cancelled' ? 'text-[var(--color-muted)] line-through' : 'text-[var(--color-accent)]'">
                                {{ o.amount === null ? '—' : vnd(o.amount) }}
                            </p>
                        </div>
                    </div>

                    <p v-if="statusMeta[o.status].note" class="text-xs text-[var(--color-muted)] mt-3 pt-3 border-t border-[var(--color-line)]">
                        {{ statusMeta[o.status].note }}
                    </p>

                    <p class="text-[11px] text-[var(--color-muted)] mt-2">Mã đơn Shopee: {{ o.order_id }}</p>
                </div>

                <!-- Phân trang -->
                <div class="flex justify-center gap-2 mt-8">
                    <Link v-if="orders.prev_page_url" :href="orders.prev_page_url"
                        class="px-4 py-2 rounded-xl border border-[var(--color-line)] text-sm font-semibold text-[var(--color-ink)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] transition">
                        ← Trước
                    </Link>
                    <Link v-if="orders.next_page_url" :href="orders.next_page_url"
                        class="px-4 py-2 rounded-xl border border-[var(--color-line)] text-sm font-semibold text-[var(--color-ink)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] transition">
                        Tiếp →
                    </Link>
                </div>
            </div>

            <!-- Chưa có đơn nào: nói rõ điều kiện để đơn được ghi nhận, vì đây đúng là chỗ khách
                 vào tìm câu trả lời "mua rồi mà sao không thấy gì". -->
            <div v-else class="card-glass rounded-2xl p-6 text-center">
                <p class="text-4xl mb-3">🧾</p>
                <p class="font-bold text-[var(--color-ink)] mb-1">Chưa ghi nhận đơn nào</p>
                <p class="text-sm text-[var(--color-muted)] max-w-md mx-auto">
                    Đơn chỉ được ghi nhận khi bạn <b>đang đăng nhập</b> lúc lấy mã và bấm mua qua link ở đây.
                    Đơn mới đặt cũng cần vài ngày để hiện, vì bên mình đối soát theo báo cáo Shopee.
                </p>
                <Link href="/" class="btn-fire mt-5 inline-block px-6 py-3 rounded-xl no-underline">
                    Về trang chủ lấy mã →
                </Link>
            </div>

            <p v-if="cashbackRate > 0" class="text-xs text-[var(--color-muted)] mt-6 text-center">
                Số tiền hoàn được tính trên hoa hồng thực nhận của đơn, theo tỉ lệ {{ cashbackRate }}%.
                Số "dự kiến" có thể đổi khi Shopee chốt lại hoa hồng cuối cùng.
            </p>

            <div class="text-center mt-6">
                <Link href="/profile" class="text-sm font-semibold text-[var(--color-accent)] hover:underline">
                    Xem ví và rút tiền →
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
