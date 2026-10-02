<script setup>
// Khối "Ưu đãi đang có": mã YTB nào đang có và đã dùng bao nhiêu %. Số liệu do server đọc từ nguồn
// cấp mã (YtbVoucherStatusService), chỉ gửi xuống khi khách đang nhận đúng loại mã này — nên ở đây
// có dữ liệu là hiện, không phải tự đoán gì thêm.
//
// Mỗi mục: { title, subtitle, used_percent (null = nguồn không báo), sold_out }.
defineProps({
    vouchers: { type: Array, required: true },
})
</script>

<template>
    <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4 md:p-5">
        <div class="flex items-center gap-2 mb-3">
            <h2 class="font-extrabold text-[var(--color-ink)] text-sm uppercase tracking-wide">🎁 Ưu đãi đang có</h2>
            <span class="rounded-md bg-[var(--color-accent)] text-white text-[10px] font-extrabold px-1.5 py-0.5">HOT</span>
        </div>

        <ul class="space-y-2.5">
            <li
                v-for="(v, i) in vouchers"
                :key="i"
                class="flex items-stretch rounded-xl border border-[var(--color-line)] overflow-hidden"
                :class="v.sold_out ? 'opacity-60' : ''"
            >
                <!-- Dải trái: kênh của mã (YouTube), cùng logo với banner khung giờ back mã. -->
                <div class="flex-none w-16 bg-[#FF0000] flex flex-col items-center justify-center gap-1 text-white">
                    <svg viewBox="0 0 24 24" class="w-6 h-6" aria-hidden="true">
                        <path d="M9.5 8.2v7.6l6.5-3.8-6.5-3.8Z" fill="#fff" />
                    </svg>
                    <span class="text-[9px] font-extrabold uppercase tracking-wide">YouTube</span>
                </div>

                <div class="flex-1 min-w-0 px-3 py-2.5">
                    <div class="flex items-start justify-between gap-2">
                        <p class="font-bold text-[var(--color-ink)] text-sm leading-snug">{{ v.title }}</p>
                        <span
                            v-if="v.sold_out"
                            class="flex-none rounded-full bg-[var(--color-line)] text-[var(--color-muted)] text-[11px] font-bold px-2 py-0.5"
                        >Hết mã</span>
                        <span
                            v-else
                            class="flex-none inline-flex items-center gap-1 rounded-full bg-[var(--color-green-soft)] text-[var(--color-brand-green)] text-[11px] font-bold px-2 py-0.5"
                        ><span class="w-1.5 h-1.5 rounded-full bg-[var(--color-brand-green)]"></span>Còn mã</span>
                    </div>
                    <p v-if="v.subtitle" class="text-xs text-[var(--color-muted)] mt-0.5">{{ v.subtitle }}</p>

                    <template v-if="v.used_percent !== null">
                        <div
                            class="mt-2 h-1.5 rounded-full bg-[var(--color-line)] overflow-hidden"
                            role="progressbar"
                            :aria-valuenow="v.used_percent"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            :aria-label="`Đã dùng ${v.used_percent}%`"
                        >
                            <div class="h-full rounded-full bg-[var(--color-accent)]" :style="{ width: `${v.used_percent}%` }"></div>
                        </div>
                        <p class="text-[11px] text-[var(--color-muted)] mt-1">Đã dùng {{ v.used_percent }}%</p>
                    </template>
                </div>
            </li>
        </ul>
    </div>
</template>
