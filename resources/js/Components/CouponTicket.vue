<script setup>
import { ref } from 'vue'
import { useClipboard } from '@vueuse/core'

const props = defineProps({
    code: { type: String, required: true },
    discountType: { type: String, default: 'flat' },
    discountValue: { type: Number, default: 0 },
    minimumOrder: { type: Number, default: 0 },
    expiresAt: { type: String, default: null },
    isFreeship: { type: Boolean, default: false },
})

const { copy, copied } = useClipboard({ source: props.code })

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
    <div class="relative flex rounded-xl overflow-hidden border border-[--color-ticket-border] bg-[--color-ticket]">
        <!-- Left notch -->
        <div class="absolute left-[calc(50%-12px)] top-0 bottom-0 flex flex-col justify-between pointer-events-none z-10">
            <div class="w-6 h-3 rounded-b-full bg-[--color-bg]"></div>
            <div class="border-l-2 border-dashed border-[--color-ticket-border] flex-1 mx-auto"></div>
            <div class="w-6 h-3 rounded-t-full bg-[--color-bg]"></div>
        </div>

        <!-- Left: discount info -->
        <div class="flex-1 px-5 py-4 pr-8">
            <p class="text-xs font-semibold text-[--color-muted] uppercase tracking-wide mb-1">
                {{ isFreeship ? 'Freeship' : 'Voucher' }}
            </p>
            <p class="text-lg font-extrabold text-[--color-ink]">{{ label }}</p>
            <p v-if="minimumOrder > 0" class="text-xs text-[--color-muted] mt-1">
                Đơn tối thiểu {{ formatVnd(minimumOrder) }}
            </p>
            <p v-if="expiresAt" class="text-xs text-[--color-muted]">
                HSD: {{ new Date(expiresAt).toLocaleDateString('vi-VN') }}
            </p>
        </div>

        <!-- Right: code + copy -->
        <div class="flex flex-col items-center justify-center px-5 py-4 pl-8 min-w-[130px]">
            <p class="font-mono font-bold text-[--color-ink] text-sm tracking-widest mb-2">{{ code }}</p>
            <button
                @click="copy()"
                :class="copied ? 'bg-[--color-brand-green] text-white' : 'bg-[--color-accent] text-white hover:bg-[--color-accent-deep]'"
                class="px-4 py-1.5 rounded-lg text-xs font-semibold transition-all duration-300"
            >
                {{ copied ? '✓ Đã sao chép' : 'Copy mã' }}
            </button>
        </div>
    </div>
</template>
