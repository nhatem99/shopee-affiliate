<script setup>
import { ref, computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AccountLayout from '@/Layouts/AccountLayout.vue'
import MembershipTierProgress from '@/Components/MembershipTierProgress.vue'
import UserAvatar from '@/Components/UserAvatar.vue'
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
const statusColors = {
    pending: 'bg-yellow-100 text-yellow-700',
    approved: 'bg-green-100 text-green-700',
    completed: 'bg-blue-100 text-blue-700',
    rejected: 'bg-red-100 text-red-600',
}
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
            <div class="card-glass rounded-2xl p-5 flex items-center gap-4">
                <UserAvatar
                    :src="profile.avatar"
                    :name="profile.name || profile.email"
                    class="flex-none w-14 h-14 text-xl"
                />
                <div class="min-w-0">
                    <div class="flex items-center flex-wrap gap-x-2 gap-y-1">
                        <p class="font-bold text-[var(--color-ink)] truncate">{{ profile.name || profile.email }}</p>
                        <span v-if="!balance.hasRealCashback" class="flex-none text-[10px] font-bold px-2 py-0.5 rounded-full bg-[var(--color-peach-soft)] text-[var(--color-accent)]">🔥 LÍNH MỚI</span>
                    </div>
                    <p class="text-xs text-[var(--color-muted)] truncate">{{ profile.email }}</p>
                    <p class="text-xs text-[var(--color-muted)]">Thành viên từ: {{ profile.member_since }}</p>
                </div>
            </div>

            <!-- Số dư -->
            <div class="card-glass rounded-2xl p-6">
                <div class="flex items-end justify-between flex-wrap gap-4">
                    <div>
                        <!-- Giữ nhãn "khả dụng" chứ không đổi thành "rút được": con số này là hiệu
                             của hoa hồng đã duyệt trừ phần đang giữ cho các lệnh rút, nên có thể
                             bằng 0 hoặc âm, và kể cả khi dương vẫn chưa rút được nếu chưa khai ví. -->
                        <p class="text-sm text-[var(--color-muted)]">Số dư khả dụng</p>
                        <p class="text-3xl font-extrabold text-[var(--color-brand-green)]">{{ vnd(balance.available) }}</p>
                        <p class="text-xs text-[var(--color-muted)] mt-1">
                            Đã duyệt: {{ vnd(balance.earned) }} · Đang giữ: {{ vnd(balance.reserved) }}
                        </p>
                        <!-- Con số ở trên là tổng; đây là đường tới phần giải thích nó được cộng
                             từ những đơn nào. -->
                        <div class="flex flex-wrap gap-x-4 gap-y-1 mt-1">
                            <Link href="/don-hang" class="text-xs font-semibold text-[var(--color-accent)] hover:underline">
                                Xem từng đơn và tiền hoàn →
                            </Link>
                            <Link href="/vi/lich-su" class="text-xs font-semibold text-[var(--color-accent)] hover:underline">
                                Lịch sử số dư ví →
                            </Link>
                        </div>
                    </div>
                    <button
                        @click="openWithdraw"
                        :disabled="!canWithdraw"
                        class="btn-fire text-sm px-5 py-2.5 rounded-xl"
                    >
                        Rút tiền
                    </button>
                </div>

                <!-- Thanh tiến độ tới mốc rút. Chỉ hiện khi đã có tiền thật trong ví: hiện với ví
                     rỗng thì thành lời trách "bạn còn thiếu 10.000đ" ngay khi khách vừa đăng ký.
                     Điều kiện > 0 còn chặn luôn trường hợp số âm — availableBalance() là hiệu của
                     hoa hồng đã duyệt trừ phần đang giữ nên hoàn toàn có thể âm (xem User.php). -->
                <div v-if="balance.available > 0 && balance.available < minWithdrawal" class="mt-4">
                    <div class="h-2 rounded-full bg-[var(--color-peach-soft)] overflow-hidden">
                        <div
                            class="h-full rounded-full bg-[var(--color-brand-green)] transition-all duration-500"
                            :style="{ width: Math.min(100, (balance.available / minWithdrawal) * 100) + '%' }"
                        ></div>
                    </div>
                    <p class="text-xs text-[var(--color-muted)] mt-2">
                        Còn <b class="text-[var(--color-ink)]">{{ vnd(minWithdrawal - balance.available) }}</b> nữa là đủ mức rút tối thiểu{{ hasAnyAccount ? '' : ' — nhớ khai sẵn ví nhận tiền ở mục Thông tin cá nhân' }}.
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

            <!-- Hạng thành viên. Đặt ngay dưới số dư vì nó nói về cùng một thứ — tiền hoàn —
                 và là nơi trang /hoan-tien đã hứa là "theo dõi được tiến độ nâng hạng". -->
            <MembershipTierProgress v-if="tier" :tier="tier" />

            <!-- Ví rỗng và chưa từng rút: chỉ đường thay vì để khách nhìn số 0 rồi thoát. Đây là
                 điểm rơi lớn nhất của nhóm khách đã chịu đăng ký — họ vào xem ví ngay sau khi đăng
                 ký, thấy ₫0 và nút Rút tiền xám ngắt. -->
            <!-- Dựa vào hasRealCashback chứ không phải earned: có thưởng người mới thì earned > 0
                 nhưng khách vẫn chưa làm gì cả — vẫn cần được chỉ đường. -->
            <div v-if="!balance.hasRealCashback && !withdrawals?.length" class="card-glass rounded-2xl p-6">
                <h2 class="font-bold text-[var(--color-ink)] mb-1">
                    {{ balance.earned > 0 ? 'Có quà chào mừng rồi — mua đơn đầu tiên để rút được' : 'Ví chưa có gì — bắt đầu thế nào?' }}
                </h2>
                <p class="text-xs text-[var(--color-muted)] mb-4">Ba bước, làm một lần rồi thôi.</p>
                <ol class="space-y-3 text-sm text-[var(--color-ink)]">
                    <li class="flex gap-3">
                        <span class="flex-none w-6 h-6 rounded-full bg-[var(--color-peach-soft)] text-[var(--color-accent)] text-xs font-extrabold flex items-center justify-center">1</span>
                        <span>Khai sẵn ví MoMo hoặc ZaloPay ở mục <Link href="/profile/thong-tin" class="underline font-semibold">Thông tin cá nhân</Link> — làm sớm cho xong, đừng đợi đủ tiền mới khai.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex-none w-6 h-6 rounded-full bg-[var(--color-peach-soft)] text-[var(--color-accent)] text-xs font-extrabold flex items-center justify-center">2</span>
                        <span>Về trang chủ, dán link sản phẩm Shopee và bấm mua <b>trong lúc đang đăng nhập</b>.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex-none w-6 h-6 rounded-full bg-[var(--color-peach-soft)] text-[var(--color-accent)] text-xs font-extrabold flex items-center justify-center">3</span>
                        <span>Đợi đơn sang trạng thái Hoàn thành bên Shopee. Qua kỳ đối soát gần nhất là tiền hiện ở đây.</span>
                    </li>
                </ol>
                <Link href="/" class="btn-fire inline-block mt-5 px-5 py-2.5 rounded-xl text-sm no-underline">
                    Về trang chủ lấy mã →
                </Link>
            </div>

            <!-- Lịch sử rút -->
            <div class="card-glass rounded-2xl overflow-hidden">
                <h2 class="font-bold text-[var(--color-ink)] p-6 pb-3">Lịch sử rút tiền</h2>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-[var(--color-peach-soft)]">
                        <tr class="text-left text-xs text-[var(--color-muted)]">
                            <th class="px-6 py-3 font-semibold">Ngày</th>
                            <th class="px-6 py-3 font-semibold">Ví</th>
                            <th class="px-6 py-3 font-semibold">Số tiền</th>
                            <th class="px-6 py-3 font-semibold">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        <tr v-for="w in withdrawals" :key="w.id">
                            <td class="px-6 py-4 text-[var(--color-muted)] text-xs">{{ w.created_at }}</td>
                            <td class="px-6 py-4 text-[var(--color-ink)]">
                                {{ providerLabels[w.provider] }} · {{ w.account_number }}
                            </td>
                            <td class="px-6 py-4 font-semibold text-[var(--color-brand-green)]">{{ vnd(w.amount) }}</td>
                            <td class="px-6 py-4">
                                <span :class="statusColors[w.status]" class="px-2 py-1 rounded-full text-xs font-semibold">
                                    {{ statusLabels[w.status] }}
                                </span>
                                <p v-if="w.status === 'rejected' && w.admin_note" class="text-xs text-red-500 mt-1">{{ w.admin_note }}</p>
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

        <!-- Modal rút tiền -->
        <div v-if="showWithdraw" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="card-glass rounded-2xl p-6 w-full max-w-md">
                <h2 class="font-extrabold text-[var(--color-ink)] mb-1">Yêu cầu rút tiền</h2>
                <p class="text-xs text-[var(--color-muted)] mb-5">Số dư khả dụng: {{ vnd(balance.available) }}</p>
                <form @submit.prevent="submitWithdraw" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Chọn ví nhận tiền</label>
                        <select v-model="withdrawForm.provider"
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]">
                            <option v-for="p in availableProviders" :key="p.key" :value="p.key">
                                {{ p.label }} · {{ payoutAccounts[p.key].account_number }}
                            </option>
                        </select>
                        <p v-if="withdrawForm.errors.provider" class="text-xs text-red-500 mt-1">{{ withdrawForm.errors.provider }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Số tiền (₫)</label>
                        <input v-model="withdrawForm.amount" type="number" :min="minWithdrawal" :max="balance.available" step="1000"
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                        <p class="text-xs text-[var(--color-muted)] mt-1">Tối thiểu {{ vnd(minWithdrawal) }}</p>
                        <p v-if="withdrawForm.errors.amount" class="text-xs text-red-500 mt-1">{{ withdrawForm.errors.amount }}</p>
                    </div>
                    <div class="flex gap-3 pt-2">
                        <button type="submit" :disabled="withdrawForm.processing"
                            class="btn-fire flex-1 py-2.5 rounded-xl text-sm">
                            Gửi yêu cầu
                        </button>
                        <button type="button" @click="showWithdraw = false"
                            class="px-6 bg-[var(--color-peach-soft)] text-[var(--color-ink)] font-semibold py-2.5 rounded-xl text-sm hover:bg-[var(--color-peach)] transition">
                            Hủy
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AccountLayout>
</template>
