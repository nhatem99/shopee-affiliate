<script setup>
import { computed, ref } from 'vue'
import axios from 'axios'

/**
 * Một thẻ mã ở trang /ma-giam-gia.
 *
 * CÙNG MÔ HÌNH THẺ VỚI CouponTicket (xem ghi chú trong file đó): cùng khung .card, cùng ô
 * mã viền đứt, cùng một động từ "Lấy mã". Ray cam cao suốt thẻ ở bản cũ đã bỏ — nó là một
 * mảng cam to bằng nửa thẻ, đứng cạnh cái nút cũng màu cam, nên không còn chỗ nào "nổi"
 * nữa. Nhận diện sàn/shop rút về một ô ảnh 36px ở hàng đầu, đủ để khách biết mã của ai.
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

const isExpired = computed(() => msLeft.value !== null && msLeft.value <= 0)

// Dưới 24 giờ thì tô màu nhắc — đây là lúc "còn hạn" đổi từ thông tin nền thành lý do bấm
// ngay. Màu CHỜ/CHÚ Ý (--color-warn) chứ không phải cam: cam để dành cho cái nút.
const isExpiring = computed(() => !isExpired.value && msLeft.value !== null && msLeft.value < 86400000)

// Mã sắp cạn lượt thì thanh chuyển sang màu chú ý; còn nhiều thì đây chỉ là con số trung
// tính, không việc gì phải hét lên.
const usageNearlyGone = computed(() => Number(props.offer.usagePercent) >= 80)

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
    <div class="card overflow-hidden p-4 flex flex-col gap-2 transition-shadow hover:shadow-lg dark:hover:shadow-[0_0_24px_rgba(var(--color-accent-rgb),0.12)]">
        <!-- Hàng nhận diện: ảnh mã + shop + sàn -->
        <div class="flex items-center gap-2">
            <span class="flex-none w-9 h-9 rounded-lg overflow-hidden bg-[var(--color-bg)] border border-[var(--color-line)] flex items-center justify-center">
                <img
                    v-if="offer.voucherImage && !imageFailed"
                    :src="offer.voucherImage"
                    :alt="offer.shopName ?? platformLabel"
                    loading="lazy"
                    class="w-full h-full object-contain"
                    @error="imageFailed = true"
                />
                <span v-else class="text-xs font-extrabold text-[var(--color-muted)]">{{ initials }}</span>
            </span>
            <!-- Dòng sàn chỉ hiện khi có TÊN SHOP ở dòng trên. Mã toàn sàn không có
                 shop_name (cột nullable), lúc đó dòng trên đã rơi về chính tên sàn — in
                 thêm một lần nữa là hai dòng "Shopee" chồng lên nhau ngay đầu thẻ. -->
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-[var(--color-ink)] truncate">{{ offer.shopName ?? platformLabel }}</p>
                <p v-if="offer.shopName" class="text-xs text-[var(--color-muted)] truncate">{{ platformLabel }}</p>
            </div>
        </div>

        <!-- Phần được giảm = tiền của khách → màu tiền. Cam chỉ còn ở cái nút. -->
        <p class="num text-lg font-extrabold leading-tight text-[var(--color-money)]">{{ offer.title }}</p>
        <p v-if="offer.subtitle" class="text-sm text-[var(--color-muted)] leading-snug">
            {{ offer.subtitle }}
        </p>

        <p
            class="num text-xs"
            :class="isExpired
                ? 'text-[var(--color-danger)] font-semibold'
                : isExpiring
                    ? 'text-[var(--color-warn)] font-semibold'
                    : 'text-[var(--color-muted)]'"
        >
            {{ remaining ?? 'Không ghi hạn' }}
        </p>

        <!-- Thanh lượt dùng: cho khách biết mã còn thật hay sắp cạn trước khi bấm -->
        <div v-if="offer.usageText">
            <div class="flex justify-between items-baseline gap-2 text-xs text-[var(--color-muted)]">
                <span class="truncate">{{ offer.usageText }}</span>
                <span class="num shrink-0 font-semibold">{{ offer.usagePercent }}%</span>
            </div>
            <div class="mt-1 h-1.5 rounded-full bg-[var(--color-line)] overflow-hidden">
                <div
                    class="h-full rounded-full transition-all"
                    :class="usageNearlyGone ? 'bg-[var(--color-warn)]' : 'bg-[var(--color-info)]'"
                    :style="{ width: `${offer.usagePercent}%` }"
                ></div>
            </div>
        </div>

        <!-- Ô mã + nút "Lấy mã".
             KHÔNG có nút sao chép: bấm là mã tự lưu vào tài khoản Shopee của khách (link
             mang promotionId + signature), nên chép tay rồi dán lúc thanh toán là một
             đường vòng dài hơn cho cùng một kết quả. Mã vẫn hiện để khách đối chiếu.
             rel=nofollow: đây là link tiếp thị liên kết, không phải link giới thiệu nội
             dung. noopener bắt buộc khi target=_blank. -->
        <div class="panel mt-auto flex items-center gap-2 px-3 py-2 border border-dashed border-[var(--color-line)]">
            <div class="min-w-0 flex-1">
                <p class="text-xs uppercase tracking-wide text-[var(--color-muted)] font-semibold">Mã giảm giá</p>
                <p class="num font-mono text-sm font-bold text-[var(--color-ink)] truncate">{{ offer.code }}</p>
            </div>
            <a
                :href="offer.claimUrl"
                target="_blank"
                rel="nofollow noopener"
                class="btn-fire focus-ring shrink-0 inline-flex items-center justify-center min-h-[44px] px-4 rounded-lg text-xs font-bold whitespace-nowrap"
                @click="onClaim"
            >
                Lấy mã →
            </a>
        </div>
    </div>
</template>
