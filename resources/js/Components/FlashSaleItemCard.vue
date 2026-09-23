<script setup>
import { computed, ref } from 'vue'
import axios from 'axios'

/**
 * Một thẻ sản phẩm ở trang /flashsale.
 *
 * Không có ô mã như VoucherOfferCard — đây là link sản phẩm thường (tự dựng từ
 * shopid+itemid, xem FlashSaleSyncService), không phải link áp voucher, nên chỉ có
 * MỘT nút "Mua ngay" dẫn thẳng sang Shopee.
 *
 * `status` ('past'|'current'|'upcoming') do trang cha tính sẵn theo khung giờ, truyền
 * xuống thay vì mỗi thẻ tự tính lại — xem FlashSale.vue::slotStatus().
 */
const props = defineProps({
    item: { type: Object, required: true },
    status: { type: String, default: 'current' },
})

const imageFailed = ref(false)

function vnd(n) {
    return '₫' + Number(n || 0).toLocaleString('vi-VN')
}

const hasDiscount = computed(() => props.item.originalPrice && props.item.originalPrice > props.item.price)

const statusLabel = computed(() => ({
    current: 'Đang diễn ra',
    upcoming: 'Sắp diễn ra',
    past: 'Đã kết thúc',
}[props.status] ?? ''))

// Suất còn ít (dưới 10) đáng nhấn hơn số suất còn nhiều — khách hiểu ngay "nên bấm sớm".
const isLowStock = computed(() => props.item.amount > 0 && props.item.amount < 10)

function onClaim() {
    axios.post('/track/event', {
        event_type: 'flashsale_claim',
        product_name: props.item.title,
        source: 'flashsale',
    }).catch(() => {})
}
</script>

<template>
    <a
        :href="item.claimUrl"
        target="_blank"
        rel="nofollow noopener"
        class="group flex flex-col rounded-2xl overflow-hidden border border-[var(--color-line)] bg-[var(--color-surface)] transition-shadow hover:shadow-lg dark:hover:shadow-[0_0_24px_rgba(var(--color-accent-rgb),0.12)]"
        @click="onClaim"
    >
        <div class="relative aspect-square bg-[var(--color-bg)]">
            <img
                v-if="item.image && !imageFailed"
                :src="item.image"
                :alt="item.title"
                loading="lazy"
                class="w-full h-full object-cover"
                @error="imageFailed = true"
            />
            <div v-else class="w-full h-full flex items-center justify-center text-3xl">🛍️</div>

            <span v-if="item.percent > 0" class="absolute top-2 left-2 px-2 py-1 rounded-lg bg-[var(--color-accent)] text-white text-xs font-extrabold shadow">
                -{{ item.percent }}%
            </span>
            <span class="absolute top-2 right-2 px-2 py-0.5 rounded-md bg-black/60 text-white text-[10px] font-semibold backdrop-blur-sm">
                {{ item.timeSlot }}
            </span>
        </div>

        <div class="flex-1 flex flex-col gap-1.5 p-3">
            <p class="text-sm font-medium text-[var(--color-ink)] leading-snug line-clamp-2 min-h-[2.5rem]">
                {{ item.title }}
            </p>

            <div class="flex items-baseline gap-1.5 flex-wrap">
                <span class="text-base font-extrabold text-[var(--color-accent)]">{{ vnd(item.price) }}</span>
                <span v-if="hasDiscount" class="text-xs text-[var(--color-muted)] line-through">{{ vnd(item.originalPrice) }}</span>
            </div>

            <div class="flex items-center justify-between gap-2 text-[11px] text-[var(--color-muted)]">
                <span :class="status === 'current' ? 'text-[var(--color-accent)] font-semibold' : ''">{{ statusLabel }}</span>
                <span v-if="item.amount" :class="isLowStock ? 'text-[var(--color-accent)] font-semibold' : ''">
                    Còn {{ item.amount }} suất
                </span>
            </div>

            <span class="mt-1 btn-fire text-center px-3 py-2 rounded-lg text-xs font-bold">
                Mua ngay →
            </span>
        </div>
    </a>
</template>
