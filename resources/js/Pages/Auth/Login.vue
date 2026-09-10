<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3'
import ThemeToggle from '@/Components/ThemeToggle.vue'

const emailForm = useForm({
    email: '',
    password: '',
    remember: false,
})

function submitEmail() {
    emailForm.post('/login')
}
</script>

<template>
    <Head title="Đăng nhập" />
    <div class="min-h-screen bg-[var(--color-bg)] flex items-center justify-center px-4">
        <div class="fixed top-4 right-4 z-50"><ThemeToggle /></div>
        <div class="w-full max-w-md card-glass rounded-2xl shadow-lg p-8">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-extrabold text-[var(--color-ink)]">Đăng nhập</h1>
                <p class="text-[var(--color-muted)] text-sm mt-1">Chào mừng bạn trở lại!</p>
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
                Đăng nhập bằng Google
            </a>

            <div class="flex items-center gap-3 my-6">
                <div class="flex-1 h-px bg-[var(--color-line)]"></div>
                <span class="text-xs font-semibold text-[var(--color-muted)] tracking-wide">HOẶC DÙNG EMAIL</span>
                <div class="flex-1 h-px bg-[var(--color-line)]"></div>
            </div>

            <!-- Email/Password form -->
            <form @submit.prevent="submitEmail" class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Email</label>
                    <input v-model="emailForm.email" type="email" required
                        class="w-full border border-[var(--color-line)] rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-[var(--color-accent)] transition"
                        placeholder="you@example.com" />
                    <p v-if="emailForm.errors.email" class="text-red-500 text-xs mt-1">{{ emailForm.errors.email }}</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Mật khẩu</label>
                    <input v-model="emailForm.password" type="password" required
                        class="w-full border border-[var(--color-line)] rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-[var(--color-accent)] transition"
                        placeholder="••••••••" />
                </div>
                <button type="submit" :disabled="emailForm.processing"
                    class="btn-fire w-full py-3 rounded-xl">
                    {{ emailForm.processing ? 'Đang đăng nhập...' : 'Đăng nhập' }}
                </button>
            </form>

            <p class="text-center text-sm text-[var(--color-muted)] mt-6">
                Chưa có tài khoản?
                <Link href="/register" class="text-[var(--color-accent)] font-semibold hover:underline">Đăng ký</Link>
            </p>
        </div>
    </div>
</template>
