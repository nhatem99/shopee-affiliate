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

/**
 * Màu của dòng trạng thái khung giờ. Trước đây "Đang diễn ra" cũng màu cam, cùng lúc với
 * badge giảm giá, giá bán, "còn N suất" và cái nút — năm thứ cùng một màu thì không thứ
 * nào nổi, mắt khách không biết bấm vào đâu. Giờ cam chỉ còn ở nút "Mua ngay".
 */
const statusClass = computed(() => ({
    current: 'text-[var(--color-money)] font-semibold',
    upcoming: 'text-[var(--color-info)] font-semibold',
    past: 'text-[var(--color-muted)]',
}[props.status] ?? 'text-[var(--color-muted)]'))

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
        class="card focus-ring group flex flex-col overflow-hidden transition-shadow hover:shadow-lg dark:hover:shadow-[0_0_24px_rgba(var(--color-accent-rgb),0.12)]"
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

            <!-- Hai nhãn đè lên ảnh nên phải có NỀN ĐỤC: nền trong suốt nằm trên ảnh sản
                 phẩm là ảnh màu gì thì chữ mất tăm màu đó. Dùng --color-surface cho cả hai
                 chế độ, chữ đổi vai: badge giảm sâu màu DANGER, khung giờ chỉ là chú thích
                 nên để màu chữ mờ. Cam đã rút hết khỏi thẻ, chỉ còn ở nút "Mua ngay". -->
            <span v-if="item.percent > 0" class="num absolute top-2 left-2 px-2 py-1 rounded-lg bg-[var(--color-surface)] border border-[rgba(var(--color-danger-rgb),0.45)] text-[var(--color-danger)] text-xs font-extrabold shadow">
                -{{ item.percent }}%
            </span>
            <!-- Có viền: nền surface ở chế độ sáng là màu TRẮNG, mà ảnh sản phẩm Shopee
                 phần lớn cũng nền trắng — không viền thì cái nhãn tan vào ảnh, chữ giờ
                 trông như in thẳng lên sản phẩm. Badge -N% bên trái đã tự có viền danger. -->
            <span class="num absolute top-2 right-2 px-2 py-0.5 rounded-md bg-[var(--color-surface)] border border-[var(--color-line)] text-[var(--color-muted)] text-xs font-semibold shadow">
                {{ item.timeSlot }}
            </span>
        </div>

        <div class="flex-1 flex flex-col gap-1.5 p-3">
            <!-- 2.75rem = đúng hai dòng text-sm theo nhịp dòng tiếng Việt (1.55). Bản cũ để
                 2.5rem nên tên hai dòng tràn ra ngoài phần đã chừa, làm các thẻ cùng hàng
                 lệch nhau vài pixel. -->
            <p class="text-sm font-medium text-[var(--color-ink)] leading-snug line-clamp-2 min-h-[2.75rem]">
                {{ item.title }}
            </p>

            <!-- Giá bán là tiền khách PHẢI TRẢ, không phải tiền khách được hưởng, nên để
                 màu chữ thường; màu tiền dành cho phần khách tiết kiệm được. -->
            <div class="flex items-baseline gap-1.5 flex-wrap">
                <span class="num text-base font-extrabold text-[var(--color-ink)]">{{ vnd(item.price) }}</span>
                <span v-if="hasDiscount" class="num text-xs text-[var(--color-muted)] line-through">{{ vnd(item.originalPrice) }}</span>
            </div>

            <!-- gap-y đi kèm gap-x: thẻ chỉ rộng ~165px ở màn 375px (lưới 2 cột), nên
                 "Đang diễn ra" + "Còn 9 suất" xuống dòng là chuyện thường — chỉ khai
                 gap-x thì hai dòng dính sát nhau, đọc ra một cục. -->
            <div class="flex items-center justify-between flex-wrap gap-x-2 gap-y-0.5 text-xs text-[var(--color-muted)]">
                <span :class="statusClass">{{ statusLabel }}</span>
                <span v-if="item.amount" class="num" :class="isLowStock ? 'text-[var(--color-warn)] font-semibold' : ''">
                    Còn {{ item.amount }} suất
                </span>
            </div>

            <!-- mt-auto: nút nằm sát đáy thẻ ở mọi thẻ, nên cả hàng thẻ có một đường nút
                 thẳng dù tên sản phẩm dài ngắn khác nhau. -->
            <span class="mt-auto btn-fire flex items-center justify-center min-h-[44px] px-3 rounded-lg text-xs font-bold">
                Mua ngay →
            </span>
        </div>
    </a>
</template>
