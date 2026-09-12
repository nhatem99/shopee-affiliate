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

        <div class="space-y-3 text-sm">
            <div class="flex justify-between text-[var(--color-muted)]">
                <span>Giá gốc</span>
                <span class="line-through">{{ vnd(savings.original) }}</span>
            </div>
            <div class="flex justify-between text-[var(--color-accent)]">
                <span>Giảm sản phẩm</span>
                <span>-{{ vnd(savings.product_discount) }}</span>
            </div>
            <div v-if="savings.voucher_discount > 0" class="flex justify-between text-[var(--color-accent)]">
                <span>Mã voucher</span>
                <span>-{{ vnd(savings.voucher_discount) }}</span>
            </div>
            <div class="border-t border-[var(--color-line)] pt-3 flex justify-between font-extrabold text-[var(--color-ink)] text-base">
                <span>Tạm tính</span>
                <span>{{ vnd(savings.final_price) }}</span>
            </div>
        </div>

        <!-- Đã gỡ khối "Hoàn tiền dự kiến (ước tính)".
             Con số đó tính bằng GIÁ BÁN nhân một tỉ lệ hoa hồng đoán sẵn, trong khi tiền hoàn thật
             lại tính trên hoa hồng RÒNG mà Shopee báo về sau khi đơn hoàn thành, nhân tỉ lệ admin
             đặt (xem CashbackService::sync). Hai cách tính lệch nhau cả chục lần.
             Hiện một con số rồi trả về ví một con số khác hẳn là cách nhanh nhất để mất niềm tin
             của đúng nhóm khách chịu đăng nhập để nhận hoàn tiền. Prop `cashback` giữ nguyên để
             không phải sửa nơi gọi; chỉ thôi hiển thị. -->

        <div class="mt-4 bg-[var(--color-peach-soft)] rounded-xl px-4 py-3 text-center">
            <p class="text-xs text-[var(--color-muted)]">Bạn tiết kiệm</p>
            <p class="font-extrabold text-[var(--color-accent)] text-xl">{{ vnd(savings.total_saved) }}</p>
            <p class="text-xs text-[var(--color-muted)]">({{ savings.pct_saved }}% so với giá gốc)</p>
        </div>

        <a
            :href="affiliateLink"
            target="_blank"
            rel="noopener noreferrer"
            class="btn-fire mt-5 flex items-center justify-center gap-2 w-full py-3.5 rounded-xl"
        >
            Mở Shopee &amp; mua ngay →
        </a>
    </div>
</template>
