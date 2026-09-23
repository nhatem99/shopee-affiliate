<script setup>
import { computed, ref } from 'vue'
import axios from 'axios'

/**
 * Một thẻ mã ở trang /ma-giam-gia.
 *
 * `now` được TRUYỀN TỪ TRÊN XUỐNG chứ không tự đặt setInterval trong mỗi thẻ: trang cuộn
 * vô tận, tới vài trăm thẻ là vài trăm bộ đếm chạy song song, điện thoại nóng ran mà tất
 * cả chỉ để hiển thị cùng một giây.
 */
const props = defineProps({
    offer: { type: Object, required: true },
    now: { type: Number, required: true },
})

const imageFailed = ref(false)

const platformLabels = {
    shopee: 'Shopee',
    shopeefood: 'ShopeeFood',
    lazada: 'Lazada',
    tiktok: 'TikTok Shop',
    tiki: 'Tiki',
}

const platformLabel = computed(() => platformLabels[props.offer.platform] ?? props.offer.platform)

// Chữ cái đầu của tên shop, dùng khi ảnh mã hỏng hoặc chưa tải xong.
const initials = computed(() => (props.offer.shopName ?? platformLabel.value).slice(0, 2).toUpperCase())

const msLeft = computed(() => (props.offer.endsAt ? new Date(props.offer.endsAt).getTime() - props.now : null))

const remaining = computed(() => {
    if (msLeft.value === null) return null
    if (msLeft.value <= 0) return 'Đã hết hạn'

    const s = Math.floor(msLeft.value / 1000)
    const d = Math.floor(s / 86400)
    const h = Math.floor((s % 86400) / 3600)
    const m = Math.floor((s % 3600) / 60)

    // Còn nhiều ngày thì đếm từng giây chỉ làm rối mắt; chỉ khi sắp hết mới cần gấp gáp.
    if (d > 0) return `Còn ${d} ngày ${h} giờ`
    if (h > 0) return `Còn ${h} giờ ${m} phút`

    return `Còn ${m} phút ${s % 60} giây`
})

// Dưới 24 giờ thì tô màu nhấn — đây là lúc "còn hạn" đổi từ thông tin nền thành lý do bấm ngay.
const isExpiring = computed(() => msLeft.value !== null && msLeft.value < 86400000)

// Ghi nhận không chặn UI: link vẫn mở dù request hỏng hay mạng chậm.
function onClaim() {
    axios.post('/track/event', {
        event_type: 'voucher_claim',
        voucher_code: props.offer.code,
        platform: props.offer.platform,
        product_name: props.offer.subtitle,
        source: 'catalog',
    }).catch(() => {})
}
</script>

<template>
    <div class="flex rounded-2xl overflow-hidden border border-[var(--color-line)] bg-[var(--color-surface)] transition-shadow hover:shadow-lg dark:hover:shadow-[0_0_24px_rgba(var(--color-accent-rgb),0.12)]">
        <!-- Cột trái: ảnh mã + tên sàn -->
        <div class="relative w-24 shrink-0 bg-gradient-to-b from-[var(--color-accent)] to-[var(--color-accent-deep)] flex flex-col items-center justify-center gap-2 py-4">
            <div class="w-14 h-14 rounded-xl bg-white overflow-hidden flex items-center justify-center shadow-sm">
                <img
                    v-if="offer.voucherImage && !imageFailed"
                    :src="offer.voucherImage"
                    :alt="offer.shopName ?? platformLabel"
                    loading="lazy"
                    class="w-full h-full object-contain"
                    @error="imageFailed = true"
                />
                <span v-else class="text-sm font-extrabold text-[var(--color-accent)]">{{ initials }}</span>
            </div>
            <span class="px-2 py-0.5 rounded-md bg-white/90 text-[10px] font-bold text-[var(--color-accent-deep)] leading-tight text-center">
                {{ platformLabel }}
            </span>

            <!-- Khía vé: hai nửa hình tròn màu nền, cắt vào đường ranh giữa hai cột -->
            <div class="absolute -right-2 top-1/2 -translate-y-1/2 w-4 h-4 rounded-full bg-[var(--color-bg)]"></div>
        </div>

        <!-- Cột phải: nội dung -->
        <div class="flex-1 min-w-0 p-4 flex flex-col gap-2">
            <p class="text-xs text-[var(--color-muted)] truncate">{{ offer.shopName ?? platformLabel }}</p>

            <div>
                <p class="text-lg font-extrabold text-[var(--color-ink)] leading-tight">{{ offer.title }}</p>
                <p v-if="offer.subtitle" class="text-sm font-semibold text-[var(--color-accent)] leading-snug">
                    {{ offer.subtitle }}
                </p>
            </div>

            <!-- Thanh lượt dùng: cho khách biết mã còn thật hay sắp cạn trước khi bấm -->
            <div v-if="offer.usageText">
                <div class="flex justify-between items-baseline gap-2 text-xs text-[var(--color-muted)]">
                    <span class="truncate">{{ offer.usageText }}</span>
                    <span class="shrink-0 font-semibold">{{ offer.usagePercent }}%</span>
                </div>
                <div class="mt-1 h-1.5 rounded-full bg-[var(--color-line)] overflow-hidden">
                    <div
                        class="h-full rounded-full bg-gradient-to-r from-[var(--color-accent)] to-[var(--color-accent-deep)] transition-all"
                        :style="{ width: `${offer.usagePercent}%` }"
                    ></div>
                </div>
            </div>

            <!-- Ô mã + nút Áp dụng.
                 KHÔNG có nút sao chép: bấm "Áp dụng" là mã tự lưu vào tài khoản Shopee của
                 khách (link mang promotionId + signature), nên chép tay rồi dán lúc thanh
                 toán là một đường vòng dài hơn cho cùng một kết quả. Mã vẫn hiện để khách
                 đối chiếu, chỉ bỏ cái nút.
                 rel=nofollow: đây là link tiếp thị liên kết, không phải link giới thiệu nội
                 dung. noopener bắt buộc khi target=_blank. -->
            <div class="flex items-center gap-2 rounded-xl border border-dashed border-[var(--color-line)] bg-[var(--color-bg)] px-3 py-2">
                <div class="min-w-0 flex-1">
                    <p class="text-[10px] uppercase tracking-wide text-[var(--color-muted)] font-semibold">Mã giảm giá</p>
                    <p class="font-mono text-sm font-bold text-[var(--color-ink)] truncate">{{ offer.code }}</p>
                </div>
                <a
                    :href="offer.claimUrl"
                    target="_blank"
                    rel="nofollow noopener"
                    class="btn-fire shrink-0 px-4 py-2 rounded-lg text-xs font-bold whitespace-nowrap"
                    @click="onClaim"
                >
                    Áp dụng →
                </a>
            </div>

            <div class="text-xs">
                <span :class="isExpiring ? 'text-[var(--color-accent)] font-semibold' : 'text-[var(--color-muted)]'">
                    {{ remaining ?? 'Không ghi hạn' }}
                </span>
            </div>
        </div>
    </div>
</template>
