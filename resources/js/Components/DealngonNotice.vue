<script setup>
import { ref, onMounted } from 'vue'
import { useCashback } from '@/composables/useCashback'

// Hộp thông báo cho khách ĐÃ đăng nhập khi đang mời sang dealngon (DealngonInvite): hoàn tiền đã
// chuyển sang đó, tietkiemvi đang xây dựng lại. Hiện MỘT lần mỗi phiên trình duyệt — đăng nhập
// lại (tab mới) là thấy lại; trong phiên thì chuông thông báo và thẻ ở trang chủ vẫn còn đó.
// AppLayout chỉ gắn component này cho khách đăng nhập, không phải admin.
const SEEN_KEY = 'sv_dealngon_notice_seen'

const { dealngonUrl, dealngonHost } = useCashback()
const open = ref(false)

onMounted(() => {
    if (!dealngonUrl.value) return
    try {
        if (sessionStorage.getItem(SEEN_KEY)) return
        sessionStorage.setItem(SEEN_KEY, '1')
    } catch (e) {
        // Không có sessionStorage (chế độ riêng tư cũ) thì vẫn hiện — thà hiện lại còn hơn không báo.
    }
    open.value = true
})
</script>

<template>
    <Transition name="fade-up">
        <div
            v-if="open && dealngonUrl"
            class="fixed inset-0 z-[70] flex items-end sm:items-center justify-center bg-black/50 px-4 pb-6 sm:pb-0"
            role="dialog"
            aria-modal="true"
            aria-labelledby="dealngon-notice-title"
            @click.self="open = false"
        >
            <div class="w-full max-w-md rounded-3xl bg-[var(--color-surface)] border border-[var(--color-line)] p-6 shadow-2xl text-center">
                <p class="text-4xl mb-2">📢</p>
                <h2 id="dealngon-notice-title" class="text-lg font-black text-[var(--color-ink)]">Hoàn tiền đã chuyển sang {{ dealngonHost }}</h2>
                <p class="mt-2 text-sm text-[var(--color-muted)] leading-relaxed">
                    Tietkiemvi đang xây dựng lại — từ nay mua hàng qua <b class="text-[var(--color-ink)]">{{ dealngonHost }}</b> mới được hoàn tiền.
                    Đăng nhập bên đó bằng đúng email, mật khẩu hoặc Google như ở đây; số dư ví và lịch sử của bạn đã được chuyển theo.
                </p>
                <a
                    :href="dealngonUrl"
                    class="btn-fire mt-5 w-full px-6 py-3.5 rounded-2xl flex items-center justify-center gap-2 text-base no-underline"
                >Sang {{ dealngonHost }} →</a>
                <button
                    type="button"
                    @click="open = false"
                    class="mt-2 w-full py-2.5 text-sm font-semibold text-[var(--color-muted)] hover:text-[var(--color-ink)]"
                >Để sau</button>
            </div>
        </div>
    </Transition>
</template>
