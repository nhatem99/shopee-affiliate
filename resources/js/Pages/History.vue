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
        <div class="max-w-4xl mx-auto px-4 py-8">
            <h1 class="text-2xl font-extrabold text-[var(--color-ink)]">Lịch sử quét mã</h1>
            <p class="text-sm text-[var(--color-muted)] mt-1 mb-6">Những sản phẩm bạn đã dán link để tìm mã, mới nhất ở trên.</p>

            <div v-if="links?.data?.length" class="space-y-4">
                <div
                    v-for="link in links.data"
                    :key="link.id"
                    class="card p-4 sm:p-5 flex gap-3 sm:gap-4 items-start"
                >
                    <div class="w-16 h-16 rounded-xl bg-[var(--color-peach-soft)] flex-none overflow-hidden">
                        <img v-if="link.product_image" :src="link.product_image" :alt="link.product_name" class="w-full h-full object-cover" />
                        <div v-else class="w-full h-full flex items-center justify-center text-2xl">🛍️</div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1 flex-wrap">
                            <!-- Nhãn sàn là THÔNG TIN, không phải hành động: trả nó về chip trung
                                 tính để màu nhấn trong hàng này chỉ còn một chỗ — cái link bấm
                                 được ở mép phải. -->
                            <span class="panel border border-[var(--color-line)] text-xs font-bold text-[var(--color-ink)] px-2 py-0.5">
                                {{ platformLabels[link.platform] }}
                            </span>
                            <span class="text-xs text-[var(--color-muted)] num">{{ new Date(link.created_at).toLocaleDateString('vi-VN') }}</span>
                        </div>
                        <p class="font-semibold text-[var(--color-ink)] text-sm line-clamp-2">{{ link.product_name || 'Sản phẩm' }}</p>
                        <div class="flex items-center gap-3 mt-1 text-sm flex-wrap">
                            <!-- Giá là chữ thường in đậm, không phải màu nhấn: khách đang lướt cả
                                 chục hàng, mười cái giá cùng màu lửa thì không cái nào nổi. -->
                            <span class="num font-bold text-[var(--color-ink)]">{{ vnd(link.discounted_price || 0) }}</span>
                            <!-- Mã tìm được = kết quả tốt → màu tiền. --color-brand-green chỉ đạt
                                 3.41:1 ở chế độ sáng nên không được làm chữ. -->
                            <span v-if="link.vouchers?.length" class="text-xs text-[var(--color-money)] font-bold">
                                <span class="num">{{ link.vouchers.length }}</span> mã giảm giá
                            </span>
                        </div>
                    </div>
                    <a v-if="historyRebuy && link.short_url" :href="link.short_url" target="_blank" rel="noopener"
                        class="focus-ring flex-none inline-flex items-center min-h-[44px] px-2 -mr-2 rounded-lg text-xs font-bold text-[var(--color-accent-deep)] hover:underline whitespace-nowrap">
                        Mở link →
                    </a>
                    <Link v-else-if="!historyRebuy" href="/"
                        class="focus-ring flex-none inline-flex items-center min-h-[44px] px-2 -mr-2 rounded-lg text-xs font-bold text-[var(--color-accent-deep)] hover:underline whitespace-nowrap">
                        Dán lại link →
                    </Link>
                </div>

                <!-- Pagination: chiều cao đặt bằng min-h-[44px] chứ không py-2 — hai nút này nằm
                     sát nhau nên bấm hụt là nhảy nhầm trang. -->
                <div v-if="links?.last_page > 1" class="flex items-center justify-center gap-2 mt-8 text-sm">
                    <Link v-if="links.prev_page_url" :href="links.prev_page_url"
                        class="focus-ring inline-flex items-center min-h-[44px] px-4 rounded-xl border border-[var(--color-line)] text-sm font-semibold text-[var(--color-ink)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent-deep)] transition">
                        ← Trước
                    </Link>
                    <span class="text-[var(--color-muted)] num px-2">Trang {{ links.current_page }}/{{ links.last_page }}</span>
                    <Link v-if="links.next_page_url" :href="links.next_page_url"
                        class="focus-ring inline-flex items-center min-h-[44px] px-4 rounded-xl border border-[var(--color-line)] text-sm font-semibold text-[var(--color-ink)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent-deep)] transition">
                        Tiếp →
                    </Link>
                </div>
            </div>

            <div v-else class="text-center py-16">
                <p class="text-4xl mb-4">📋</p>
                <p class="text-[var(--color-ink)] font-bold">Chưa có lịch sử quét mã nào.</p>
                <p class="text-sm text-[var(--color-muted)] mt-1 max-w-xs mx-auto leading-relaxed">
                    Mỗi lần bạn dán link sản phẩm để tìm mã, lần đó sẽ được lưu lại ở đây.
                </p>
                <Link href="/" class="btn-fire mt-5 inline-flex items-center justify-center px-6 rounded-xl no-underline">
                    Lấy mã ngay →
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
