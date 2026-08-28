<script setup>
import { Head, useForm } from '@inertiajs/vue3'

const form = useForm({
    email: '',
    password: '',
    remember: false,
})

function submit() {
    form.post('/admin/login')
}
</script>

<template>
    <Head title="Đăng nhập quản trị" />
    <div class="min-h-screen bg-[var(--color-side)] flex items-center justify-center px-4">
        <div class="w-full max-w-sm bg-[var(--color-surface)] rounded-2xl shadow-lg p-8">
            <div class="text-center mb-8">
                <span class="inline-flex w-10 h-10 rounded-lg bg-gradient-to-br from-[var(--color-accent)] to-[var(--color-accent-deep)] items-center justify-center text-lg font-extrabold text-white mb-3">%</span>
                <h1 class="text-xl font-extrabold text-[var(--color-ink)]">Đăng nhập quản trị</h1>
                <p class="text-[var(--color-muted)] text-sm mt-1">Chỉ dành cho tài khoản admin</p>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Email</label>
                    <input v-model="form.email" type="email" required autofocus
                        class="w-full border border-[var(--color-line)] rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-[var(--color-accent)] transition"
                        placeholder="admin@example.com" />
                    <p v-if="form.errors.email" class="text-red-500 text-xs mt-1">{{ form.errors.email }}</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Mật khẩu</label>
                    <input v-model="form.password" type="password" required
                        class="w-full border border-[var(--color-line)] rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-[var(--color-accent)] transition"
                        placeholder="••••••••" />
                </div>
                <label class="flex items-center gap-2 text-sm text-[var(--color-muted)]">
                    <input v-model="form.remember" type="checkbox" class="rounded border-[var(--color-line)]" />
                    Ghi nhớ đăng nhập
                </label>
                <button type="submit" :disabled="form.processing"
                    class="btn-fire w-full py-3 rounded-xl">
                    {{ form.processing ? 'Đang đăng nhập...' : 'Đăng nhập' }}
                </button>
            </form>
        </div>
    </div>
</template>
