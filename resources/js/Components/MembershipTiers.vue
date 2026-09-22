<script setup>
import { Link } from '@inertiajs/vue3'
import { useAuthStore } from '@/Stores/useAuthStore'
import { useCashback } from '@/composables/useCashback'
import { tierStyle } from '@/tierStyles'
import MembershipTierProgress from '@/Components/MembershipTierProgress.vue'

// Bảng "Hạng thành viên & Đặc quyền" cho trang chủ và trang /hoan-tien.
//
// Toàn bộ số liệu (tên hạng, mốc tích luỹ, phần thưởng) đến từ server — hằng số TIERS trong
// MembershipTierService. Không gõ lại ở đây dù chỉ một con số: `bonus` là điểm phần trăm được
// đem nhân vào tiền thật của khách, chép ra frontend là sớm muộn bảng quảng bá và ví nói hai
// giá khác nhau, mà bên thiệt luôn là niềm tin của khách.
//
// `me` chỉ có khi khách đã đăng nhập (và không phải admin). Không có thì bảng vẫn đứng nguyên,
// chỉ mất phần tiến độ — đây là khối bán hàng cho người chưa đăng ký, không phải khối tài khoản.
defineProps({
    tiers: { type: Array, required: true },
    me: { type: Object, default: null },
})

const auth = useAuthStore()
const { joinHref, joinLabel, vnd } = useCashback()
</script>

<template>
    <section id="hang-thanh-vien" class="py-14 px-4 bg-[var(--color-bg)] scroll-mt-20">
        <div class="max-w-5xl mx-auto">
            <div class="text-center mb-8">
                <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-ink)] mb-2">
                    Hạng thành viên &amp; Đặc quyền
                </h2>
                <p class="text-[var(--color-muted)] text-sm">
                    Hoàn càng nhiều, hạng càng cao, tiền hoàn càng lớn
                </p>
            </div>

            <!-- Tiến độ của chính người đang xem, đặt TRƯỚC bảng: người đã đăng nhập vào đây để
                 xem mình đang ở đâu, không phải để đọc lại bảng giá. -->
            <MembershipTierProgress v-if="me" :tier="me" compact class="mb-6" />

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                <div
                    v-for="tier in tiers"
                    :key="tier.key"
                    class="relative rounded-2xl border p-4 text-center flex flex-col"
                    :class="[tierStyle(tier.key).card, me && me.current.key === tier.key ? 'ring-2 ring-[var(--color-accent)]' : '']"
                >
                    <!-- Dải nhãn ghim ở mép trên, không phải góc phải: thẻ chỉ rộng bằng nửa màn
                         hình điện thoại nên nhãn ở góc sẽ đè lên biểu tượng hạng. -->
                    <span
                        v-if="me && me.current.key === tier.key"
                        class="absolute -top-2.5 left-1/2 -translate-x-1/2 whitespace-nowrap text-[10px] font-extrabold px-2 py-0.5 rounded-full bg-[var(--color-accent)] text-white shadow"
                    >HẠNG CỦA BẠN</span>

                    <div
                        class="mx-auto w-12 h-12 rounded-2xl flex items-center justify-center text-xl shadow-sm"
                        :class="tierStyle(tier.key).icon"
                    >{{ tier.icon }}</div>

                    <p class="font-extrabold mt-3 leading-tight" :class="tierStyle(tier.key).text">
                        Hạng {{ tier.label }}
                    </p>
                    <p class="text-[10px] font-bold uppercase tracking-wide text-[var(--color-muted)] mt-1">
                        Thưởng thêm
                    </p>
                    <p class="text-2xl font-extrabold leading-tight mb-3" :class="tierStyle(tier.key).text">
                        +{{ tier.bonus }}%
                    </p>

                    <!-- mt-auto: tên hạng dài ("Kim cương") xuống dòng trên màn hình hẹp, không
                         ghim dòng mốc xuống đáy thì sáu cái thẻ lệch nhau một nấc. -->
                    <div class="mt-auto pt-3 border-t border-[var(--color-line)] text-xs text-[var(--color-muted)] leading-relaxed">
                        <template v-if="tier.threshold > 0">
                            Tích lũy <b class="text-[var(--color-ink)]">&gt; {{ vnd(tier.threshold) }}</b> tiền hoàn
                        </template>
                        <template v-else>Mặc định khi đăng ký</template>
                    </div>
                </div>
            </div>

            <!-- Cơ chế xét hạng. Viết đủ cả chiều HẠ hạng và chuyện không tính ngược, dù hai ý
                 đó không có lợi cho việc bán hàng: cùng một lý do với cột "KHÔNG được hoàn" ở
                 CashbackExplainer — khách đã gặp quá nhiều trang hứa rồi im lặng, nên nói trước
                 mấy điều bất lợi là thứ duy nhất làm phần còn lại đáng tin. -->
            <div class="card-glass rounded-2xl p-5 mt-6">
                <p class="font-bold text-[var(--color-ink)] text-sm mb-3">ⓘ Cơ chế xét hạng</p>
                <ul class="space-y-2 text-sm text-[var(--color-muted)] leading-relaxed">
                    <li class="flex gap-2">
                        <span class="flex-none text-[var(--color-accent)]">•</span>
                        <span>Hạng thành viên được xét dựa trên <b class="text-[var(--color-ink)]">tổng số tiền hoàn đã duyệt trong một quý</b> (3 tháng).</span>
                    </li>
                    <li class="flex gap-2">
                        <span class="flex-none text-[var(--color-accent)]">•</span>
                        <span>Bạn sẽ được nâng hạng hoặc bị hạ hạng tùy vào tổng số tiền hoàn tích lũy được trong <b class="text-[var(--color-ink)]">quý trước</b>.</span>
                    </li>
                    <li class="flex gap-2">
                        <span class="flex-none text-[var(--color-accent)]">•</span>
                        <span>Hạng được cập nhật tự động vào ngày đầu tiên của mỗi quý (<b class="text-[var(--color-ink)]">1/1, 1/4, 1/7, 1/10</b>).</span>
                    </li>
                    <li class="flex gap-2">
                        <span class="flex-none text-[var(--color-accent)]">•</span>
                        <span>Phần thưởng hạng áp cho các đơn được ghi hoàn tiền <b class="text-[var(--color-ink)]">kể từ lúc bạn đang ở hạng đó</b> — lên hạng không tính ngược lại các khoản đã vào ví.</span>
                    </li>
                    <li class="flex gap-2">
                        <span class="flex-none text-[var(--color-accent)]">•</span>
                        <span v-if="auth.isLoggedIn">
                            Theo dõi tiến độ nâng hạng trong mục
                            <Link href="/profile" class="font-semibold text-[var(--color-accent)] hover:underline">Tài khoản</Link>.
                        </span>
                        <span v-else>
                            <Link :href="joinHref" class="font-semibold text-[var(--color-accent)] hover:underline">{{ joinLabel }}</Link>
                            để bắt đầu tích lũy — mọi tài khoản mới đều vào hạng Tân binh.
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
