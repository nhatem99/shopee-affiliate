<script setup>
import { ref } from 'vue'
import { Head, useForm, Link } from '@inertiajs/vue3'
import axios from 'axios'

const tab = ref('email') // 'email' | 'otp'
const otpStep = ref('phone') // 'phone' | 'verify'
const phoneInput = ref('')
const otpSending = ref(false)

const emailForm = useForm({
    email: '',
    password: '',
    remember: false,
})

const otpForm = useForm({
    phone: '',
    otp: '',
})

function submitEmail() {
    emailForm.post('/login')
}

async function sendOtp() {
    otpSending.value = true
    try {
        await axios.post('/auth/otp/send', { phone: phoneInput.value })
        otpForm.phone = phoneInput.value
        otpStep.value = 'verify'
    } catch (e) {
        //
    } finally {
        otpSending.value = false
    }
}

function submitOtp() {
    otpForm.post('/auth/otp/verify')
}
</script>

<template>
    <Head title="Đăng nhập" />
    <div class="min-h-screen bg-[var(--color-bg)] flex items-center justify-center px-4">
        <div class="w-full max-w-md bg-white rounded-2xl shadow-lg p-8">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-extrabold text-[var(--color-ink)]">Đăng nhập</h1>
                <p class="text-[var(--color-muted)] text-sm mt-1">Chào mừng bạn trở lại!</p>
            </div>

            <!-- Tab switcher -->
            <div class="flex bg-[var(--color-peach)] rounded-xl p-1 mb-6">
                <button
                    @click="tab = 'email'"
                    :class="tab === 'email' ? 'bg-white shadow text-[var(--color-ink)]' : 'text-[var(--color-ink)]/60 hover:text-[var(--color-ink)]'"
                    class="flex-1 py-2 rounded-lg text-sm font-semibold transition"
                >Email & Mật khẩu</button>
                <button
                    @click="tab = 'otp'"
                    :class="tab === 'otp' ? 'bg-white shadow text-[var(--color-ink)]' : 'text-[var(--color-ink)]/60 hover:text-[var(--color-ink)]'"
                    class="flex-1 py-2 rounded-lg text-sm font-semibold transition"
                >OTP Zalo/SMS</button>
            </div>

            <!-- Email/Password form -->
            <form v-if="tab === 'email'" @submit.prevent="submitEmail" class="space-y-4">
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
                    class="w-full bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold py-3 rounded-xl transition disabled:opacity-60">
                    {{ emailForm.processing ? 'Đang đăng nhập...' : 'Đăng nhập' }}
                </button>
            </form>

            <!-- OTP form -->
            <div v-else>
                <div v-if="otpStep === 'phone'" class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Số điện thoại</label>
                        <input v-model="phoneInput" type="tel"
                            class="w-full border border-[var(--color-line)] rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-[var(--color-accent)] transition"
                            placeholder="0901234567" />
                    </div>
                    <button @click="sendOtp" :disabled="otpSending || !phoneInput"
                        class="w-full bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold py-3 rounded-xl transition disabled:opacity-60">
                        {{ otpSending ? 'Đang gửi...' : 'Gửi mã OTP' }}
                    </button>
                </div>

                <form v-else @submit.prevent="submitOtp" class="space-y-4">
                    <p class="text-sm text-[var(--color-muted)] text-center">Nhập mã 6 số gửi đến <strong class="text-[var(--color-ink)]">{{ phoneInput }}</strong></p>
                    <input v-model="otpForm.otp" type="text" maxlength="6"
                        class="w-full border border-[var(--color-line)] rounded-xl px-4 py-4 text-center text-2xl font-mono tracking-widest focus:outline-none focus:border-[var(--color-accent)] transition"
                        placeholder="_ _ _ _ _ _" />
                    <p v-if="otpForm.errors.otp" class="text-red-500 text-xs text-center">{{ otpForm.errors.otp }}</p>
                    <button type="submit" :disabled="otpForm.processing || otpForm.otp.length < 6"
                        class="w-full bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold py-3 rounded-xl transition disabled:opacity-60">
                        {{ otpForm.processing ? 'Đang xác nhận...' : 'Xác nhận OTP' }}
                    </button>
                    <button type="button" @click="otpStep = 'phone'" class="w-full text-sm text-[var(--color-muted)] hover:text-[var(--color-ink)] transition">
                        ← Đổi số điện thoại
                    </button>
                </form>
            </div>

            <p class="text-center text-sm text-[var(--color-muted)] mt-6">
                Chưa có tài khoản?
                <Link href="/register" class="text-[var(--color-accent)] font-semibold hover:underline">Đăng ký</Link>
            </p>
        </div>
    </div>
</template>
