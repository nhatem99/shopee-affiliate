<script setup>
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useAdminStore } from '@/Stores/useAdminStore'
import { useToast } from '@/composables/useToast'

const admin = useAdminStore()
const toast = useToast()

defineProps({
    withdrawals: Object,
    filters: Object,
})

function vnd(n) {
    return '₫' + Number(n || 0).toLocaleString('vi-VN')
}

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

function filter(status) {
    router.get('/admin/withdrawals', { status }, { preserveState: true })
}

function approve(id) {
    admin.approveWithdrawal(id, {
        onSuccess: () => toast.success('Đã chấp nhận yêu cầu'),
        onError: () => toast.error('Không thể cập nhật, vui lòng thử lại'),
    })
}

// Modal "Đã chuyển"
const completing = ref(null)
const transactionRef = ref('')

function openComplete(w) {
    completing.value = w
    transactionRef.value = ''
}

function confirmComplete() {
    admin.completeWithdrawal(completing.value.id, transactionRef.value, {
        onSuccess: () => { completing.value = null; toast.success('Đã đánh dấu chuyển tiền') },
        onError: () => toast.error('Không thể cập nhật, vui lòng thử lại'),
    })
}

// Modal "Từ chối"
const rejecting = ref(null)
const rejectNote = ref('')

function openReject(w) {
    rejecting.value = w
    rejectNote.value = ''
}

function confirmReject() {
    admin.rejectWithdrawal(rejecting.value.id, rejectNote.value, {
        onSuccess: () => { rejecting.value = null; toast.success('Đã từ chối yêu cầu') },
        onError: () => toast.error('Không thể cập nhật, vui lòng thử lại'),
    })
}
</script>

<template>
    <Head title="Admin — Rút tiền" />
    <AdminLayout>
        <template #title>Quản lý rút tiền</template>

        <!-- Filters -->
        <div class="flex gap-2 mb-6 flex-wrap">
            <button @click="filter('')" :class="!filters?.status ? 'bg-[var(--color-accent)] text-white' : 'bg-[var(--color-surface)] text-[var(--color-ink)] border border-[var(--color-line)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)]'"
                class="px-4 py-2 rounded-xl text-sm font-semibold transition">Tất cả</button>
            <button v-for="s in ['pending','approved','completed','rejected']" :key="s" @click="filter(s)"
                :class="filters?.status === s ? 'bg-[var(--color-accent)] text-white' : 'bg-[var(--color-surface)] text-[var(--color-ink)] border border-[var(--color-line)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)]'"
                class="px-4 py-2 rounded-xl text-sm font-semibold transition">
                {{ statusLabels[s] }}
            </button>
        </div>

        <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-peach-soft)]">
                    <tr class="text-left text-xs text-[var(--color-muted)]">
                        <th class="px-6 py-3 font-semibold">ID</th>
                        <th class="px-6 py-3 font-semibold">Người dùng</th>
                        <th class="px-6 py-3 font-semibold">Ví nhận tiền</th>
                        <th class="px-6 py-3 font-semibold">Số tiền</th>
                        <th class="px-6 py-3 font-semibold">Trạng thái</th>
                        <th class="px-6 py-3 font-semibold">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-line)]">
                    <tr v-for="w in withdrawals?.data" :key="w.id">
                        <td class="px-6 py-4 text-[var(--color-muted)]">#{{ w.id }}</td>
                        <td class="px-6 py-4 font-medium text-[var(--color-ink)]">{{ w.user }}</td>
                        <td class="px-6 py-4 text-[var(--color-ink)]/70">
                            <span class="font-semibold">{{ providerLabels[w.provider] }}</span><br>
                            <span class="text-xs">{{ w.account_number }} · {{ w.account_name }}</span>
                        </td>
                        <td class="px-6 py-4 font-semibold text-[var(--color-brand-green)]">{{ vnd(w.amount) }}</td>
                        <td class="px-6 py-4">
                            <span :class="statusColors[w.status]" class="px-2 py-1 rounded-full text-xs font-semibold">
                                {{ statusLabels[w.status] }}
                            </span>
                            <p v-if="w.status === 'completed' && w.transaction_ref" class="text-xs text-[var(--color-muted)] mt-1">Mã GD: {{ w.transaction_ref }}</p>
                            <p v-if="w.status === 'rejected' && w.admin_note" class="text-xs text-red-500 mt-1">{{ w.admin_note }}</p>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3" v-if="w.status === 'pending' || w.status === 'approved'">
                                <button v-if="w.status === 'pending'" @click="approve(w.id)"
                                    :disabled="admin.loadingWithdrawals.includes(w.id)"
                                    class="text-xs font-semibold text-[var(--color-brand-green)] hover:underline disabled:opacity-50 transition">Chấp nhận</button>
                                <button v-if="w.status === 'approved'" @click="openComplete(w)"
                                    :disabled="admin.loadingWithdrawals.includes(w.id)"
                                    class="text-xs font-semibold text-blue-600 hover:underline disabled:opacity-50 transition">Đã chuyển</button>
                                <button @click="openReject(w)"
                                    :disabled="admin.loadingWithdrawals.includes(w.id)"
                                    class="text-xs font-semibold text-red-500 hover:underline disabled:opacity-50 transition">Từ chối</button>
                            </div>
                            <span v-else class="text-xs text-[var(--color-muted)]">—</span>
                        </td>
                    </tr>
                    <tr v-if="!withdrawals?.data?.length">
                        <td colspan="6" class="px-6 py-10 text-center text-[var(--color-muted)]">Không có yêu cầu rút tiền.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Modal đánh dấu đã chuyển -->
        <div v-if="completing" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="bg-[var(--color-surface)] rounded-2xl p-6 w-full max-w-md">
                <h2 class="font-extrabold text-[var(--color-ink)] mb-1">Đánh dấu đã chuyển tiền</h2>
                <p class="text-xs text-[var(--color-muted)] mb-5">
                    {{ providerLabels[completing.provider] }} · {{ completing.account_number }} · {{ vnd(completing.amount) }}
                </p>
                <form @submit.prevent="confirmComplete" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Mã giao dịch *</label>
                        <input v-model="transactionRef" type="text" required placeholder="Mã GD MoMo/ZaloPay"
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                    </div>
                    <div class="flex gap-3 pt-2">
                        <button type="submit" :disabled="admin.loadingWithdrawals.includes(completing.id)"
                            class="flex-1 bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold py-2.5 rounded-xl text-sm transition disabled:opacity-60">
                            Xác nhận
                        </button>
                        <button type="button" @click="completing = null"
                            class="px-6 bg-[var(--color-peach-soft)] text-[var(--color-ink)] font-semibold py-2.5 rounded-xl text-sm hover:bg-[var(--color-peach)] transition">
                            Hủy
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal từ chối -->
        <div v-if="rejecting" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="bg-[var(--color-surface)] rounded-2xl p-6 w-full max-w-md">
                <h2 class="font-extrabold text-[var(--color-ink)] mb-1">Từ chối yêu cầu rút tiền</h2>
                <p class="text-xs text-[var(--color-muted)] mb-5">
                    {{ rejecting.user }} · {{ vnd(rejecting.amount) }}
                </p>
                <form @submit.prevent="confirmReject" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Lý do từ chối</label>
                        <input v-model="rejectNote" type="text" placeholder="VD: Sai thông tin ví"
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                    </div>
                    <div class="flex gap-3 pt-2">
                        <button type="submit" :disabled="admin.loadingWithdrawals.includes(rejecting.id)"
                            class="flex-1 bg-red-500 hover:bg-red-600 text-white font-semibold py-2.5 rounded-xl text-sm transition disabled:opacity-60">
                            Từ chối
                        </button>
                        <button type="button" @click="rejecting = null"
                            class="px-6 bg-[var(--color-peach-soft)] text-[var(--color-ink)] font-semibold py-2.5 rounded-xl text-sm hover:bg-[var(--color-peach)] transition">
                            Hủy
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
