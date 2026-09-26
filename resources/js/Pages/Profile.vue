<script setup>
import { ref, computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AccountLayout from '@/Layouts/AccountLayout.vue'
import MembershipTierProgress from '@/Components/MembershipTierProgress.vue'
import DailyCheckIn from '@/Components/DailyCheckIn.vue'
import UserAvatar from '@/Components/UserAvatar.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const props = defineProps({
    profile: Object,
    payoutAccounts: Object,
    balance: Object,
    withdrawals: Array,
    minWithdrawal: Number,
    // Hạng thành viên + tiến độ lên hạng của quý này. null = admin tắt chương trình hạng (hoặc
    // tỉ lệ hoàn tiền = 0), lúc đó thẻ hạng biến mất khỏi trang này.
    tier: { type: Object, default: null },
    // Thẻ Điểm danh nhận quà — null khi admin tắt chương trình, thẻ tự biến mất khỏi trang.
    dailyCheckIn: { type: Object, default: null },
})

function vnd(n) {
    return '₫' + Number(n || 0).toLocaleString('vi-VN')
}

// --- Ví nhận tiền (chỉ đọc ở đây — sửa nằm ở trang Thông tin cá nhân) ---
const providers = [
    { key: 'momo', label: 'Ví MoMo' },
    { key: 'zalopay', label: 'Ví ZaloPay' },
]

const hasAnyAccount = computed(() =>
    !!(props.payoutAccounts?.momo || props.payoutAccounts?.zalopay)
)

// --- Rút tiền ---
const showWithdraw = ref(false)
// Ba điều kiện: đủ mức tối thiểu, có ví nhận tiền, và đã có ít nhất một đơn hoàn tiền thật —
// thưởng người mới không rút được một mình (server cũng chặn, xem WithdrawalController).
const canWithdraw = computed(() =>
    props.balance.available >= props.minWithdrawal && hasAnyAccount.value && props.balance.hasRealCashback
)

const availableProviders = computed(() =>
    providers.filter(p => props.payoutAccounts?.[p.key])
)

const withdrawForm = useForm({
    provider: availableProviders.value[0]?.key || 'momo',
    amount: props.minWithdrawal,
})

function openWithdraw() {
    withdrawForm.clearErrors()
    withdrawForm.provider = availableProviders.value[0]?.key || 'momo'
    withdrawForm.amount = props.minWithdrawal
    showWithdraw.value = true
}

function submitWithdraw() {
    withdrawForm.post('/withdrawals', {
        preserveScroll: true,
        onSuccess: () => { showWithdraw.value = false; toast.success('Đã gửi yêu cầu rút tiền') },
    })
}

// --- Lịch sử rút ---
// Màu trạng thái đã chuyển hẳn sang Components/StatusBadge.vue — bảng chép tay ở đây trước đây
// dùng bg-yellow-100/green-100/blue-100/red-100 trần, không có bản tối nào.
const statusLabels = {
    pending: 'Chờ duyệt',
    approved: 'Đã duyệt',
    completed: 'Đã chuyển',
    rejected: 'Từ chối',
}
const providerLabels = { momo: 'MoMo', zalopay: 'ZaloPay' }
</script>

<template>
    <Head title="Tài khoản" />
    <AccountLayout>
        <div class="space-y-6">
            <h1 class="text-2xl font-extrabold text-[var(--color-ink)]">Tổng quan</h1>

            <!-- Chào mừng: avatar + huy hiệu "Lính mới" + ngày tham gia. Huy hiệu dựa vào
                 hasRealCashback (đã có sẵn cho điều kiện rút tiền) chứ không phải cờ riêng —
                 "mới" ở đây nghĩa là chưa có đơn hoàn tiền thật nào, đúng cái khách cần biết
                 (còn phải mua 1 đơn thì thưởng chào mừng mới rút được, xem khối bên dưới). -->
            <div class="card p-5 flex items-center gap-4">
                <UserAvatar
                    :src="profile.avatar"
                    :name="profile.name || profile.email"
                    class="flex-none w-14 h-14 text-xl"
                />
                <div class="min-w-0">
                    <div class="flex items-center flex-wrap gap-x-2 gap-y-1">
                        <p class="font-bold text-[var(--color-ink)] truncate">{{ profile.name || profile.email }}</p>
                        <!-- Trước là chữ cam trên nền peach-soft: ở chế độ sáng cặp đó chỉ được
                             3.5:1, trượt AA ngay trên một huy hiệu 10px. Đổi sang cặp warn (4.9:1)
                             vừa đọc được vừa giữ được tông ấm của chữ "lính mới". -->
                        <span v-if="!balance.hasRealCashback" class="flex-none text-xs font-bold px-2 py-0.5 rounded-full bg-[var(--color-warn-soft)] text-[var(--color-warn)]">🔥 LÍNH MỚI</span>
                    </div>
                    <p class="text-xs text-[var(--color-muted)] truncate">{{ profile.email }}</p>
                    <p class="text-xs text-[var(--color-muted)]">Thành viên từ: {{ profile.member_since }}</p>
                </div>
            </div>

            <!-- Số dư. Cùng một cách trình bày với AccountDrawer và trang Lịch sử số dư ví: cùng
                 nhãn "SỐ DƯ KHẢ DỤNG", cùng màu tiền, cùng .num. Trước đây ba nơi vẽ ba kiểu (ở
                 đây là chữ xanh trên thẻ trắng, hai nơi kia là chữ trắng trên thẻ gradient cam) —
                 khách đa nghi nhìn ba khuôn mặt khác nhau của cùng một con số thì bắt đầu ngờ là
                 ba con số khác nhau. Gradient cam cũng bị bỏ: cam là của NÚT BẤM. -->
            <div class="card p-6">
                <div class="flex items-end justify-between flex-wrap gap-4">
                    <div>
                        <!-- Giữ nhãn "khả dụng" chứ không đổi thành "rút được": con số này là hiệu
                             của hoa hồng đã duyệt trừ phần đang giữ cho các lệnh rút, nên có thể
                             bằng 0 hoặc âm, và kể cả khi dương vẫn chưa rút được nếu chưa khai ví. -->
                        <p class="text-xs font-bold uppercase tracking-wide text-[var(--color-muted)]">Số dư khả dụng</p>
                        <p class="text-3xl font-extrabold num text-[var(--color-money)] mt-0.5">{{ vnd(balance.available) }}</p>
                        <p class="text-xs text-[var(--color-muted)] mt-1 num">
                            Đã duyệt: {{ vnd(balance.earned) }} · Đang giữ: {{ vnd(balance.reserved) }}
                        </p>
                        <!-- Con số ở trên là tổng; đây là đường tới phần giải thích nó được cộng
                             từ những đơn nào.
                             Màu trung tính chứ không cam: hai lối này nằm CÙNG MỘT THẺ với nút
                             "Rút tiền" — để cam thì trong một khung nhìn có ba vệt cam ngang hàng
                             nhau và cái duy nhất khách cần bấm không còn nổi lên nữa. Dùng đúng
                             --color-info như link phụ ở trang Đơn hàng. -->
                        <div class="flex flex-wrap gap-x-4 gap-y-1 mt-1">
                            <Link href="/don-hang" class="focus-ring inline-flex items-center min-h-[44px] pr-2 rounded-xl text-xs font-semibold text-[var(--color-info)] hover:underline">
                                Xem từng đơn và tiền hoàn →
                            </Link>
                            <Link href="/vi/lich-su" class="focus-ring inline-flex items-center min-h-[44px] pr-2 rounded-xl text-xs font-semibold text-[var(--color-info)] hover:underline">
                                Lịch sử số dư ví →
                            </Link>
                        </div>
                    </div>
                    <button
                        @click="openWithdraw"
                        :disabled="!canWithdraw"
                        class="btn-fire focus-ring inline-flex items-center justify-center text-sm px-5 rounded-xl"
                    >
                        Rút tiền
                    </button>
                </div>

                <!-- Thanh tiến độ tới mốc rút. Chỉ hiện khi đã có tiền thật trong ví: hiện với ví
                     rỗng thì thành lời trách "bạn còn thiếu 10.000đ" ngay khi khách vừa đăng ký.
                     Điều kiện > 0 còn chặn luôn trường hợp số âm — availableBalance() là hiệu của
                     hoa hồng đã duyệt trừ phần đang giữ nên hoàn toàn có thể âm (xem User.php). -->
                <div v-if="balance.available > 0 && balance.available < minWithdrawal" class="mt-4">
                    <div class="h-2 rounded-full bg-[var(--color-line)] overflow-hidden">
                        <div
                            class="h-full rounded-full bg-[var(--color-money)] transition-all duration-500"
                            :style="{ width: Math.min(100, (balance.available / minWithdrawal) * 100) + '%' }"
                        ></div>
                    </div>
                    <p class="text-xs text-[var(--color-muted)] mt-2">
                        Còn <b class="text-[var(--color-ink)] num">{{ vnd(minWithdrawal - balance.available) }}</b> nữa là đủ mức rút tối thiểu{{ hasAnyAccount ? '' : ' — nhớ khai sẵn ví nhận tiền ở mục Thông tin cá nhân' }}.
                    </p>
                </div>

                <p v-if="!canWithdraw" class="text-xs text-[var(--color-muted)] mt-3">
                    Cần số dư tối thiểu {{ vnd(minWithdrawal) }}, ít nhất một ví nhận tiền{{ balance.hasRealCashback ? '' : ' và một đơn hàng đã được hoàn tiền' }} để rút.
                </p>
                <!-- Nói trước việc duyệt tay. Đây là câu đứng giữa khách và cơn giận "gửi lệnh rút
                     cả tiếng rồi mà chưa thấy tiền đâu" — WithdrawalController tạo lệnh ở trạng
                     thái pending và admin chuyển khoản thủ công, không có tự động ở bất kỳ khâu nào. -->
                <p class="text-xs text-[var(--color-muted)] mt-2">
                    Gửi yêu cầu xong, bên mình duyệt rồi chuyển tay về ví của bạn — không phải tự động, nên bạn chờ một chút nhé.
                </p>
            </div>

            <!-- Điểm danh nhận quà. Đứng ngay dưới số dư vì đây là thứ DUY NHẤT trên trang này
                 khách bấm một cái là con số phía trên nhúch lên ngay; mọi khối còn lại (rút tiền, hạng,
                 lịch sử) đều là chờ hoặc là việc phải làm ở chỗ khác.
                 reload='balance': số dư nằm ngay trên thẻ nên phải lấy lại cùng lượt, không thì khách
                 vừa nhận quà mà con số đứng yên — xem Components/DailyCheckIn.vue. -->
            <DailyCheckIn v-if="dailyCheckIn" :state="dailyCheckIn" :reload="['balance']" />

            <!-- Hạng thành viên. Đặt ngay dưới số dư vì nó nói về cùng một thứ — tiền hoàn —
                 và là nơi trang /hoan-tien đã hứa là "theo dõi được tiến độ nâng hạng". -->
            <MembershipTierProgress v-if="tier" :tier="tier" />

            <!-- Ví rỗng và chưa từng rút: chỉ đường thay vì để khách nhìn số 0 rồi thoát. Đây là
                 điểm rơi lớn nhất của nhóm khách đã chịu đăng ký — họ vào xem ví ngay sau khi đăng
                 ký, thấy ₫0 và nút Rút tiền xám ngắt. -->
            <!-- Dựa vào hasRealCashback chứ không phải earned: có thưởng người mới thì earned > 0
                 nhưng khách vẫn chưa làm gì cả — vẫn cần được chỉ đường. -->
            <div v-if="!balance.hasRealCashback && !withdrawals?.length" class="card p-6">
                <h2 class="font-bold text-[var(--color-ink)] mb-1">
                    {{ balance.earned > 0 ? 'Có quà chào mừng rồi — mua đơn đầu tiên để rút được' : 'Ví chưa có gì — bắt đầu thế nào?' }}
                </h2>
                <p class="text-xs text-[var(--color-muted)] mb-4">Ba bước, làm một lần rồi thôi.</p>
                <!-- Ba số thứ tự trước đây tô cam. Trên cùng một thẻ đã có nút cam ở cuối, nên ba
                     chấm cam phía trên chỉ chia bớt sự chú ý của đúng cái nút cần bấm. -->
                <ol class="space-y-3 text-sm text-[var(--color-ink)]">
                    <li class="flex gap-3">
                        <span class="flex-none w-6 h-6 rounded-full bg-[var(--color-info-soft)] text-[var(--color-info)] text-xs font-extrabold flex items-center justify-center">1</span>
                        <span>Khai sẵn ví MoMo hoặc ZaloPay ở mục <Link href="/profile/thong-tin" class="focus-ring rounded underline font-semibold">Thông tin cá nhân</Link> — làm sớm cho xong, đừng đợi đủ tiền mới khai.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex-none w-6 h-6 rounded-full bg-[var(--color-info-soft)] text-[var(--color-info)] text-xs font-extrabold flex items-center justify-center">2</span>
                        <span>Về trang chủ, dán link sản phẩm Shopee và bấm mua <b>trong lúc đang đăng nhập</b>.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex-none w-6 h-6 rounded-full bg-[var(--color-info-soft)] text-[var(--color-info)] text-xs font-extrabold flex items-center justify-center">3</span>
                        <span>Đợi đơn sang trạng thái Hoàn thành bên Shopee. Qua kỳ đối soát gần nhất là tiền hiện ở đây.</span>
                    </li>
                </ol>
                <Link href="/" class="btn-fire focus-ring inline-flex items-center mt-5 px-5 rounded-xl text-sm no-underline">
                    Về trang chủ lấy mã →
                </Link>
            </div>

            <!-- Lịch sử rút -->
            <div class="card overflow-hidden">
                <h2 class="font-bold text-[var(--color-ink)] p-6 pb-3">Lịch sử rút tiền</h2>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <!-- Nền peach-soft cũ là một vệt cam nữa trên trang; một đường kẻ là đủ để tách
                         hàng tiêu đề, và nó sống được ở cả hai chế độ. -->
                    <thead class="border-y border-[var(--color-line)]">
                        <tr class="text-left text-xs text-[var(--color-muted)]">
                            <th class="px-6 py-3 font-semibold">Ngày</th>
                            <th class="px-6 py-3 font-semibold">Ví</th>
                            <th class="px-6 py-3 font-semibold">Số tiền</th>
                            <th class="px-6 py-3 font-semibold">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        <tr v-for="w in withdrawals" :key="w.id">
                            <td class="px-6 py-4 text-[var(--color-muted)] text-xs whitespace-nowrap">{{ w.created_at }}</td>
                            <td class="px-6 py-4 text-[var(--color-ink)]">
                                {{ providerLabels[w.provider] }} · <span class="num">{{ w.account_number }}</span>
                            </td>
                            <td class="px-6 py-4 font-semibold num text-[var(--color-money)] whitespace-nowrap">{{ vnd(w.amount) }}</td>
                            <td class="px-6 py-4">
                                <StatusBadge :status="w.status" :label="statusLabels[w.status]" />
                                <p v-if="w.status === 'rejected' && w.admin_note" class="text-xs text-[var(--color-danger)] mt-1">{{ w.admin_note }}</p>
                            </td>
                        </tr>
                        <tr v-if="!withdrawals?.length">
                            <td colspan="4" class="px-6 py-10 text-center text-[var(--color-muted)]">
                                Chưa rút lần nào. Đủ {{ vnd(minWithdrawal) }} là nút Rút tiền ở trên tự sáng lên.
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- Modal rút tiền.
             z-[70] cho lớp che và z-[80] cho hộp, khớp thang z của AccountDrawer. Trước đây cả
             cụm ở z-50 — ĐÚNG BẰNG BottomNav, mà BottomNav là thẻ anh em đứng SAU <main> nên
             cùng z thì nó được vẽ đè lên: khách mở form rút tiền trên điện thoại thì thanh điều
             hướng nằm chồng lên đáy hộp, che mất đúng hai nút Gửi yêu cầu / Huỷ. -->
        <div v-if="showWithdraw" class="fixed inset-0 bg-black/50 z-[70] flex items-center justify-center p-4">
            <div class="card relative z-[80] p-6 w-full max-w-md">
                <h2 class="font-extrabold text-[var(--color-ink)] mb-1">Yêu cầu rút tiền</h2>
                <p class="text-xs text-[var(--color-muted)] mb-5">Số dư khả dụng: <span class="num font-bold text-[var(--color-money)]">{{ vnd(balance.available) }}</span></p>
                <form @submit.prevent="submitWithdraw" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Chọn ví nhận tiền</label>
                        <select v-model="withdrawForm.provider"
                            class="focus-ring w-full min-h-[44px] border border-[var(--color-line)] rounded-xl px-3 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:border-[var(--color-accent)]">
                            <option v-for="p in availableProviders" :key="p.key" :value="p.key">
                                {{ p.label }} · {{ payoutAccounts[p.key].account_number }}
                            </option>
                        </select>
                        <p v-if="withdrawForm.errors.provider" class="text-xs text-[var(--color-danger)] mt-1">{{ withdrawForm.errors.provider }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Số tiền (₫)</label>
                        <input v-model="withdrawForm.amount" type="number" :min="minWithdrawal" :max="balance.available" step="1000"
                            class="focus-ring num w-full min-h-[44px] border border-[var(--color-line)] rounded-xl px-3 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:border-[var(--color-accent)]" />
                        <p class="text-xs text-[var(--color-muted)] mt-1">Tối thiểu {{ vnd(minWithdrawal) }}</p>
                        <p v-if="withdrawForm.errors.amount" class="text-xs text-[var(--color-danger)] mt-1">{{ withdrawForm.errors.amount }}</p>
                    </div>
                    <div class="flex gap-3 pt-2">
                        <button type="submit" :disabled="withdrawForm.processing"
                            class="btn-fire focus-ring flex-1 inline-flex items-center justify-center rounded-xl text-sm">
                            Gửi yêu cầu
                        </button>
                        <!-- Hover đổi NỀN chứ không hạ opacity cả nút: opacity kéo tụt luôn chữ
                             (và Safari trên iOS giữ trạng thái :hover sau khi chạm cho tới lúc
                             chạm chỗ khác, nên nút sẽ đứng mờ giữa hộp). --color-line có sẵn bản
                             sáng lẫn tối nên chỉ cần một lớp. -->
                        <button type="button" @click="showWithdraw = false"
                            class="panel focus-ring inline-flex items-center justify-center min-h-[44px] px-6 border border-[var(--color-line)] text-[var(--color-ink)] font-semibold text-sm hover:bg-[var(--color-line)] transition">
                            Hủy
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AccountLayout>
</template>
