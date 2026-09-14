<script setup>
import { Head, useForm } from '@inertiajs/vue3'
import AccountLayout from '@/Layouts/AccountLayout.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const props = defineProps({
    hasPassword: Boolean,
})

// hasPassword=false với khách đăng nhập Google chưa từng đặt mật khẩu — form ẩn ô "mật khẩu
// cũ" cho nhóm này (xem User::hasUsablePassword() / ProfileController::updatePassword).
const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
})

function savePassword() {
    passwordForm.post('/profile/password', {
        preserveScroll: true,
        // Message khác nhau giữa "đặt lần đầu" và "đổi" tuỳ hasPassword — lấy từ flash server
        // trả về (ProfileController::updatePassword) thay vì đoán ở đây.
        onSuccess: (page) => { passwordForm.reset(); toast.success(page.props.flash?.success || 'Đã lưu mật khẩu.') },
    })
}
</script>

<template>
    <Head title="Mật khẩu & Bảo mật" />
    <AccountLayout>
        <div class="space-y-6">
            <h1 class="text-2xl font-extrabold text-[var(--color-ink)]">Mật khẩu & Bảo mật</h1>

            <div class="card-glass rounded-2xl p-6">
                <h2 class="font-bold text-[var(--color-ink)] mb-1">Đổi mật khẩu</h2>
                <p class="text-xs text-[var(--color-muted)] mb-4">
                    {{ hasPassword ? 'Cập nhật mật khẩu để bảo vệ tài khoản.' : 'Tài khoản đang đăng nhập bằng Google — đặt thêm mật khẩu để đăng nhập được cả bằng email.' }}
                </p>
                <form @submit.prevent="savePassword" class="space-y-4">
                    <div v-if="hasPassword">
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Mật khẩu hiện tại</label>
                        <input v-model="passwordForm.current_password" type="password" required autocomplete="current-password"
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                        <p v-if="passwordForm.errors.current_password" class="text-xs text-red-500 mt-1">{{ passwordForm.errors.current_password }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Mật khẩu mới</label>
                        <input v-model="passwordForm.password" type="password" required autocomplete="new-password"
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                        <p class="text-xs text-[var(--color-muted)] mt-1">Từ 8 ký tự, gồm chữ thường, chữ hoa và số.</p>
                        <p v-if="passwordForm.errors.password" class="text-xs text-red-500 mt-1">{{ passwordForm.errors.password }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Xác nhận mật khẩu mới</label>
                        <input v-model="passwordForm.password_confirmation" type="password" required autocomplete="new-password"
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                    </div>
                    <button type="submit" :disabled="passwordForm.processing"
                        class="btn-fire text-sm px-5 py-2.5 rounded-xl">
                        {{ hasPassword ? 'Đổi mật khẩu' : 'Đặt mật khẩu' }}
                    </button>
                </form>
            </div>
        </div>
    </AccountLayout>
</template>
