<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useAuthStore } from '@/Stores/useAuthStore'
import { useCashback } from '@/composables/useCashback'

// Bảng vàng hoàn tiền tháng này cho trang khách.
//
// Mục đích là để người MỚI thấy tiền hoàn là có thật và có người đang nhận — nên phần thuyết
// phục nhất không phải bảng xếp hạng mà là dòng "tháng này đã hoàn ₫X cho N người" ở đầu khối.
// Bảng xếp hạng là phần chơi: bục top 3 + danh sách tới hạng 10 + hạng của chính người xem.
//
// Số liệu lấy từ server (CashbackLeaderboardService): tên đã che, tiền là tiền đã thật sự vào ví
// trong tháng. Component không tự tính gì thêm — số ở đây mà lệch với ví khách là mất tin ngay.
const props = defineProps({
    // { month, entries: [{rank, name, amount, orders, is_me}], total_users, total_amount, me }
    leaderboard: { type: Object, default: null },
    // `compact` = bản nằm trong TAB ở trang chủ, ngay dưới ô dán link: bỏ section + tiêu đề lớn,
    // con số tổng của tháng dồn vào một dải mỏng trên đầu thẻ. Bản đầy đủ (có tiêu đề, dùng ở
    // /hoan-tien) và bản này là cùng một thẻ — không chép ra hai component để rồi lệch nhau.
    compact: { type: Boolean, default: false },
})

const auth = useAuthStore()
const { joinHref, joinLabel, vnd } = useCashback()

const entries = computed(() => props.leaderboard?.entries ?? [])
const podium = computed(() => entries.value.slice(0, 3))
const rest = computed(() => entries.value.slice(3))
const me = computed(() => props.leaderboard?.me ?? null)
const hasData = computed(() => entries.value.length > 0)

// Bục xếp theo thứ tự 2 – 1 – 3 để hạng nhất đứng giữa và cao nhất; thiếu người thì ô đó trống
// chứ không dồn lại — bục lệch đi nhìn còn lạ hơn là một ô đang chờ.
const podiumSlots = computed(() => [
    { entry: podium.value[1] ?? null, rank: 2 },
    { entry: podium.value[0] ?? null, rank: 1 },
    { entry: podium.value[2] ?? null, rank: 3 },
])

const rankStyle = {
    1: { medal: '🥇', ring: 'from-amber-300 via-yellow-400 to-orange-400', bar: 'h-20 md:h-24', label: 'Hạng nhất', tone: 'text-amber-500' },
    2: { medal: '🥈', ring: 'from-slate-300 via-gray-200 to-slate-400', bar: 'h-14 md:h-16', label: 'Hạng nhì', tone: 'text-slate-400' },
    3: { medal: '🥉', ring: 'from-orange-300 via-amber-600 to-orange-700', bar: 'h-10 md:h-12', label: 'Hạng ba', tone: 'text-orange-600' },
}

// Chữ cái đầu làm avatar — tên đã che nên lấy chữ đầu của chữ đầu tiên là đủ, vừa không lộ
// thêm gì, vừa cho mỗi người một hình đại diện thay vì một dãy ảnh mặc định giống nhau.
function initial(name) {
    return (name || '?').trim().charAt(0).toUpperCase()
}

// Mỗi tên một màu cố định (băm theo ký tự) để cùng một người lần nào cũng ra cùng màu.
const avatarPalette = [
    'from-rose-400 to-pink-500',
    'from-sky-400 to-blue-500',
    'from-emerald-400 to-teal-500',
    'from-violet-400 to-purple-500',
    'from-amber-400 to-orange-500',
    'from-cyan-400 to-sky-500',
]
function avatarColor(name) {
    let h = 0
    for (const ch of name || '') h = (h * 31 + ch.charCodeAt(0)) >>> 0
    return avatarPalette[h % avatarPalette.length]
}

const inTopList = computed(() => entries.value.some(e => e.is_me))
// Server đang trả số liệu minh hoạ (admin bật, tháng này chưa có ai) — phải nói ra, không giấu.
const isDemo = computed(() => props.leaderboard?.demo === true)
</script>

<template>
    <section id="leaderboard" :class="compact ? '' : 'py-14 px-4 bg-[var(--color-bg)] scroll-mt-20'">
        <div :class="compact ? '' : 'max-w-3xl mx-auto'">
            <!-- Tiêu đề + con số tổng của tháng (bản đầy đủ) -->
            <div v-if="!compact" class="text-center mb-8">
                <span class="step-badge step-badge--orange px-2.5 py-1 text-xs">THÁNG {{ leaderboard?.month }}</span>
                <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-ink)] mt-3 mb-3">
                    🏆 Bảng vàng hoàn tiền tháng này
                </h2>
                <p v-if="hasData && isDemo" class="text-[var(--color-muted)] text-sm leading-relaxed max-w-xl mx-auto">
                    Bảng đang hiện <b class="text-[var(--color-ink)]">số liệu minh hoạ</b> để bạn hình dung — tháng này
                    chưa có đơn nào được đối soát xong. Người thật đầu tiên lên bảng là số mẫu tự biến mất.
                </p>
                <p v-else-if="hasData" class="text-[var(--color-muted)] text-sm leading-relaxed max-w-xl mx-auto">
                    Tháng này tụi mình đã hoàn
                    <b class="text-[var(--color-brand-green)] text-base">{{ vnd(leaderboard.total_amount) }}</b>
                    vào ví của <b class="text-[var(--color-ink)]">{{ leaderboard.total_users }}</b> người.
                    Tên đã được che một phần để giữ riêng tư.
                </p>
                <p v-else class="text-[var(--color-muted)] text-sm leading-relaxed max-w-xl mx-auto">
                    Bảng tháng này còn trống — tiền chỉ lên bảng khi đơn đã <b>Hoàn thành</b> trên Shopee và
                    được đối soát, nên đầu tháng thường vắng. Người đầu tiên có thể là bạn.
                </p>
            </div>

            <div class="card-glass overflow-hidden" :class="compact ? 'rounded-2xl' : 'rounded-3xl'">
                <!-- Dải tổng của tháng (bản compact) — thay cho tiêu đề lớn ở trên -->
                <div v-if="compact" class="px-4 py-2.5 bg-gradient-to-r from-[var(--color-accent)] to-[var(--color-accent-deep)] text-white text-xs font-semibold flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                    <span class="font-mono tracking-wide">🏆 THÁNG {{ leaderboard?.month }}</span>
                    <span v-if="hasData && isDemo" class="inline-flex items-center gap-1"><span class="px-1.5 py-0.5 rounded bg-white/20 text-[10px] uppercase tracking-wide">Minh hoạ</span> Bảng mẫu, chưa có số thật</span>
                    <span v-else-if="hasData">Đã hoàn <b class="text-sm">{{ vnd(leaderboard.total_amount) }}</b> cho {{ leaderboard.total_users }} người</span>
                    <span v-else>Chưa có ai lên bảng — bạn đầu tiên nhé</span>
                </div>

                <!-- Bục top 3 -->
                <div
                    v-if="hasData"
                    class="relative px-4 pb-0 bg-gradient-to-b from-[var(--color-peach-soft)] to-transparent"
                    :class="compact ? 'pt-5' : 'pt-8'"
                >
                    <!-- Ánh sáng sau bục -->
                    <div class="pointer-events-none absolute inset-x-0 top-0 h-40 bg-[radial-gradient(ellipse_60%_70%_at_50%_0%,rgba(var(--color-accent-rgb),.18),transparent_70%)]"></div>

                    <div class="relative grid grid-cols-3 gap-2 md:gap-4 items-end">
                        <div
                            v-for="slot in podiumSlots"
                            :key="slot.rank"
                            class="flex flex-col items-center text-center min-w-0"
                        >
                            <template v-if="slot.entry">
                                <!-- Vương miện chỉ cho hạng nhất, lơ lửng nhẹ -->
                                <span v-if="slot.rank === 1" class="text-2xl md:text-3xl leading-none mb-1 animate-float">👑</span>

                                <div class="relative mb-2">
                                    <div
                                        class="rounded-full p-[3px] bg-gradient-to-br shadow-lg"
                                        :class="[rankStyle[slot.rank].ring, slot.rank === 1 ? 'w-[76px] h-[76px] md:w-24 md:h-24' : 'w-14 h-14 md:w-[72px] md:h-[72px]']"
                                    >
                                        <div
                                            class="w-full h-full rounded-full bg-gradient-to-br flex items-center justify-center text-white font-extrabold"
                                            :class="[avatarColor(slot.entry.name), slot.rank === 1 ? 'text-2xl md:text-3xl' : 'text-lg md:text-xl']"
                                        >{{ initial(slot.entry.name) }}</div>
                                    </div>
                                    <span class="absolute -bottom-2 left-1/2 -translate-x-1/2 text-xl md:text-2xl leading-none drop-shadow">{{ rankStyle[slot.rank].medal }}</span>
                                </div>

                                <p class="mt-2 text-xs md:text-sm font-bold text-[var(--color-ink)] truncate w-full px-1">
                                    {{ slot.entry.name }}
                                    <span v-if="slot.entry.is_me" class="text-[var(--color-accent)]">(bạn)</span>
                                </p>
                                <p class="font-extrabold text-[var(--color-brand-green)] leading-tight" :class="slot.rank === 1 ? 'text-base md:text-xl' : 'text-sm md:text-base'">
                                    {{ vnd(slot.entry.amount) }}
                                </p>
                                <p class="text-[11px] text-[var(--color-muted)] mb-2">{{ slot.entry.orders }} đơn</p>
                            </template>
                            <template v-else>
                                <div class="mb-2 w-14 h-14 md:w-[72px] md:h-[72px] rounded-full border-2 border-dashed border-[var(--color-line)] flex items-center justify-center text-[var(--color-muted)] text-xl">?</div>
                                <p class="text-xs text-[var(--color-muted)] mb-2">Còn trống</p>
                            </template>

                            <!-- Thân bục -->
                            <div
                                class="w-full rounded-t-xl bg-gradient-to-b flex items-start justify-center pt-2 text-white font-extrabold text-lg md:text-xl font-mono"
                                :class="[rankStyle[slot.rank].bar, slot.rank === 1 ? 'from-[var(--color-accent)] to-[var(--color-accent-deep)]' : 'from-[var(--color-side-soft)] to-[var(--color-side)]']"
                            >{{ slot.rank }}</div>
                        </div>
                    </div>
                </div>

                <!-- Hạng 4 → 10 -->
                <ol v-if="rest.length" class="divide-y divide-[var(--color-line)]">
                    <li
                        v-for="e in rest"
                        :key="e.rank"
                        class="flex items-center gap-3 px-4 md:px-6 py-3 transition"
                        :class="e.is_me ? 'bg-[var(--color-green-soft)]' : 'hover:bg-[var(--color-peach-soft)]'"
                    >
                        <span class="w-7 text-center font-mono font-extrabold text-sm text-[var(--color-muted)]">{{ e.rank }}</span>
                        <div
                            class="w-9 h-9 rounded-full bg-gradient-to-br flex-none flex items-center justify-center text-white text-sm font-extrabold"
                            :class="avatarColor(e.name)"
                        >{{ initial(e.name) }}</div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-[var(--color-ink)] truncate">
                                {{ e.name }}
                                <span v-if="e.is_me" class="text-[var(--color-brand-green)] text-xs font-bold ml-1">← bạn</span>
                            </p>
                            <p class="text-[11px] text-[var(--color-muted)]">{{ e.orders }} đơn được hoàn</p>
                        </div>
                        <span class="font-extrabold text-[var(--color-brand-green)] text-sm whitespace-nowrap">{{ vnd(e.amount) }}</span>
                    </li>
                </ol>

                <!-- Trạng thái trống -->
                <div v-if="!hasData" class="px-6 py-10 text-center">
                    <div class="text-5xl mb-3">🏁</div>
                    <p class="font-bold text-[var(--color-ink)]">Chưa có ai lên bảng tháng {{ leaderboard?.month }}</p>
                    <p class="text-sm text-[var(--color-muted)] mt-1">Dán link, đăng nhập rồi bấm mua — đơn hoàn thành là bạn có tên ở đây.</p>
                </div>

                <!-- Chân khối: hạng của người xem / lời mời tham gia -->
                <div class="border-t border-[var(--color-line)] px-4 md:px-6 py-4 bg-[var(--color-surface)]">
                    <!-- Đã đăng nhập và có tiền hoàn tháng này -->
                    <div v-if="auth.isLoggedIn && me" class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-[var(--color-accent)] to-[var(--color-accent-deep)] flex items-center justify-center text-white font-mono font-extrabold text-sm shadow-[0_4px_14px_rgba(var(--color-accent-rgb),.35)]">
                                #{{ me.rank }}
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-[var(--color-ink)]">
                                    Bạn đang ở hạng <span class="text-[var(--color-accent)]">#{{ me.rank }}</span>
                                    <template v-if="!inTopList"> — chưa vào top {{ entries.length }}</template>
                                </p>
                                <p class="text-xs text-[var(--color-muted)]">
                                    Tháng này bạn đã nhận <b class="text-[var(--color-brand-green)]">{{ vnd(me.amount) }}</b> từ {{ me.orders }} đơn
                                </p>
                            </div>
                        </div>
                        <Link href="/don-hang" class="text-sm font-semibold text-[var(--color-accent)] hover:underline whitespace-nowrap">Xem đơn của tôi →</Link>
                    </div>

                    <!-- Đã đăng nhập nhưng tháng này chưa có đồng nào -->
                    <div v-else-if="auth.isLoggedIn" class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-[var(--color-ink)]">Bạn chưa có tên trên bảng tháng này</p>
                            <p class="text-xs text-[var(--color-muted)]">Đơn phải Hoàn thành trên Shopee và được đối soát mới tính — mua rồi thì cứ chờ, chưa mua thì dán link thôi.</p>
                        </div>
                        <a href="#voucher-tool" class="btn-fire px-5 py-2.5 rounded-xl text-sm no-underline whitespace-nowrap">Dán link mua ngay</a>
                    </div>

                    <!-- Khách vãng lai: mời đăng ký -->
                    <div v-else class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-[var(--color-ink)]">Muốn có tên ở đây tháng sau?</p>
                            <p class="text-xs text-[var(--color-muted)]">Miễn phí. Chỉ cần đăng nhập trước khi bấm mua — đơn nào được hoàn cũng tự cộng vào bảng.</p>
                        </div>
                        <Link :href="joinHref" class="btn-fire px-5 py-2.5 rounded-xl text-sm no-underline whitespace-nowrap">{{ joinLabel }}</Link>
                    </div>
                </div>
            </div>

            <p v-if="isDemo" class="text-[11px] text-[var(--color-muted)] text-center mt-3 leading-relaxed">
                Số liệu minh hoạ: tên và số tiền trên bảng là ví dụ, không phải người thật. Khi có đơn đầu tiên được đối soát trong tháng, bảng sẽ tự chuyển sang số thật.
            </p>
            <p v-else class="text-[11px] text-[var(--color-muted)] text-center mt-3 leading-relaxed">
                Xếp theo số tiền đã thật sự vào ví trong tháng (đơn hoàn thành + đối soát xong). Bảng làm mới sau mỗi đợt đối soát, không phải tức thì.<template v-if="compact"> Tên đã che một phần để giữ riêng tư.</template>
            </p>
        </div>
    </section>
</template>
