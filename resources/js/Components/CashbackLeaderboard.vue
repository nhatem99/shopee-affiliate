<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import UserAvatar from '@/Components/UserAvatar.vue'
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

// Ai đã tải ảnh đại diện ở trang Thông tin cá nhân thì hiện ảnh đó (entry.avatar), còn lại vẫn
// là chữ cái đầu trên nền màu như trước — xem Components/UserAvatar.vue.
//
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
                <p v-if="hasData" class="text-[var(--color-muted)] text-sm leading-relaxed max-w-xl mx-auto">
                    Tháng này tụi mình đã hoàn
                    <b class="num text-[var(--color-money)] text-base">{{ vnd(leaderboard.total_amount) }}</b>
                    vào ví của <b class="num text-[var(--color-ink)]">{{ leaderboard.total_users }}</b> người.
                    Tên đã được che một phần để giữ riêng tư.
                </p>
                <p v-else class="text-[var(--color-muted)] text-sm leading-relaxed max-w-xl mx-auto">
                    Bảng tháng này còn trống — tiền chỉ lên bảng khi đơn đã <b>Hoàn thành</b> trên Shopee và
                    được đối soát, nên đầu tháng thường vắng. Người đầu tiên có thể là bạn.
                </p>
            </div>

            <!-- Một bo góc duy nhất cho cả hai bản (trước là rounded-2xl / rounded-3xl tuỳ chỗ,
                 tức cùng một cái bảng mà ở trang chủ và ở /hoan-tien lại bo khác nhau). .card
                 giữ bo góc, viền và bóng trong một chỗ. -->
            <div class="card overflow-hidden">
                <!-- Dải tổng của tháng (bản compact) — thay cho tiêu đề lớn ở trên.
                     Bỏ nền gradient cam chữ trắng: chữ trắng trên #F5511E chỉ đạt 3.5:1 (trượt
                     AA), và đây là chỗ đặt CON SỐ ĐÁNG TIN NHẤT của cả trang — nó cần trông như
                     một dòng số liệu, không như một biển quảng cáo. Tiền để --color-money. -->
                <div v-if="compact" class="panel rounded-none px-4 py-3 border-b border-[var(--color-line)] text-xs font-semibold text-[var(--color-muted)] flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                    <span class="font-mono tracking-wide text-[var(--color-ink)]">🏆 THÁNG {{ leaderboard?.month }}</span>
                    <span v-if="hasData">Đã hoàn <b class="num text-sm text-[var(--color-money)]">{{ vnd(leaderboard.total_amount) }}</b> cho <span class="num">{{ leaderboard.total_users }}</span> người</span>
                    <span v-else>Chưa có ai lên bảng — bạn đầu tiên nhé</span>
                </div>

                <!-- Bục top 3.
                     Bỏ lớp "ánh sáng sau bục" (radial gradient cam): trên màn 375px nó chỉ là một
                     vệt cam mờ sau ba cái avatar, và nó đẩy bảng này về phía "trò chơi" trong khi
                     việc của bảng là làm bằng chứng — số tiền thật, người thật. Nền chuyển peach
                     nhạt giữ lại là đủ ấm. -->
                <div
                    v-if="hasData"
                    class="px-4 pb-0 bg-gradient-to-b from-[var(--color-peach-soft)] to-transparent"
                    :class="compact ? 'pt-5' : 'pt-8'"
                >
                    <div class="grid grid-cols-3 gap-2 md:gap-4 items-end">
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
                                        <UserAvatar
                                            :src="slot.entry.avatar"
                                            :name="slot.entry.name"
                                            :gradient="avatarColor(slot.entry.name)"
                                            class="w-full h-full"
                                            :class="slot.rank === 1 ? 'text-2xl md:text-3xl' : 'text-lg md:text-xl'"
                                        />
                                    </div>
                                    <span class="absolute -bottom-2 left-1/2 -translate-x-1/2 text-xl md:text-2xl leading-none drop-shadow">{{ rankStyle[slot.rank].medal }}</span>
                                </div>

                                <p class="mt-2 text-xs md:text-sm font-bold text-[var(--color-ink)] truncate w-full px-1">
                                    {{ slot.entry.name }}
                                    <!-- Dấu "(bạn)" là thông tin nhận dạng, không phải hành động
                                         và cũng không phải tiền — để --color-info. -->
                                    <span v-if="slot.entry.is_me" class="text-[var(--color-info)]">(bạn)</span>
                                </p>
                                <p class="num font-extrabold text-[var(--color-money)] leading-tight" :class="slot.rank === 1 ? 'text-base md:text-xl' : 'text-sm md:text-base'">
                                    {{ vnd(slot.entry.amount) }}
                                </p>
                                <p class="num text-xs text-[var(--color-muted)] mb-2">{{ slot.entry.orders }} đơn</p>
                            </template>
                            <template v-else>
                                <div class="mb-2 w-14 h-14 md:w-[72px] md:h-[72px] rounded-full border-2 border-dashed border-[var(--color-line)] flex items-center justify-center text-[var(--color-muted)] text-xl">?</div>
                                <p class="text-xs text-[var(--color-muted)] mb-2">Còn trống</p>
                            </template>

                            <!-- Thân bục.
                                 Cả ba bục dùng chung một màu trung tính. Trước đây bục hạng nhất
                                 đổ gradient cam — cùng màu với nút bấm, nên trên một màn hình
                                 điện thoại có tới hai "ngọn lửa" và mắt không biết nhìn đâu. Hạng
                                 nhất vẫn nổi hơn hẳn nhờ vương miện, vòng vàng, avatar to và bục
                                 cao gấp đôi; không cần mượn thêm màu của nút. -->
                            <div
                                class="w-full rounded-t-xl bg-gradient-to-b from-[var(--color-side-soft)] to-[var(--color-side)] flex items-start justify-center pt-2 text-white font-extrabold text-lg md:text-xl font-mono num"
                                :class="rankStyle[slot.rank].bar"
                            >{{ slot.rank }}</div>
                        </div>
                    </div>
                </div>

                <!-- Hạng 4 → 10 -->
                <ol v-if="rest.length" class="divide-y divide-[var(--color-line)]">
                    <li
                        v-for="e in rest"
                        :key="e.rank"
                        class="flex items-center gap-3 px-4 md:px-6 min-h-[56px] py-2 transition"
                        :class="e.is_me ? 'bg-[var(--color-info-soft)]' : 'hover:bg-[var(--color-peach-soft)]'"
                    >
                        <span class="num w-7 text-center font-mono font-extrabold text-sm text-[var(--color-muted)]">{{ e.rank }}</span>
                        <UserAvatar
                            :src="e.avatar"
                            :name="e.name"
                            :gradient="avatarColor(e.name)"
                            class="w-9 h-9 flex-none text-sm"
                        />
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-[var(--color-ink)] truncate">
                                {{ e.name }}
                                <!-- Cùng một vai với "(bạn)" trên bục: nhận dạng, không phải tiền.
                                     Nền dòng cũng đổi sang info-soft cho khớp — màu xanh lá chỉ
                                     nên có một nghĩa duy nhất trên trang này là TIỀN. -->
                                <span v-if="e.is_me" class="text-[var(--color-info)] text-xs font-bold ml-1">← bạn</span>
                            </p>
                            <p class="num text-xs text-[var(--color-muted)]">{{ e.orders }} đơn được hoàn</p>
                        </div>
                        <span class="num font-extrabold text-[var(--color-money)] text-sm whitespace-nowrap">{{ vnd(e.amount) }}</span>
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
                            <!-- Viên số hạng: nền chìm + chữ mực, không còn gradient cam chữ
                                 trắng (3.5:1, trượt AA — và lại là màu của nút bấm). -->
                            <div class="panel num w-11 h-11 flex items-center justify-center text-[var(--color-ink)] font-mono font-extrabold text-sm">
                                #{{ me.rank }}
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-[var(--color-ink)]">
                                    Bạn đang ở hạng <span class="num text-[var(--color-info)]">#{{ me.rank }}</span>
                                    <template v-if="!inTopList"> — chưa vào top {{ entries.length }}</template>
                                </p>
                                <p class="text-xs text-[var(--color-muted)]">
                                    Tháng này bạn đã nhận <b class="num text-[var(--color-money)]">{{ vnd(me.amount) }}</b> từ <span class="num">{{ me.orders }}</span> đơn
                                </p>
                            </div>
                        </div>
                        <Link href="/don-hang" class="focus-ring inline-flex items-center min-h-[44px] rounded-xl text-sm font-semibold text-[var(--color-accent-deep)] hover:underline whitespace-nowrap">Xem đơn của tôi →</Link>
                    </div>

                    <!-- Đã đăng nhập nhưng tháng này chưa có đồng nào -->
                    <div v-else-if="auth.isLoggedIn" class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-[var(--color-ink)]">Bạn chưa có tên trên bảng tháng này</p>
                            <p class="text-xs text-[var(--color-muted)]">Đơn phải Hoàn thành trên Shopee và được đối soát mới tính — mua rồi thì cứ chờ, chưa mua thì dán link thôi.</p>
                        </div>
                        <a href="/#voucher-tool" class="btn-outline inline-flex items-center justify-center px-5 min-h-[44px] rounded-xl text-sm no-underline whitespace-nowrap">Dán link mua ngay</a>
                    </div>

                    <!-- Khách vãng lai: mời đăng ký -->
                    <div v-else class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-[var(--color-ink)]">Muốn có tên ở đây tháng sau?</p>
                            <p class="text-xs text-[var(--color-muted)]">Miễn phí. Chỉ cần đăng nhập trước khi bấm mua — đơn nào được hoàn cũng tự cộng vào bảng.</p>
                        </div>
                        <Link :href="joinHref" class="btn-outline inline-flex items-center justify-center px-5 min-h-[44px] rounded-xl text-sm no-underline whitespace-nowrap">{{ joinLabel }}</Link>
                    </div>
                </div>
            </div>

            <!-- 12px là sàn cứng của cả trang: tiếng Việt có dấu chồng (ế, ộ, ữ), dưới 12px là
                 phần dấu bị bóp nghẹt — mà đây lại đúng là dòng nói rõ số liệu từ đâu ra. -->
            <p class="text-xs text-[var(--color-muted)] text-center mt-3 leading-relaxed">
                Xếp theo số tiền đã thật sự vào ví trong tháng (đơn hoàn thành + đối soát xong). Bảng làm mới sau mỗi đợt đối soát, không phải tức thì.<template v-if="compact"> Tên đã che một phần để giữ riêng tư.</template>
            </p>
        </div>
    </section>
</template>
