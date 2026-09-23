<script setup>
import { computed, ref, watch, onUnmounted } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useToast } from '@/composables/useToast'
import { useCashback } from '@/composables/useCashback'
import { useFestive } from '@/composables/useFestive'
import { useAuthStore } from '@/Stores/useAuthStore'

/**
 * Thẻ "Điểm danh nhận quà" — mỗi ngày bấm một lần, bốc ngẫu nhiên một phần quà vào ví.
 *
 * MỌI con số ở đây đến từ server (DailyCheckInService::state): mệnh giá quà, số phần còn lại,
 * chuỗi ngày, mốc thưởng. Không gõ lại một số nào trong file này — chúng là tiền thật, chép ra
 * frontend là sớm muộn thẻ quảng cáo một đằng, ví cộng một nẻo.
 *
 * Khách VÃNG LAI vẫn thấy thẻ (state.can_claim = false): đây là mồi đăng ký tốt nhất trên trang,
 * ẩn đi với người chưa đăng nhập là vứt đúng nhóm mà nó nhắm tới.
 */
const props = defineProps({
    state: { type: Object, required: true },
    // Prop nào CỦA TRANG ĐANG MỞ cần lấy lại sau khi điểm danh, ngoài mấy prop mặc định trong
    // claim(). Trang Tài khoản phải thêm 'balance': số dư nằm ngay phía trên thẻ này, không lấy
    // lại thì khách vừa nhận 100đ mà con số to đùng trên đầu vẫn đứng yên — trông như mất tiền.
    reload: { type: Array, default: () => [] },
})

const toast = useToast()
const auth = useAuthStore()
const { joinHref, customerAuthEnabled } = useCashback()
const { celebrate } = useFestive()

const claiming = ref(false)
const showDetail = ref(false)

// Phần quà vừa bốc được, chỉ sống vài giây ngay sau cú bấm rồi tự tắt — xem claim().
const reveal = ref(null)
let revealTimer = null

const REVEAL_MS = 6000

onUnmounted(() => clearTimeout(revealTimer))

function money(n) {
    return Number(n || 0).toLocaleString('vi-VN') + ' đ'
}

const claimed = computed(() => props.state.claimed_today)

// Hết sạch quà có hạn thì KHÔNG im lặng giữ nguyên câu "giải cao nhất": nói thẳng là hôm nay
// chỉ còn mức ăn chắc. Người bấm lúc 11 giờ đêm xứng đáng biết trước thay vì tự đoán.
const soldOut = computed(() => props.state.gifts_left <= 0)

// Còn bao nhiêu ngày nữa tới mốc gần nhất — câu duy nhất giữ người ta quay lại ngày mai.
const nextMilestone = computed(() => {
    const streak = props.state.next_streak || 1

    return props.state.milestones
        .map((m) => {
            // Mốc lặp lại (7, 14, 21... / 30, 60...) nên tính theo phần dư chứ không trừ thẳng.
            // Vừa chạm mốc (streak % days === 0) thì mốc kế cách đúng một chu kỳ nữa, không phải
            // 0 ngày — người vừa ăn mốc 7 không được bảo là mai ăn tiếp.
            const left = m.days - (streak % m.days)

            return { ...m, left }
        })
        .filter((m) => m.left > 0)
        .sort((a, b) => a.left - b.left)[0] ?? null
})

const buttonLabel = computed(() => {
    // Chữ phải bám theo ĐÍCH ĐẾN: joinHref là /register khi đăng ký đang bật và chỉ lùi về
    // /login khi admin tắt đăng ký. Ghi cứng "Đăng nhập" là hứa một trang rồi mở ra trang khác.
    if (!auth.isLoggedIn) {
        return customerAuthEnabled.value ? 'Đăng ký để điểm danh nhận quà' : 'Đăng nhập để điểm danh'
    }

    if (claimed.value) return 'Hôm nay đã điểm danh · ' + money(claimed.value.amount)
    if (!props.state.can_claim) return 'Điểm danh đang tạm dừng'

    return claiming.value ? 'Đang mở quà...' : 'Điểm danh nhận quà ngẫu nhiên'
})

function claim() {
    if (!props.state.can_claim || claiming.value) return

    claiming.value = true

    router.post('/diem-danh', {}, {
        preserveScroll: true,
        // Chỉ xin lại đúng mấy prop đổi sau khi điểm danh. Tải lại cả trang chủ chỉ để cộng
        // 100đ là chạy lại toàn bộ bảng xếp hạng, bảng hạng và danh sách mã gợi ý.
        only: ['dailyCheckIn', 'flash', 'wallet', 'notifications', ...props.reload],
        onSuccess: (page) => {
            const won = page.props.flash?.checkin

            // back()->with('error') vẫn là một lượt thành công với Inertia (302 rồi 200), nên
            // lý do hỏng nằm trong flash chứ không rơi vào onError.
            if (!won) {
                toast.error(page.props.flash?.error || 'Không điểm danh được, thử lại nhé.')

                return
            }

            reveal.value = won
            celebrate()
            toast.success(won.milestone
                ? `Mốc ${won.milestone} ngày! Bạn nhận ${money(won.amount)} vào ví.`
                : `Điểm danh thành công — ${money(won.amount)} đã vào ví.`)

            clearTimeout(revealTimer)
            revealTimer = setTimeout(() => { reveal.value = null }, REVEAL_MS)
        },
        onError: () => toast.error('Không điểm danh được, thử lại nhé.'),
        onFinish: () => { claiming.value = false },
    })
}

// Sang ngày mới (hoặc admin tắt chương trình) thì màn lật quà cũ phải biến mất cùng với nó,
// nếu không nó treo lại trên một thẻ đã ở trạng thái khác hẳn.
watch(() => props.state.today, () => { reveal.value = null })
</script>

<template>
    <section class="rounded-3xl bg-[var(--color-surface)] border border-[var(--color-line)] p-5 md:p-6 shadow-[0_2px_12px_rgba(0,0,0,.04)]">
        <div class="flex items-start justify-between gap-3 mb-3">
            <div class="min-w-0">
                <h2 class="text-base md:text-lg font-extrabold text-[var(--color-ink)] uppercase tracking-wide">
                    Điểm danh nhận quà
                </h2>
                <p class="text-sm font-semibold text-[var(--color-muted)] mt-1">
                    <template v-if="!soldOut">
                        Giải cao nhất hôm nay: <b class="text-[var(--color-accent)]">{{ money(state.top_prize) }}</b>.
                        Còn <b class="text-[var(--color-accent)]">{{ state.gifts_left.toLocaleString('vi-VN') }}</b> phần quà.
                    </template>
                    <template v-else>
                        Hết quà mệnh giá cao hôm nay — mỗi lượt vẫn chắc chắn có
                        <b class="text-[var(--color-accent)]">{{ money(state.base_amount) }}</b>.
                    </template>
                </p>
                <button
                    type="button"
                    @click="showDetail = !showDetail"
                    class="mt-1.5 text-sm font-bold text-[var(--color-accent)] inline-flex items-center gap-0.5 hover:underline"
                >
                    Chi tiết
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 transition-transform" :class="showDetail ? 'rotate-90' : ''"><path d="m9 18 6-6-6-6"/></svg>
                </button>
            </div>

            <!-- Chuỗi ngày: chỉ số này mới là thứ giữ khách quay lại, nên nó đứng ở góc dễ thấy
                 nhất chứ không nằm lẫn trong dòng chữ hướng dẫn phía dưới. -->
            <span
                class="flex-none text-xs font-extrabold px-3 py-1.5 rounded-full whitespace-nowrap"
                :class="state.streak > 0
                    ? 'bg-[var(--color-accent)] text-white'
                    : 'bg-[var(--color-peach-soft)] text-[var(--color-accent)]'"
            >Chuỗi: {{ state.streak }} ngày</span>
        </div>

        <!-- Bảng quà + luật chơi. Đóng sẵn: thẻ này phải bấm được trong một nhịp, ai muốn soi kỹ
             mới mở ra. -->
        <div v-if="showDetail" class="mb-4 rounded-2xl bg-[var(--color-peach-soft)] border border-[var(--color-line)] p-4">
            <p class="text-xs font-extrabold uppercase tracking-wide text-[var(--color-muted)] mb-2">Kho quà hôm nay</p>
            <ul class="space-y-1.5 mb-4">
                <li
                    v-for="prize in state.prizes"
                    :key="prize.amount"
                    class="flex items-center justify-between gap-3 text-sm"
                >
                    <span class="font-bold text-[var(--color-ink)]">{{ money(prize.amount) }}</span>
                    <span v-if="prize.quantity === null" class="text-xs font-semibold text-[var(--color-brand-green)]">
                        Không giới hạn — ai điểm danh cũng có
                    </span>
                    <span v-else class="text-xs font-semibold" :class="prize.left > 0 ? 'text-[var(--color-muted)]' : 'text-[var(--color-muted)] opacity-60'">
                        <template v-if="prize.left > 0">Còn {{ prize.left }}/{{ prize.quantity }} phần</template>
                        <template v-else>Đã hết hôm nay</template>
                    </span>
                </li>
            </ul>

            <p class="text-xs font-extrabold uppercase tracking-wide text-[var(--color-muted)] mb-2">Thưởng mốc chuỗi ngày</p>
            <ul class="space-y-1.5 mb-4">
                <li
                    v-for="m in state.milestones"
                    :key="m.days"
                    class="flex items-center justify-between gap-3 text-sm"
                >
                    <span class="font-bold text-[var(--color-ink)]">{{ m.days }} ngày liên tiếp</span>
                    <span class="text-xs font-extrabold text-[var(--color-accent)]">+{{ money(m.amount) }}</span>
                </li>
            </ul>

            <!-- Nói trước mấy điều bất lợi (chuỗi đứt, chưa rút được) thay vì để khách tự phát
                 hiện: cùng lý do với cột "KHÔNG được hoàn" ở CashbackExplainer. -->
            <ul class="space-y-1.5 text-xs text-[var(--color-muted)] leading-relaxed border-t border-[var(--color-line)] pt-3">
                <li class="flex gap-1.5">
                    <span class="flex-none text-[var(--color-accent)]">•</span>
                    <span>Mỗi tài khoản điểm danh <b class="text-[var(--color-ink)]">1 lần/ngày</b>, quà bốc ngẫu nhiên trong kho quà còn lại của ngày hôm đó.</span>
                </li>
                <li class="flex gap-1.5">
                    <span class="flex-none text-[var(--color-accent)]">•</span>
                    <span>Kho quà <b class="text-[var(--color-ink)]">đầy lại lúc 0 giờ</b> mỗi ngày. Mệnh giá càng cao càng ít phần, hết thì hết tới sáng hôm sau.</span>
                </li>
                <li class="flex gap-1.5">
                    <span class="flex-none text-[var(--color-accent)]">•</span>
                    <span>Nghỉ một ngày là <b class="text-[var(--color-ink)]">chuỗi về 0</b> và phải đếm lại từ đầu. Mốc thưởng lặp lại, nên đi tiếp sau mốc 7 vẫn có mốc.</span>
                </li>
                <li class="flex gap-1.5">
                    <span class="flex-none text-[var(--color-accent)]">•</span>
                    <span>Tiền vào ví ngay, nhưng <b class="text-[var(--color-ink)]">muốn rút phải có ít nhất một đơn hàng được hoàn tiền thật</b> — quà điểm danh một mình không rút ra được.</span>
                </li>
            </ul>
        </div>

        <!-- Bảy ngày trong tuần. grid-cols-7 ở mọi khổ màn hình: xuống dòng giữa tuần là mất
             cảm giác "một chuỗi liền mạch", đúng thứ mà khối này tồn tại để tạo ra. -->
        <div class="grid grid-cols-7 gap-1.5 md:gap-2.5 mb-4">
            <div
                v-for="day in state.week"
                :key="day.date"
                class="rounded-xl md:rounded-2xl border py-2.5 px-1 flex flex-col items-center gap-1.5 transition-colors"
                :class="{
                    'border-[var(--color-brand-green)] bg-[var(--color-green-soft)]': day.state === 'done',
                    'border-[var(--color-accent)] bg-[var(--color-peach-soft)] ring-1 ring-[var(--color-accent)]': day.state === 'today',
                    'border-dashed border-[var(--color-line)] bg-[var(--color-bg)] opacity-70': day.state === 'missed',
                    'border-[var(--color-line)] bg-[var(--color-bg)]': day.state === 'upcoming',
                }"
            >
                <span
                    class="text-[11px] md:text-xs font-extrabold"
                    :class="day.state === 'done' ? 'text-[var(--color-brand-green)]'
                        : day.state === 'today' ? 'text-[var(--color-accent)]'
                        : 'text-[var(--color-muted)]'"
                >{{ day.label }}</span>

                <svg v-if="day.state === 'done'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 text-[var(--color-brand-green)]"><circle cx="12" cy="12" r="10" stroke-width="2"/><path d="m8 12 3 3 5-6"/></svg>
                <svg v-else-if="day.state === 'today'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 text-[var(--color-accent)]"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="m9 16 2 2 4-4" stroke-width="2.5"/></svg>
                <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-5 h-5 text-[var(--color-line)]"><circle cx="12" cy="12" r="9"/></svg>

                <span
                    class="text-[10px] md:text-xs font-extrabold tabular-nums"
                    :class="day.state === 'done' ? 'text-[var(--color-brand-green)]'
                        : day.state === 'today' ? 'text-[var(--color-accent)]'
                        : 'text-[var(--color-muted)]'"
                >+{{ Number(day.amount).toLocaleString('vi-VN') }}</span>
            </div>
        </div>

        <!-- Màn lật quà: chiếm chỗ của dòng hướng dẫn chứ không đè lên nó (overlay trên điện
             thoại là che mất chính cái nút vừa bấm). Tự tắt sau REVEAL_MS. -->
        <div
            v-if="reveal"
            class="mb-4 rounded-2xl bg-gradient-to-br from-[var(--color-accent)] to-[var(--color-accent-deep)] text-white px-4 py-3.5 text-center"
        >
            <p class="text-xs font-bold uppercase tracking-wide opacity-90">
                {{ reveal.milestone ? `Mốc ${reveal.milestone} ngày liên tiếp!` : 'Bạn vừa nhận được' }}
            </p>
            <p class="text-3xl font-extrabold leading-tight my-0.5 tabular-nums">{{ money(reveal.amount) }}</p>
            <p v-if="reveal.bonus > 0" class="text-xs font-semibold opacity-90">
                Gồm {{ money(reveal.prize) }} quà may mắn + {{ money(reveal.bonus) }} thưởng mốc
            </p>
            <p v-else class="text-xs font-semibold opacity-90">Đã cộng vào ví · Chuỗi {{ reveal.streak }} ngày</p>
        </div>

        <p v-else class="text-sm font-semibold text-[var(--color-muted)] text-center mb-3 leading-relaxed">
            <template v-if="claimed">
                Đã nhận <b class="text-[var(--color-ink)]">{{ money(claimed.amount) }}</b> hôm nay.
                <template v-if="nextMilestone && nextMilestone.left > 0">
                    Còn {{ nextMilestone.left }} ngày nữa tới mốc {{ nextMilestone.days }} ngày (+{{ money(nextMilestone.amount) }}).
                </template>
                <template v-else>Quay lại vào ngày mai để giữ chuỗi nhé.</template>
            </template>
            <template v-else>
                Điểm danh mỗi ngày để nhận thưởng và mở khóa mốc
                <b class="text-[var(--color-ink)]">{{ state.milestones.map((m) => m.days).join('/') }}</b> ngày.
            </template>
        </p>

        <!-- Khách vãng lai: nút dẫn đi đăng nhập/đăng ký thay vì một nút bấm vào thì báo lỗi. -->
        <Link
            v-if="!auth.isLoggedIn"
            :href="joinHref"
            class="btn-fire w-full py-3.5 rounded-2xl font-extrabold flex items-center justify-center gap-2 no-underline"
        >
            <span>📅</span> {{ buttonLabel }}
        </Link>
        <button
            v-else
            type="button"
            @click="claim"
            :disabled="!state.can_claim || claiming"
            class="btn-fire w-full py-3.5 rounded-2xl font-extrabold flex items-center justify-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed"
        >
            <span>{{ claimed ? '✅' : '📅' }}</span> {{ buttonLabel }}
        </button>
    </section>
</template>
