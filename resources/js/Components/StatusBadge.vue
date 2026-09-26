<script setup>
import { computed } from 'vue'

// Nhãn trạng thái dùng chung cho các màn hình TIỀN.
//
// Trước đây bản đồ màu này bị chép tay ở 7+ nơi (Orders, Profile và 5 trang admin) và phần lớn
// là bg-yellow-100 / bg-green-100 / bg-red-100 TRẦN — không có bản tối đi kèm. Mà tối là chế độ
// MẶC ĐỊNH của trang và nền là navy: nền pastel sáng trưng nằm giữa thẻ tối, còn chữ thì vẫn là
// yellow-700 trên nền vàng nhạt, đọc được nhưng nhìn như dán nhầm từ trang khác sang.
// Gom về một chỗ và ánh xạ sang token ngữ nghĩa nên tự sống ở cả hai chế độ, không cần dark:.
//
// Đợt này chỉ thay ở trang khách; 5 trang admin giữ nguyên, sẽ chuyển sau.

const props = defineProps({
    // Khoá trạng thái. Hai dòng đời đang dùng chung bảng này:
    //   đơn hàng : waiting → reconciling → credited → paid   (cancelled là nhánh chết)
    //   lệnh rút : pending → approved → completed            (rejected là nhánh chết)
    status: { type: String, required: true },
    // Chữ hiện ra. Bỏ trống thì lấy nhãn mặc định bên dưới. Truyền vào khi trang đã có sẵn câu
    // chữ riêng — Orders.vue mô tả từng chặng bằng lời của nó ("Chờ Shopee xác nhận") chứ không
    // dùng nhãn cụt ở đây.
    label: { type: String, default: '' },
})

// Bốn vai, không hơn: đang chờ / đang chạy / tiền đã về tay khách / hỏng.
// Cố ý KHÔNG cho trạng thái nào mượn --color-accent: cam là của nút hành động chính, badge mà
// cũng cam thì cả màn hình không còn chỗ nào nhấn được.
const TONES = {
    // Chờ phía bên kia động tay (Shopee xác nhận đơn, bên mình duyệt lệnh rút).
    waiting: 'warn',
    pending: 'warn',
    // Đang chạy, không cần khách làm gì, cũng chưa có tiền — trung tính.
    reconciling: 'info',
    approved: 'info',
    // Tiền đã thuộc về khách.
    credited: 'money',
    paid: 'money',
    completed: 'money',
    // Mất tiền / bị từ chối.
    cancelled: 'danger',
    rejected: 'danger',
}

// Viết đủ cả chuỗi lớp ở đây (không ghép chuỗi) để Tailwind quét thấy mà sinh ra lớp tương ứng.
const TONE_CLASS = {
    warn: 'bg-[var(--color-warn-soft)] text-[var(--color-warn)]',
    info: 'bg-[var(--color-info-soft)] text-[var(--color-info)]',
    money: 'bg-[var(--color-money-soft)] text-[var(--color-money)]',
    danger: 'bg-[var(--color-danger-soft)] text-[var(--color-danger)]',
}

const LABELS = {
    waiting: 'Chờ xác nhận',
    reconciling: 'Đang đối soát',
    credited: 'Đã cộng vào ví',
    paid: 'Đã rút về ví',
    cancelled: 'Đã huỷ',
    pending: 'Chờ duyệt',
    approved: 'Đã duyệt',
    completed: 'Đã chuyển',
    rejected: 'Từ chối',
}

const toneClass = computed(() => TONE_CLASS[TONES[props.status]] ?? TONE_CLASS.info)
const text = computed(() => props.label || LABELS[props.status] || props.status)
</script>

<template>
    <!-- text-xs là sàn cứng: tiếng Việt có dấu chồng (ế, ộ, ữ), nhỏ hơn 12px là phần dấu bị bóp
         nghẹt, mà đây đúng là chỗ khách đọc kỹ nhất — nó trả lời "tiền của mình đang ở đâu". -->
    <span
        class="inline-flex items-center text-xs font-bold px-2 py-0.5 rounded-md whitespace-nowrap"
        :class="toneClass"
    >{{ text }}</span>
</template>
