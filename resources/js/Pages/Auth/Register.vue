<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3'

const form = useForm({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
})

function submit() {
    form.post('/register')
}
</script>

<template>
    <Head title="Đăng ký" />
    <div class="min-h-screen bg-[--color-bg] flex items-center justify-center px-4">
        <div class="w-full max-w-md bg-white rounded-2xl shadow-lg p-8">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-extrabold text-[--color-ink]">Tạo tài khoản</h1>
                <p class="text-[--color-muted] text-sm mt-1">Miễn phí, không quảng cáo</p>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-[--color-ink] mb-1">Họ và tên</label>
                    <input v-model="form.name" type="text" required
                        class="w-full border border-[--color-line] rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-[--color-accent] transition"
                        placeholder="Nguyễn Văn A" />
                    <p v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[--color-ink] mb-1">Email</label>
                    <input v-model="form.email" type="email" required
                        class="w-full border border-[--color-line] rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-[--color-accent] transition"
                        placeholder="you@example.com" />
                    <p v-if="form.errors.email" class="text-red-500 text-xs mt-1">{{ form.errors.email }}</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[--color-ink] mb-1">Số điện thoại <span class="text-[--color-muted] font-normal">(tùy chọn)</span></label>
                    <input v-model="form.phone" type="tel"
                        class="w-full border border-[--color-line] rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-[--color-accent] transition"
                        placeholder="0901234567" />
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[--color-ink] mb-1">Mật khẩu</label>
                    <input v-model="form.password" type="password" required
                        class="w-full border border-[--color-line] rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-[--color-accent] transition"
                        placeholder="Tối thiểu 8 ký tự" />
                    <p v-if="form.errors.password" class="text-red-500 text-xs mt-1">{{ form.errors.password }}</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[--color-ink] mb-1">Xác nhận mật khẩu</label>
                    <input v-model="form.password_confirmation" type="password" required
                        class="w-full border border-[--color-line] rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-[--color-accent] transition"
                        placeholder="Nhập lại mật khẩu" />
                </div>
                <button type="submit" :disabled="form.processing"
                    class="w-full bg-[--color-accent] hover:bg-[--color-accent-deep] text-white font-semibold py-3 rounded-xl transition disabled:opacity-60">
                    {{ form.processing ? 'Đang tạo tài khoản...' : 'Đăng ký' }}
                </button>
            </form>

            <p class="text-center text-sm text-[--color-muted] mt-6">
                Đã có tài khoản?
                <Link href="/login" class="text-[--color-accent] font-semibold hover:underline">Đăng nhập</Link>
            </p>
        </div>
    </div>
</template>
