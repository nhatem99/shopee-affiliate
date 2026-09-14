<script setup>
import { Head, useForm } from '@inertiajs/vue3'
import AccountLayout from '@/Layouts/AccountLayout.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const props = defineProps({
    profile: Object,
    payoutAccounts: Object,
})

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
</script>

<template>
    <Head title="Thông tin cá nhân" />
    <AccountLayout>
        <div class="space-y-6">
            <h1 class="text-2xl font-extrabold text-[var(--color-ink)]">Thông tin cá nhân</h1>

            <!-- Thông tin cá nhân -->
            <div class="card-glass rounded-2xl p-6">
                <h2 class="font-bold text-[var(--color-ink)] mb-4">Hồ sơ</h2>
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
        </div>
    </AccountLayout>
</template>
