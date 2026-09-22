<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useCashback } from '@/composables/useCashback'
import { tierStyle } from '@/tierStyles'

// Tiến độ hạng của CHÍNH người đang đăng nhập. Dùng ở hai nơi: thẻ hạng trong Tài khoản
// (Pages/Profile.vue) và ngay trên bảng hạng ở trang khách (Components/MembershipTiers.vue) —
// một component chứ không phải hai bản chép tay, vì phần chữ dưới đây là phần dễ nói sai nhất
// của cả tính năng và hai nơi mà lệch nhau thì khách sẽ tin nơi nào có lợi hơn.
//
// Điều phải nói cho đúng: `current` là hạng ĐANG hưởng, xét từ tổng của quý TRƯỚC và đứng yên
// suốt quý này; còn số tiền hoàn đang chạy của quý này chỉ quyết định hạng của quý SAU
// (`pending`). Gộp hai thứ lại thành một con số là khách thấy mình "đã lên hạng Vàng" rồi thắc
// mắc sao ví vẫn cộng theo hạng Đồng.
const props = defineProps({
    tier: { type: Object, required: true },
    // Bản gọn dùng khi khối này nằm chen giữa bảng hạng ở trang khách: bỏ tiêu đề và nút dẫn
    // sang Tài khoản (đang đứng ngay dưới bảng hạng rồi, không cần mời đi tiếp).
    compact: { type: Boolean, default: false },
})

const { vnd } = useCashback()

const style = computed(() => tierStyle(props.tier.current.key))
const willChange = computed(() => props.tier.pending.key !== props.tier.current.key)
</script>

<template>
    <div class="rounded-2xl border p-5" :class="style.card">
        <div class="flex items-center gap-3">
            <div
                class="flex-none w-12 h-12 rounded-2xl flex items-center justify-center text-xl shadow-sm"
                :class="style.icon"
            >{{ tier.current.icon }}</div>
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-bold uppercase tracking-wide text-[var(--color-muted)]">Hạng của bạn</p>
                <p class="font-extrabold text-lg leading-tight" :class="style.text">{{ tier.current.label }}</p>
            </div>
            <div class="flex-none text-right">
                <p class="text-[11px] font-bold uppercase tracking-wide text-[var(--color-muted)]">Thưởng thêm</p>
                <p class="font-extrabold text-lg leading-tight" :class="style.text">+{{ tier.current.bonus }}%</p>
            </div>
        </div>

        <!-- Nói rõ đặc quyền là gì bằng tiền, không để mỗi con số "+1%" treo lơ lửng. -->
        <p v-if="tier.current.bonus > 0" class="text-xs text-[var(--color-muted)] mt-3 leading-relaxed">
            Mọi đơn hoàn thành trong {{ tier.quarter }} được cộng thêm
            <b class="text-[var(--color-ink)]">{{ tier.current.bonus }}%</b> vào tỉ lệ hoàn tiền.
        </p>

        <div class="mt-4">
            <div class="h-2 rounded-full bg-[var(--color-line)] overflow-hidden">
                <div
                    class="h-full rounded-full transition-all duration-500"
                    :class="style.bar"
                    :style="{ width: Math.max(3, tier.progress) + '%' }"
                ></div>
            </div>

            <p class="text-xs text-[var(--color-muted)] mt-2 leading-relaxed">
                {{ tier.quarter }} bạn đã được hoàn
                <b class="text-[var(--color-ink)]">{{ vnd(tier.earned) }}</b>.
                <template v-if="tier.next">
                    Còn <b class="text-[var(--color-ink)]">{{ vnd(tier.to_next) }}</b> nữa là đạt
                    hạng {{ tier.next.label }} (+{{ tier.next.bonus }}%) — áp dụng từ {{ tier.next_review }}.
                </template>
                <template v-else>
                    Bạn đang ở hạng cao nhất, không còn mốc nào để leo nữa.
                </template>
            </p>

            <!-- Tin vui duy nhất đáng tách riêng: số của quý này đã đủ để đổi hạng vào đầu quý
                 sau. Không hiện cho trường hợp giữ nguyên hạng — thừa một dòng chữ. -->
            <p
                v-if="willChange"
                class="text-xs mt-2 rounded-xl px-3 py-2 leading-relaxed bg-[var(--color-green-soft)] text-[var(--color-brand-green)] font-semibold"
            >
                Từ {{ tier.next_review }} bạn sẽ ở hạng {{ tier.pending.label }} (+{{ tier.pending.bonus }}%).
            </p>
        </div>

        <div v-if="!compact" class="mt-4 pt-3 border-t border-[var(--color-line)] flex flex-wrap gap-x-4 gap-y-1">
            <Link href="/hoan-tien#hang-thanh-vien" class="text-xs font-semibold text-[var(--color-accent)] hover:underline">
                Xem tất cả các hạng →
            </Link>
            <Link href="/don-hang" class="text-xs font-semibold text-[var(--color-accent)] hover:underline">
                Xem từng đơn và tiền hoàn →
            </Link>
        </div>
    </div>
</template>
