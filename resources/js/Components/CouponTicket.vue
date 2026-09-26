<script setup>
import { useClipboard } from '@vueuse/core'
import axios from 'axios'

/**
 * Một thẻ mã giảm giá (trang chủ + trang kết quả).
 *
 * DÙNG CHUNG MỘT MÔ HÌNH THẺ VỚI VoucherOfferCard: trước đây cùng khái niệm "mã giảm giá"
 * mà khách gặp hai cái thẻ khác hẳn nhau (vé vàng ở đây, thẻ nền trắng ray cam ở
 * /ma-giam-gia) và hai động từ khác nhau ("Copy mã" / "Áp dụng"). Cùng một thứ thì phải
 * trông như một thứ, nếu không khách phải học lại giao diện ở mỗi trang.
 * Động từ chung là "Lấy mã" — mô tả ĐÍCH ĐẾN (có mã trong tay), không mô tả cơ chế; cơ chế
 * hai bên vốn khác nhau (bên này chép vào bộ nhớ tạm, bên kia mở Shopee lưu thẳng vào
 * tài khoản) nên không thể gộp, còn đích đến thì giống hệt.
 */
const props = defineProps({
    code: { type: String, required: true },
    discountType: { type: String, default: 'flat' },
    discountValue: { type: Number, default: 0 },
    minimumOrder: { type: Number, default: 0 },
    expiresAt: { type: String, default: null },
    isFreeship: { type: Boolean, default: false },
    source: { type: String, default: null }, // facebook|youtube|manual
    subtitle: { type: String, default: null },
})

const { copy, copied } = useClipboard({ source: props.code })

function onCopyClick() {
    copy()
    // Ghi nhận sự kiện copy mã, không chặn UI nếu request lỗi/chậm.
    axios.post('/track/event', {
        event_type: 'voucher_copy',
        voucher_code: props.code,
        source: props.source,
        product_name: props.subtitle,
    }).catch(() => {})
}

function formatVnd(n) {
    return '₫' + Number(n).toLocaleString('vi-VN')
}

const label = props.isFreeship
    ? `Miễn phí vận chuyển`
    : props.discountType === 'percent'
        ? `Giảm ${props.discountValue}%`
        : `Giảm ${formatVnd(props.discountValue)}`
</script>

<template>
    <div class="card overflow-hidden p-4 flex flex-col gap-2 transition-shadow dark:hover:shadow-[0_0_24px_rgba(var(--color-accent-rgb),0.12)]">
        <!-- Hàng nguồn mã. Đã bỏ cái nhãn "VOUCHER"/"FREESHIP" ở đầu thẻ: dòng tiêu đề
             ngay dưới đã nói đúng cái đó rồi ("Miễn phí vận chuyển" / "Giảm 50.000đ"), hai
             lần cùng một thông tin chỉ làm thẻ cao thêm.
             Hai nhãn Facebook/YouTube giữ nguyên cặp màu sáng/tối sẵn có — chúng đã đúng cả
             hai chế độ, thay bằng token là mất luôn sắc thái "đây là logo của kênh đó". -->
        <div v-if="source === 'facebook' || source === 'youtube'" class="flex items-center gap-2 flex-wrap">
            <span v-if="source === 'facebook'" class="text-xs bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 font-semibold px-1.5 py-0.5 rounded">📘 Facebook</span>
            <span v-else class="text-xs bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300 font-semibold px-1.5 py-0.5 rounded">▶️ YouTube</span>
        </div>

        <!-- Số tiền được giảm = tiền của khách → màu tiền, không phải màu cam.
             Cam giữ nguyên cho đúng một chỗ trong thẻ: cái nút. -->
        <p class="num text-lg font-extrabold leading-tight text-[var(--color-money)]">{{ label }}</p>

        <p v-if="subtitle" class="text-sm text-[var(--color-muted)] leading-snug">{{ subtitle }}</p>

        <p v-if="minimumOrder > 0" class="num text-xs text-[var(--color-muted)]">
            Đơn tối thiểu {{ formatVnd(minimumOrder) }}
        </p>

        <p v-if="expiresAt" class="num text-xs text-[var(--color-muted)]">
            Dùng trước ngày {{ new Date(expiresAt).toLocaleDateString('vi-VN') }}
        </p>

        <!-- Ô mã + nút: cùng hình dạng với VoucherOfferCard (dải chìm, viền đứt, mã bên
             trái, một nút bên phải), và luôn là khối CUỐI thẻ ở cả hai bên — chỗ tay cầm
             điện thoại chạm tới dễ nhất, và mắt đọc hết lý do rồi mới tới nút.
             Nút có min-w cố định vì chữ đổi từ "Lấy mã" sang "✓ Đã chép" khi bấm: không
             ghim bề rộng thì cả ô mã giật sang trái đúng lúc khách vừa chạm vào. -->
        <div class="panel mt-auto flex items-center gap-2 px-3 py-2 border border-dashed border-[var(--color-line)]">
            <div class="min-w-0 flex-1">
                <p class="text-xs uppercase tracking-wide font-semibold text-[var(--color-muted)]">Mã giảm giá</p>
                <p class="num font-mono text-sm font-bold text-[var(--color-ink)] truncate">{{ code }}</p>
            </div>
            <button
                type="button"
                @click="onCopyClick"
                :class="copied
                    ? 'bg-[var(--color-money-soft)] text-[var(--color-money)] border border-[rgba(var(--color-money-rgb),0.45)]'
                    : 'btn-fire'"
                class="focus-ring shrink-0 inline-flex items-center justify-center min-h-[44px] min-w-[96px] px-4 rounded-lg text-xs font-bold whitespace-nowrap transition-colors duration-200"
            >
                {{ copied ? '✓ Đã chép' : 'Lấy mã' }}
            </button>
        </div>
    </div>
</template>
