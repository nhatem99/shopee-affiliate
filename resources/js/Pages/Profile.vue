<script setup>
import { ref, computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const props = defineProps({
    profile: Object,
    payoutAccounts: Object,
    balance: Object,
    withdrawals: Array,
    minWithdrawal: Number,
})

function vnd(n) {
    return '₫' + Number(n || 0).toLocaleString('vi-VN')
}

// --- Thông tin cá nhân ---
const profileForm = useForm({
    name: props.profile.name,
    phone: props.profile.phone || '',
})

function saveProfile() {
    profileForm.patch('/profile', {
        preserveScroll: true,
        onSuccess: () => toast.success('Đã cập nhật thông tin'),
    })
}

// --- Ví nhận tiền ---
const providers = [
    { key: 'momo', label: 'Ví MoMo', color: 'text-pink-600' },
    { key: 'zalopay', label: 'Ví ZaloPay', color: 'text-blue-600' },
]

const payoutForms = {
    momo: useForm({
        provider: 'momo',
        account_number: props.payoutAccounts?.momo?.account_number || '',
        account_name: props.payoutAccounts?.momo?.account_name || '',
    }),
    zalopay: useForm({
        provider: 'zalopay',
        account_number: props.payoutAccounts?.zalopay?.account_number || '',
        account_name: props.payoutAccounts?.zalopay?.account_name || '',
    }),
}

function savePayout(key) {
    payoutForms[key].post('/profile/payout', {
        preserveScroll: true,
        onSuccess: () => toast.success('Đã lưu thông tin ví'),
    })
}

const hasAnyAccount = computed(() =>
    !!(props.payoutAccounts?.momo || props.payoutAccounts?.zalopay)
)

// --- Rút tiền ---
const showWithdraw = ref(false)
const canWithdraw = computed(() => props.balance.available >= props.minWithdrawal && hasAnyAccount.value)

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
    <AppLayout>
        <div class="max-w-3xl mx-auto px-4 py-8 space-y-6">
            <h1 class="text-2xl font-extrabold text-[var(--color-ink)]">Tài khoản của tôi</h1>

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
                     rỗng thì thành lời trách "bạn còn thiếu 50.000đ" ngay khi khách vừa đăng ký.
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
                        Còn <b class="text-[var(--color-ink)]">{{ vnd(minWithdrawal - balance.available) }}</b> nữa là đủ mức rút tối thiểu{{ hasAnyAccount ? '' : ' — nhớ khai sẵn ví nhận tiền bên dưới' }}.
                    </p>
                </div>

                <p v-if="!canWithdraw" class="text-xs text-[var(--color-muted)] mt-3">
                    Cần số dư tối thiểu {{ vnd(minWithdrawal) }} và ít nhất một ví nhận tiền để rút.
                </p>
                <!-- Nói trước việc duyệt tay. Đây là câu đứng giữa khách và cơn giận "gửi lệnh rút
                     cả tiếng rồi mà chưa thấy tiền đâu" — WithdrawalController tạo lệnh ở trạng
                     thái pending và admin chuyển khoản thủ công, không có tự động ở bất kỳ khâu nào. -->
                <p class="text-xs text-[var(--color-muted)] mt-2">
                    Gửi yêu cầu xong, bên mình duyệt rồi chuyển tay về ví của bạn — không phải tự động, nên bạn chờ một chút nhé.
                </p>
            </div>

            <!-- Ví rỗng và chưa từng rút: chỉ đường thay vì để khách nhìn số 0 rồi thoát. Đây là
                 điểm rơi lớn nhất của nhóm khách đã chịu đăng ký — họ vào xem ví ngay sau khi đăng
                 ký, thấy ₫0 và nút Rút tiền xám ngắt. -->
            <div v-if="balance.earned <= 0 && !withdrawals?.length" class="card-glass rounded-2xl p-6">
                <h2 class="font-bold text-[var(--color-ink)] mb-1">Ví chưa có gì — bắt đầu thế nào?</h2>
                <p class="text-xs text-[var(--color-muted)] mb-4">Ba bước, làm một lần rồi thôi.</p>
                <ol class="space-y-3 text-sm text-[var(--color-ink)]">
                    <li class="flex gap-3">
                        <span class="flex-none w-6 h-6 rounded-full bg-[var(--color-peach-soft)] text-[var(--color-accent)] text-xs font-extrabold flex items-center justify-center">1</span>
                        <span>Khai sẵn ví MoMo hoặc ZaloPay ở ngay bên dưới — làm sớm cho xong, đừng đợi đủ tiền mới khai.</span>
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

            <!-- Thông tin cá nhân -->
            <div class="card-glass rounded-2xl p-6">
                <h2 class="font-bold text-[var(--color-ink)] mb-4">Thông tin cá nhân</h2>
                <form @submit.prevent="saveProfile" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Họ tên</label>
                        <input v-model="profileForm.name" type="text" required
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                        <p v-if="profileForm.errors.name" class="text-xs text-red-500 mt-1">{{ profileForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Email</label>
                        <input :value="profile.email" type="email" disabled
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-peach-soft)] text-[var(--color-muted)] cursor-not-allowed" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Số điện thoại</label>
                        <input v-model="profileForm.phone" type="tel"
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                        <p v-if="profileForm.errors.phone" class="text-xs text-red-500 mt-1">{{ profileForm.errors.phone }}</p>
                    </div>
                    <button type="submit" :disabled="profileForm.processing"
                        class="btn-fire text-sm px-5 py-2.5 rounded-xl">
                        Lưu thông tin
                    </button>
                </form>
            </div>

            <!-- Ví nhận tiền -->
            <div class="card-glass rounded-2xl p-6">
                <h2 class="font-bold text-[var(--color-ink)] mb-1">Ví nhận tiền</h2>
                <p class="text-xs text-[var(--color-muted)] mb-4">Thiết lập ví MoMo / ZaloPay để nhận hoa hồng khi rút.</p>
                <div class="grid md:grid-cols-2 gap-4">
                    <form v-for="p in providers" :key="p.key" @submit.prevent="savePayout(p.key)"
                        class="border border-[var(--color-line)] rounded-xl p-4 space-y-3">
                        <p class="font-bold text-sm" :class="p.color">{{ p.label }}</p>
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Số điện thoại ví</label>
                            <input v-model="payoutForms[p.key].account_number" type="tel" placeholder="VD: 0901234567"
                                class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                            <p v-if="payoutForms[p.key].errors.account_number" class="text-xs text-red-500 mt-1">{{ payoutForms[p.key].errors.account_number }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Tên chủ ví</label>
                            <input v-model="payoutForms[p.key].account_name" type="text" placeholder="VD: NGUYEN VAN A"
                                class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                            <p v-if="payoutForms[p.key].errors.account_name" class="text-xs text-red-500 mt-1">{{ payoutForms[p.key].errors.account_name }}</p>
                        </div>
                        <button type="submit" :disabled="payoutForms[p.key].processing"
                            class="w-full bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] text-[var(--color-ink)] text-sm font-semibold py-2 rounded-xl transition disabled:opacity-60">
                            Lưu {{ p.label }}
                        </button>
                    </form>
                </div>
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
    </AppLayout>
</template>
