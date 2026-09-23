<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
    links: Object,
})

// Công tắc "Mua lại từ lịch sử" ở Admin > Cài đặt (Setting 'history_rebuy_enabled'). TẮT (mặc
// định) thì không mở thẳng link cũ từ đây: mã đã áp trong đó có thể hết lượt/hết hạn và lượt bấm
// không chắc được ghi nhận — khách muốn mua thì về trang chủ dán lại link để quét mới.
const page = usePage()
const historyRebuy = computed(() => page.props.settings?.historyRebuyEnabled ?? false)

function vnd(n) {
    return '₫' + Number(n).toLocaleString('vi-VN')
}

const platformLabels = { shopee: 'Shopee', lazada: 'Lazada', tiki: 'Tiki', tiktok: 'TikTok' }
</script>

<template>
    <Head title="Lịch sử mã giảm giá" />
    <AppLayout>
        <div class="max-w-4xl mx-auto px-4 py-10">
            <h1 class="text-2xl font-extrabold text-[var(--color-ink)] mb-8">Lịch sử quét mã</h1>

            <div v-if="links?.data?.length" class="space-y-4">
                <div
                    v-for="link in links.data"
                    :key="link.id"
                    class="card-glass rounded-2xl p-5 flex gap-4 items-start"
                >
                    <div class="w-16 h-16 rounded-xl bg-[var(--color-peach-soft)] flex-none overflow-hidden">
                        <img v-if="link.product_image" :src="link.product_image" :alt="link.product_name" class="w-full h-full object-cover" />
                        <div v-else class="w-full h-full flex items-center justify-center text-2xl">🛍️</div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-xs bg-[var(--color-peach)] text-[var(--color-accent)] font-bold px-2 py-0.5 rounded-md">
                                {{ platformLabels[link.platform] }}
                            </span>
                            <span class="text-xs text-[var(--color-muted)]">{{ new Date(link.created_at).toLocaleDateString('vi-VN') }}</span>
                        </div>
                        <p class="font-semibold text-[var(--color-ink)] text-sm line-clamp-1">{{ link.product_name || 'Sản phẩm' }}</p>
                        <div class="flex items-center gap-3 mt-1 text-sm">
                            <span class="font-bold text-[var(--color-accent)]">{{ vnd(link.discounted_price || 0) }}</span>
                            <span v-if="link.vouchers?.length" class="text-xs text-[var(--color-brand-green)] font-semibold">
                                {{ link.vouchers.length }} mã giảm giá
                            </span>
                        </div>
                    </div>
                    <a v-if="historyRebuy && link.short_url" :href="link.short_url" target="_blank" rel="noopener"
                        class="text-xs font-semibold text-[var(--color-accent)] hover:underline whitespace-nowrap">
                        Mở link →
                    </a>
                    <Link v-else-if="!historyRebuy" href="/"
                        class="text-xs font-semibold text-[var(--color-accent)] hover:underline whitespace-nowrap">
                        Dán lại link →
                    </Link>
                </div>

                <!-- Pagination -->
                <div v-if="links?.last_page > 1" class="flex items-center justify-center gap-2 mt-8 text-sm">
                    <Link v-if="links.prev_page_url" :href="links.prev_page_url"
                        class="px-4 py-2 rounded-xl border border-[var(--color-line)] text-sm font-semibold text-[var(--color-ink)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] transition">
                        ← Trước
                    </Link>
                    <span class="text-[var(--color-muted)]">Trang {{ links.current_page }}/{{ links.last_page }}</span>
                    <Link v-if="links.next_page_url" :href="links.next_page_url"
                        class="px-4 py-2 rounded-xl border border-[var(--color-line)] text-sm font-semibold text-[var(--color-ink)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] transition">
                        Tiếp →
                    </Link>
                </div>
            </div>

            <div v-else class="text-center py-20">
                <p class="text-4xl mb-4">📋</p>
                <p class="text-[var(--color-muted)] font-semibold">Chưa có lịch sử quét mã nào.</p>
                <Link href="/" class="btn-fire mt-4 inline-block px-6 py-3 rounded-xl">
                    Lấy mã ngay →
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
