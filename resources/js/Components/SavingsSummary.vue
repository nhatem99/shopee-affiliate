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

        <div v-if="cashback > 0" class="mt-4 bg-[var(--color-green-soft)] rounded-xl px-4 py-3 flex items-center gap-2">
            <span class="text-[var(--color-brand-green)]">💰</span>
            <div>
                <p class="text-xs text-[var(--color-brand-green)] font-semibold">Hoàn tiền dự kiến (ước tính)</p>
                <p class="font-extrabold text-[var(--color-brand-green)]">+{{ vnd(cashback) }}</p>
                <p class="text-[10px] text-[var(--color-brand-green)]/70 mt-0.5">Ước tính theo tỷ lệ hoa hồng hiện tại, có thể thay đổi và chưa phải số tiền cam kết.</p>
            </div>
        </div>

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
