<script setup>
defineProps({
    savings: { type: Object, required: true },
    cashback: { type: Number, default: 0 },
    affiliateLink: { type: String, required: true },
})

function vnd(n) {
    return '₫' + Number(n).toLocaleString('vi-VN')
}
</script>

<template>
    <div class="card-glass rounded-2xl p-6 sticky top-20">
        <h3 class="font-extrabold text-[var(--color-ink)] text-base mb-4">Tóm tắt tiết kiệm</h3>

        <!-- Mọi dòng tiền đều .num: cột số bên phải phải thẳng hàng thì khách mới cộng trừ
             bằng mắt được. Phần ĐƯỢC GIẢM là tiền của khách → màu tiền, không phải màu cam
             (cam để dành cho cái nút mở Shopee ở cuối thẻ). -->
        <div class="space-y-3 text-sm">
            <div class="flex justify-between text-[var(--color-muted)]">
                <span>Giá gốc</span>
                <span class="num line-through">{{ vnd(savings.original) }}</span>
            </div>
            <div class="flex justify-between text-[var(--color-money)]">
                <span>Giảm sản phẩm</span>
                <span class="num">-{{ vnd(savings.product_discount) }}</span>
            </div>
            <div v-if="savings.voucher_discount > 0" class="flex justify-between text-[var(--color-money)]">
                <span>Mã giảm giá</span>
                <span class="num">-{{ vnd(savings.voucher_discount) }}</span>
            </div>
            <div class="border-t border-[var(--color-line)] pt-3 flex justify-between font-extrabold text-[var(--color-ink)] text-base">
                <span>Tạm tính</span>
                <span class="num">{{ vnd(savings.final_price) }}</span>
            </div>
        </div>

        <!-- Đã gỡ khối "Hoàn tiền dự kiến (ước tính)".
             Con số đó tính bằng GIÁ BÁN nhân một tỉ lệ hoa hồng đoán sẵn, trong khi tiền hoàn thật
             lại tính trên hoa hồng RÒNG mà Shopee báo về sau khi đơn hoàn thành, nhân tỉ lệ admin
             đặt (xem CashbackService::sync). Hai cách tính lệch nhau cả chục lần.
             Hiện một con số rồi trả về ví một con số khác hẳn là cách nhanh nhất để mất niềm tin
             của đúng nhóm khách chịu đăng nhập để nhận hoàn tiền. Prop `cashback` giữ nguyên để
             không phải sửa nơi gọi; chỉ thôi hiển thị. -->

        <!-- Con số khách quan tâm nhất trong cả thẻ. Nền peach (cam nhạt) cũ làm nó trông
             như một mẩu quảng cáo; nền màu tiền làm nó trông như một khoản thật. -->
        <div class="mt-4 bg-[var(--color-money-soft)] rounded-xl px-4 py-3 text-center">
            <p class="text-xs text-[var(--color-muted)]">Bạn tiết kiệm</p>
            <p class="num font-extrabold text-[var(--color-money)] text-xl">{{ vnd(savings.total_saved) }}</p>
            <p class="num text-xs text-[var(--color-muted)]">({{ savings.pct_saved }}% so với giá gốc)</p>
        </div>

        <!-- Chiều cao đặt bằng min-h chứ không bằng py: đây là nút chính của cả trang kết
             quả, cao hơn ngưỡng chạm 44px một chút cho chắc tay. -->
        <a
            :href="affiliateLink"
            target="_blank"
            rel="noopener noreferrer"
            class="btn-fire focus-ring mt-5 flex items-center justify-center gap-2 w-full min-h-[52px] px-4 rounded-xl"
        >
            Mở Shopee &amp; mua ngay →
        </a>
    </div>
</template>
