<script setup>
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps({
    activities: Object,
    filters: Object,
    summary: Object,
})

const eventLabels = {
    page_view: 'Xem trang',
    url_paste: 'Dán link sản phẩm',
    voucher_select: 'Chọn/lấy mã',
    voucher_copy: 'Copy mã',
    short_link_click: 'Click link rút gọn',
}

const deviceLabels = {
    mobile: '📱 Mobile',
    tablet: '📱 Tablet',
    desktop: '💻 Desktop',
}

function filter(key, value) {
    router.get('/admin/activities', { ...props.filters, [key]: value }, { preserveState: true })
}

function goPage(url) {
    if (url) router.get(url, {}, { preserveState: true })
}
</script>

<template>
    <Head title="Admin — Theo dõi người dùng" />
    <AdminLayout>
        <template #title>Theo dõi hoạt động người dùng</template>

        <!-- Summary cards (7 ngày gần nhất) -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4">
                <p class="text-xs text-[var(--color-muted)] mb-1">Tổng sự kiện (7 ngày)</p>
                <p class="text-2xl font-extrabold text-[var(--color-ink)]">{{ summary?.total ?? 0 }}</p>
            </div>
            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4">
                <p class="text-xs text-[var(--color-muted)] mb-1">Thiết bị</p>
                <p class="text-sm text-[var(--color-ink)] space-y-0.5">
                    <span v-for="(count, device) in summary?.by_device" :key="device" class="block">
                        {{ deviceLabels[device] || device }}: <b>{{ count }}</b>
                    </span>
                    <span v-if="!Object.keys(summary?.by_device || {}).length" class="text-[var(--color-muted)]">Chưa có dữ liệu</span>
                </p>
            </div>
            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4">
                <p class="text-xs text-[var(--color-muted)] mb-1">Top mã voucher được dùng</p>
                <p class="text-sm text-[var(--color-ink)] space-y-0.5">
                    <span v-for="(count, code) in summary?.top_vouchers" :key="code" class="block truncate">
                        {{ code }}: <b>{{ count }}</b>
                    </span>
                    <span v-if="!Object.keys(summary?.top_vouchers || {}).length" class="text-[var(--color-muted)]">Chưa có dữ liệu</span>
                </p>
            </div>
            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4">
                <p class="text-xs text-[var(--color-muted)] mb-1">Top quốc gia</p>
                <p class="text-sm text-[var(--color-ink)] space-y-0.5">
                    <span v-for="(count, country) in summary?.top_countries" :key="country" class="block">
                        {{ country }}: <b>{{ count }}</b>
                    </span>
                    <span v-if="!Object.keys(summary?.top_countries || {}).length" class="text-[var(--color-muted)]">Chưa có dữ liệu</span>
                </p>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap gap-2 mb-6">
            <button @click="filter('event_type', '')" :class="!filters?.event_type ? 'bg-[var(--color-accent)] text-white' : 'bg-[var(--color-surface)] text-[var(--color-ink)] border border-[var(--color-line)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)]'"
                class="px-4 py-2 rounded-xl text-sm font-semibold transition">Tất cả sự kiện</button>
            <button v-for="(label, key) in eventLabels" :key="key" @click="filter('event_type', key)"
                :class="filters?.event_type === key ? 'bg-[var(--color-accent)] text-white' : 'bg-[var(--color-surface)] text-[var(--color-ink)] border border-[var(--color-line)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)]'"
                class="px-4 py-2 rounded-xl text-sm font-semibold transition">
                {{ label }}
            </button>
        </div>

        <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] overflow-hidden overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-peach-soft)]">
                    <tr class="text-left text-xs text-[var(--color-muted)] whitespace-nowrap">
                        <th class="px-4 py-3 font-semibold">Thời gian</th>
                        <th class="px-4 py-3 font-semibold">Sự kiện</th>
                        <th class="px-4 py-3 font-semibold">Người dùng</th>
                        <th class="px-4 py-3 font-semibold">Sản phẩm / Nguồn</th>
                        <th class="px-4 py-3 font-semibold">Mã</th>
                        <th class="px-4 py-3 font-semibold">Thiết bị</th>
                        <th class="px-4 py-3 font-semibold">Trình duyệt / OS</th>
                        <th class="px-4 py-3 font-semibold">IP</th>
                        <th class="px-4 py-3 font-semibold">Vị trí</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-line)]">
                    <tr v-for="a in activities?.data" :key="a.id">
                        <td class="px-4 py-3 text-[var(--color-muted)] whitespace-nowrap">{{ a.created_at }}</td>
                        <td class="px-4 py-3 text-[var(--color-ink)] font-medium whitespace-nowrap">{{ eventLabels[a.event_type] || a.event_type }}</td>
                        <td class="px-4 py-3 text-[var(--color-ink)]/70">{{ a.user || 'Khách' }}</td>
                        <td class="px-4 py-3 text-[var(--color-ink)]/70 max-w-[200px] truncate">{{ a.product_name || a.source || '—' }}</td>
                        <td class="px-4 py-3 font-mono text-[var(--color-ink)]">{{ a.voucher_code || '—' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ deviceLabels[a.device_type] || a.device_type || '—' }}</td>
                        <td class="px-4 py-3 text-[var(--color-ink)]/70 whitespace-nowrap">{{ [a.browser, a.os_name].filter(Boolean).join(' / ') || '—' }}</td>
                        <td class="px-4 py-3 text-[var(--color-muted)] whitespace-nowrap">{{ a.ip_address || '—' }}</td>
                        <td class="px-4 py-3 text-[var(--color-ink)]/70 whitespace-nowrap">{{ [a.city, a.country].filter(Boolean).join(', ') || '—' }}</td>
                    </tr>
                    <tr v-if="!activities?.data?.length">
                        <td colspan="9" class="px-6 py-10 text-center text-[var(--color-muted)]">Chưa có hoạt động nào.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex justify-center gap-2 mt-4" v-if="activities?.prev_page_url || activities?.next_page_url">
            <button @click="goPage(activities.prev_page_url)" :disabled="!activities.prev_page_url"
                class="px-4 py-2 rounded-xl text-sm font-semibold bg-[var(--color-surface)] border border-[var(--color-line)] disabled:opacity-40 disabled:cursor-not-allowed">
                ← Trước
            </button>
            <button @click="goPage(activities.next_page_url)" :disabled="!activities.next_page_url"
                class="px-4 py-2 rounded-xl text-sm font-semibold bg-[var(--color-surface)] border border-[var(--color-line)] disabled:opacity-40 disabled:cursor-not-allowed">
                Sau →
            </button>
        </div>
    </AdminLayout>
</template>
