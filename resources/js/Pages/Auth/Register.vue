<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3'
import ThemeToggle from '@/Components/ThemeToggle.vue'

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
    <div class="min-h-screen bg-[var(--color-bg)] flex items-center justify-center px-4">
        <div class="fixed top-4 right-4 z-50"><ThemeToggle /></div>
        <div class="w-full max-w-md card-glass rounded-2xl shadow-lg p-8">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-extrabold text-[var(--color-ink)]">Tạo tài khoản</h1>
                <p class="text-[var(--color-muted)] text-sm mt-1">Miễn phí, không quảng cáo</p>
            </div>

            <!-- Google Sign-in -->
            <a href="/auth/google/redirect"
                class="flex items-center justify-center gap-3 w-full py-3 rounded-xl border border-[var(--color-line)] bg-[var(--color-surface)] text-sm font-semibold text-[var(--color-ink)] hover:bg-[var(--color-peach)] transition">
                <svg class="w-5 h-5" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M23.52 12.27c0-.85-.08-1.67-.22-2.45H12v4.64h6.47c-.28 1.5-1.13 2.78-2.4 3.63v3.02h3.88c2.27-2.09 3.57-5.17 3.57-8.84z"/>
                    <path fill="#34A853" d="M12 24c3.24 0 5.95-1.07 7.94-2.9l-3.88-3.02c-1.07.72-2.45 1.15-4.06 1.15-3.13 0-5.78-2.11-6.73-4.96H1.27v3.12C3.25 21.3 7.28 24 12 24z"/>
                    <path fill="#FBBC05" d="M5.27 14.27a7.2 7.2 0 0 1 0-4.54V6.61H1.27a12 12 0 0 0 0 10.78l4-3.12z"/>
                    <path fill="#EA4335" d="M12 4.77c1.76 0 3.34.6 4.58 1.79l3.44-3.44C17.94 1.19 15.24 0 12 0 7.28 0 3.25 2.7 1.27 6.61l4 3.12C6.22 6.88 8.87 4.77 12 4.77z"/>
                </svg>
                Đăng ký bằng Google
            </a>

            <div class="flex items-center gap-3 my-6">
                <div class="flex-1 h-px bg-[var(--color-line)]"></div>
                <span class="text-xs font-semibold text-[var(--color-muted)] tracking-wide">HOẶC DÙNG EMAIL</span>
                <div class="flex-1 h-px bg-[var(--color-line)]"></div>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Họ và tên</label>
                    <input v-model="form.name" type="text" required
                        class="w-full border border-[var(--color-line)] rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-[var(--color-accent)] transition"
                        placeholder="Nguyễn Văn A" />
                    <p v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Email</label>
                    <input v-model="form.email" type="email" required
                        class="w-full border border-[var(--color-line)] rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-[var(--color-accent)] transition"
                        placeholder="you@example.com" />
                    <p v-if="form.errors.email" class="text-red-500 text-xs mt-1">{{ form.errors.email }}</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Số điện thoại <span class="text-[var(--color-muted)] font-normal">(tùy chọn)</span></label>
                    <input v-model="form.phone" type="tel"
                        class="w-full border border-[var(--color-line)] rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-[var(--color-accent)] transition"
                        placeholder="0901234567" />
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Mật khẩu</label>
                    <input v-model="form.password" type="password" required
                        class="w-full border border-[var(--color-line)] rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-[var(--color-accent)] transition"
                        placeholder="Tối thiểu 8 ký tự" />
                    <p v-if="form.errors.password" class="text-red-500 text-xs mt-1">{{ form.errors.password }}</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Xác nhận mật khẩu</label>
                    <input v-model="form.password_confirmation" type="password" required
                        class="w-full border border-[var(--color-line)] rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-[var(--color-accent)] transition"
                        placeholder="Nhập lại mật khẩu" />
                </div>
                <button type="submit" :disabled="form.processing"
                    class="btn-fire w-full py-3 rounded-xl">
                    {{ form.processing ? 'Đang tạo tài khoản...' : 'Đăng ký' }}
                </button>
            </form>

            <p class="text-center text-sm text-[var(--color-muted)] mt-6">
                Đã có tài khoản?
                <Link href="/login" class="text-[var(--color-accent)] font-semibold hover:underline">Đăng nhập</Link>
            </p>
        </div>
    </div>
</template>
