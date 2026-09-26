<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3'
import ThemeToggle from '@/Components/ThemeToggle.vue'
import { useCashback } from '@/composables/useCashback'

// Lý do tạo tài khoản lấy từ con số thật (Setting 'cashback_rate'): admin tắt chương trình là
// khối lý do tự tắt theo, không còn câu hứa nào đứng lại một mình — xem useCashback.
const { cashbackOn, cashbackRate } = useCashback()

// 0 khi admin đang tắt thưởng — không hứa suông.
const props = defineProps({
    welcomeBonus: { type: Number, default: 0 },
})

function vnd(n) {
    return Number(n || 0).toLocaleString('vi-VN') + ' đ'
}

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
    <!-- Cùng khuôn với /login: đây là nửa còn lại của cửa ải bắt buộc. Xem ghi chú ở Login.vue
         về lý do đệm trên/dưới viết bằng max(..., env(...)) thay cho py-10 + .safe-top. -->
    <div class="min-h-screen bg-[var(--color-bg)] flex items-center justify-center px-4 pt-[max(2.5rem,env(safe-area-inset-top))] pb-[max(2.5rem,env(safe-area-inset-bottom))]">
        <div class="fixed top-4 right-4 z-50"><ThemeToggle /></div>
        <div class="w-full max-w-md card p-6 sm:p-8">
            <div class="text-center mb-6">
                <h1 class="text-2xl font-extrabold text-[var(--color-ink)]">Tạo tài khoản</h1>
                <p class="text-[var(--color-muted)] text-sm mt-1">Miễn phí, không quảng cáo</p>
                <!-- Tiền tặng là TIỀN CỦA KHÁCH nên mang màu --color-money, không mang màu nhấn:
                     màu nhấn trên trang này chỉ còn thuộc về đúng nút "Đăng ký". -->
                <p v-if="props.welcomeBonus > 0" class="num inline-flex items-center gap-1.5 mt-3 px-3 py-1.5 rounded-full bg-[var(--color-money-soft)] text-xs font-bold text-[var(--color-money)]">
                    🎁 Tặng ngay {{ vnd(props.welcomeBonus) }} vào ví khi tạo tài khoản
                </p>
            </div>

            <!-- Nói thẳng điều kiện trước khi bắt điền 5 ô. Khách nhóm này đa nghi sẵn với "web
                 hoàn tiền"; điều kiện giấu ở trang khác rồi mới lòi ra lúc không được hoàn là
                 đúng kiểu họ đang đề phòng. -->
            <div v-if="cashbackOn" class="panel px-4 py-3 mb-5">
                <p class="text-sm font-bold text-[var(--color-money)]">Có tài khoản mới được hoàn tiền</p>
                <p class="text-xs text-[var(--color-muted)] mt-1 leading-relaxed">
                    Hoàn <b class="text-[var(--color-ink)] num">{{ cashbackRate }}%</b> giá trị đơn, nhưng chỉ tính
                    cho đơn mà bạn <b class="text-[var(--color-ink)]">lấy mã lúc đã đăng nhập</b>. Đơn mua lúc chưa
                    đăng nhập thì không quy về tài khoản nào được.
                </p>
            </div>

            <!-- Lỗi dùng --color-danger (sống được ở cả hai chế độ), thay cho cặp red-50/red-700
                 gõ thẳng — cặp đó không có bản tối, mà tối là chế độ mặc định của trang. -->
            <p
                v-if="$page.props.flash?.error"
                role="alert"
                class="mb-4 rounded-xl border border-[rgba(var(--color-danger-rgb),.35)] bg-[var(--color-danger-soft)] px-4 py-3 text-sm font-semibold text-[var(--color-danger)]"
            >
                {{ $page.props.flash.error }}
            </p>

            <!-- Google Sign-in -->
            <a href="/auth/google/redirect"
                class="focus-ring flex items-center justify-center gap-3 w-full min-h-[48px] px-4 rounded-xl border border-[var(--color-line)] bg-[var(--color-surface)] text-sm font-semibold text-[var(--color-ink)] hover:bg-[var(--color-peach)] transition">
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

            <!-- Ô nhập cao 48px và có autocomplete đầy đủ: bàn phím điện thoại che nửa màn hình,
                 form 5 ô mà không cho trình duyệt điền hộ là năm lần gõ tay trong webview. -->
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label for="reg-name" class="block text-sm font-semibold text-[var(--color-ink)] mb-1.5">Họ và tên</label>
                    <input id="reg-name" v-model="form.name" type="text" required autocomplete="name"
                        class="focus-ring w-full min-h-[48px] border border-[var(--color-line)] bg-[var(--color-bg)] text-[var(--color-ink)] rounded-xl px-4 text-sm focus:border-[var(--color-accent)] transition"
                        placeholder="Nguyễn Văn A" />
                    <p v-if="form.errors.name" class="text-xs font-semibold text-[var(--color-danger)] mt-1.5">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label for="reg-email" class="block text-sm font-semibold text-[var(--color-ink)] mb-1.5">Email</label>
                    <input id="reg-email" v-model="form.email" type="email" required autocomplete="email" inputmode="email"
                        class="focus-ring w-full min-h-[48px] border border-[var(--color-line)] bg-[var(--color-bg)] text-[var(--color-ink)] rounded-xl px-4 text-sm focus:border-[var(--color-accent)] transition"
                        placeholder="you@example.com" />
                    <p v-if="form.errors.email" class="text-xs font-semibold text-[var(--color-danger)] mt-1.5">{{ form.errors.email }}</p>
                </div>
                <div>
                    <label for="reg-phone" class="block text-sm font-semibold text-[var(--color-ink)] mb-1.5">Số điện thoại <span class="text-[var(--color-muted)] font-normal">(tùy chọn)</span></label>
                    <input id="reg-phone" v-model="form.phone" type="tel" autocomplete="tel" inputmode="tel"
                        class="focus-ring w-full min-h-[48px] border border-[var(--color-line)] bg-[var(--color-bg)] text-[var(--color-ink)] rounded-xl px-4 text-sm focus:border-[var(--color-accent)] transition"
                        placeholder="0901234567" />
                    <p v-if="form.errors.phone" class="text-xs font-semibold text-[var(--color-danger)] mt-1.5">{{ form.errors.phone }}</p>
                </div>
                <div>
                    <label for="reg-password" class="block text-sm font-semibold text-[var(--color-ink)] mb-1.5">Mật khẩu</label>
                    <input id="reg-password" v-model="form.password" type="password" required autocomplete="new-password"
                        class="focus-ring w-full min-h-[48px] border border-[var(--color-line)] bg-[var(--color-bg)] text-[var(--color-ink)] rounded-xl px-4 text-sm focus:border-[var(--color-accent)] transition"
                        placeholder="Tối thiểu 8 ký tự" />
                    <p v-if="form.errors.password" class="text-xs font-semibold text-[var(--color-danger)] mt-1.5">{{ form.errors.password }}</p>
                </div>
                <div>
                    <label for="reg-password-confirm" class="block text-sm font-semibold text-[var(--color-ink)] mb-1.5">Xác nhận mật khẩu</label>
                    <input id="reg-password-confirm" v-model="form.password_confirmation" type="password" required autocomplete="new-password"
                        class="focus-ring w-full min-h-[48px] border border-[var(--color-line)] bg-[var(--color-bg)] text-[var(--color-ink)] rounded-xl px-4 text-sm focus:border-[var(--color-accent)] transition"
                        placeholder="Nhập lại mật khẩu" />
                </div>
                <button type="submit" :disabled="form.processing"
                    class="btn-fire w-full min-h-[52px] rounded-xl">
                    {{ form.processing ? 'Đang tạo tài khoản...' : 'Đăng ký' }}
                </button>
            </form>

            <p class="text-center text-sm text-[var(--color-muted)] mt-6">
                Đã có tài khoản?
                <Link href="/login" class="focus-ring inline-flex items-center min-h-[44px] px-2 rounded-lg text-[var(--color-accent-deep)] font-semibold hover:underline">Đăng nhập</Link>
            </p>
        </div>
    </div>
</template>
