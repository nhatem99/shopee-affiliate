<script setup>
import { ref } from 'vue'
import { useClipboard } from '@vueuse/core'
import axios from 'axios'

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
    <div class="relative flex rounded-xl overflow-hidden border border-[var(--color-ticket-border)] bg-[var(--color-ticket)] transition-shadow dark:hover:shadow-[0_0_24px_rgba(var(--color-accent-rgb),0.12)]">
        <!-- Left notch -->
        <div class="absolute left-[calc(50%-12px)] top-0 bottom-0 flex flex-col justify-between pointer-events-none z-10">
            <div class="w-6 h-3 rounded-b-full bg-[var(--color-bg)]"></div>
            <div class="border-l-2 border-dashed border-[var(--color-ticket-border)] flex-1 mx-auto"></div>
            <div class="w-6 h-3 rounded-t-full bg-[var(--color-bg)]"></div>
        </div>

        <!-- Left: discount info -->
        <div class="flex-1 px-5 py-4 pr-8">
            <div class="flex items-center gap-2 mb-1">
                <p class="text-xs font-semibold text-[var(--color-muted)] uppercase tracking-wide">
                    {{ isFreeship ? 'Freeship' : 'Voucher' }}
                </p>
                <span v-if="source === 'facebook'" class="text-xs bg-blue-100 text-blue-700 font-semibold px-1.5 py-0.5 rounded">📘 Facebook</span>
                <span v-else-if="source === 'youtube'" class="text-xs bg-red-100 text-red-700 font-semibold px-1.5 py-0.5 rounded">▶️ YouTube</span>
            </div>
            <p class="text-lg font-extrabold text-[var(--color-ink)]">{{ label }}</p>
            <p v-if="subtitle" class="text-xs text-[var(--color-muted)] mt-0.5">{{ subtitle }}</p>
            <p v-if="minimumOrder > 0" class="text-xs text-[var(--color-muted)] mt-1">
                Đơn tối thiểu {{ formatVnd(minimumOrder) }}
            </p>
            <p v-if="expiresAt" class="text-xs text-[var(--color-muted)]">
                HSD: {{ new Date(expiresAt).toLocaleDateString('vi-VN') }}
            </p>
        </div>

        <!-- Right: code + copy -->
        <div class="flex flex-col items-center justify-center px-5 py-4 pl-8 min-w-[130px]">
            <p class="font-mono font-bold text-[var(--color-ink)] text-sm tracking-widest mb-2">{{ code }}</p>
            <button
                @click="onCopyClick"
                :class="copied ? 'bg-[var(--color-brand-green)] text-white' : 'btn-fire'"
                class="px-4 py-1.5 rounded-lg text-xs font-semibold transition-all duration-300"
            >
                {{ copied ? '✓ Đã sao chép' : 'Copy mã' }}
            </button>
        </div>
    </div>
</template>
