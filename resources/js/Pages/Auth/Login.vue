<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3'
import ThemeToggle from '@/Components/ThemeToggle.vue'
import { useCashback } from '@/composables/useCashback'

// Lý do đăng nhập nói bằng con số thật của hệ thống, không hứa suông: khi admin tắt chương trình
// (rate = 0) thì cashbackOn = false và khối lý do tự biến mất — xem useCashback.
const { cashbackOn, cashbackRate } = useCashback()

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
    <!-- Cửa ải BẮT BUỘC: khách phải qua đây thì đơn mới quy được về họ. Nên trang này được nới
         rộng hơn phần còn lại của nhóm.
         Đệm trên/dưới viết bằng max(2.5rem, env(...)) chứ KHÔNG phải "py-10 safe-top safe-bottom":
         .safe-top/.safe-bottom nằm ở @layer components, còn py-* ở @layer utilities — đặt cạnh
         nhau thì py-* thắng và phần chừa cho tai thỏ/vạch home biến mất không kêu một tiếng. -->
    <div class="min-h-screen bg-[var(--color-bg)] flex items-center justify-center px-4 pt-[max(2.5rem,env(safe-area-inset-top))] pb-[max(2.5rem,env(safe-area-inset-bottom))]">
        <div class="fixed top-4 right-4 z-50"><ThemeToggle /></div>
        <div class="w-full max-w-md card p-6 sm:p-8">
            <div class="text-center mb-6">
                <h1 class="text-2xl font-extrabold text-[var(--color-ink)]">Đăng nhập</h1>
                <p class="text-[var(--color-muted)] text-sm mt-1">Chào mừng bạn trở lại!</p>
            </div>

            <!-- VÌ SAO phải đăng nhập, nói trước khi bắt điền: khách vào từ link Facebook/Zalo
                 thường nghĩ đây là thủ tục moi thông tin. Lượt bấm lúc chưa đăng nhập KHÔNG mang
                 sub_id (xem ShortLinkController) nên đơn đó vĩnh viễn không quy về ai được — đó
                 mới là lý do thật, và nói thẳng ra thì đáng tin hơn mọi lời mời. -->
            <div v-if="cashbackOn" class="panel px-4 py-3 mb-5">
                <p class="text-sm font-bold text-[var(--color-money)]">Vì sao nên đăng nhập trước khi lấy mã</p>
                <p class="text-xs text-[var(--color-muted)] mt-1 leading-relaxed">
                    Đơn hàng chỉ được hoàn <b class="text-[var(--color-ink)] num">{{ cashbackRate }}%</b> khi bạn
                    đã đăng nhập <b class="text-[var(--color-ink)]">lúc lấy mã</b>. Mua xong rồi mới đăng nhập thì
                    đơn đó không còn quy về ai được nữa — chúng tôi cũng không cứu lại được.
                </p>
            </div>

            <!-- Lỗi đăng nhập dùng --color-danger: đây là "mất lối vào", cùng họ với huỷ/lỗi.
                 Bản cũ gõ thẳng red-200/red-50/red-700 nên ở chế độ tối (mặc định của trang) là
                 chữ đỏ sẫm trên nền hồng sáng chói giữa thẻ navy. -->
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
                Đăng nhập bằng Google
            </a>

            <div class="flex items-center gap-3 my-6">
                <div class="flex-1 h-px bg-[var(--color-line)]"></div>
                <span class="text-xs font-semibold text-[var(--color-muted)] tracking-wide">HOẶC DÙNG EMAIL</span>
                <div class="flex-1 h-px bg-[var(--color-line)]"></div>
            </div>

            <!-- Email/Password form.
                 Ô nhập cao 48px (không phải py-3): bàn phím điện thoại che nửa dưới màn hình, ô
                 thấp hơn thế là phải nhắm mà bấm. Bỏ + ring tự chế, dùng chung
                 .focus-ring để viền focus ở đây giống hệt mọi nút khác trong web.
                 bg/text đặt tường minh: preflight của Tailwind cho input nền trong suốt, để mặc
                 thì ở chế độ tối ô nhập lộ nguyên mảng gradient của body qua. -->
            <form @submit.prevent="submitEmail" class="space-y-4">
                <div>
                    <label for="login-email" class="block text-sm font-semibold text-[var(--color-ink)] mb-1.5">Email</label>
                    <input id="login-email" v-model="emailForm.email" type="email" required
                        autocomplete="email" inputmode="email"
                        class="focus-ring w-full min-h-[48px] border border-[var(--color-line)] bg-[var(--color-bg)] text-[var(--color-ink)] rounded-xl px-4 text-sm focus:border-[var(--color-accent)] transition"
                        placeholder="you@example.com" />
                    <p v-if="emailForm.errors.email" class="text-xs font-semibold text-[var(--color-danger)] mt-1.5">{{ emailForm.errors.email }}</p>
                </div>
                <div>
                    <label for="login-password" class="block text-sm font-semibold text-[var(--color-ink)] mb-1.5">Mật khẩu</label>
                    <input id="login-password" v-model="emailForm.password" type="password" required
                        autocomplete="current-password"
                        class="focus-ring w-full min-h-[48px] border border-[var(--color-line)] bg-[var(--color-bg)] text-[var(--color-ink)] rounded-xl px-4 text-sm focus:border-[var(--color-accent)] transition"
                        placeholder="••••••••" />
                    <p v-if="emailForm.errors.password" class="text-xs font-semibold text-[var(--color-danger)] mt-1.5">{{ emailForm.errors.password }}</p>
                </div>
                <button type="submit" :disabled="emailForm.processing"
                    class="btn-fire w-full min-h-[52px] rounded-xl">
                    {{ emailForm.processing ? 'Đang đăng nhập...' : 'Đăng nhập' }}
                </button>
            </form>

            <p class="text-center text-sm text-[var(--color-muted)] mt-6">
                Chưa có tài khoản?
                <Link href="/register" class="focus-ring inline-flex items-center min-h-[44px] px-2 rounded-lg text-[var(--color-accent-deep)] font-semibold hover:underline">Đăng ký</Link>
            </p>
        </div>
    </div>
</template>
