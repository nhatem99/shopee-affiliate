<script setup>
import { ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useToast } from '@/composables/useToast'

const url = ref('')
const scanning = ref(false)
const error = ref(null)
const toast = useToast()

const faqs = [
    { q: 'Công cụ này hoạt động như thế nào?', a: 'Bạn dán link sản phẩm Shopee, Lazada hoặc TikTok Shop — hệ thống tự động tìm mã giảm giá và tạo link affiliate có hoàn tiền cho bạn.' },
    { q: 'Tôi có được hoàn tiền không?', a: 'Có! Khi mua qua link affiliate của chúng tôi, bạn nhận được cashback sau khi đơn hàng được xác nhận thành công (thường 3–7 ngày).' },
    { q: 'Có mất phí không?', a: 'Hoàn toàn miễn phí. Chúng tôi nhận hoa hồng từ Shopee/Lazada khi bạn mua thành công.' },
    { q: 'Hỗ trợ những sàn nào?', a: 'Hiện tại hỗ trợ Shopee, Lazada, TikTok Shop và Tiki.' },
]
const openFaq = ref(null)

function scan() {
    if (!url.value.trim()) return
    scanning.value = true
    error.value = null

    router.post('/affiliate/scan', { url: url.value }, {
        onSuccess: () => { scanning.value = false },
        onError: (errors) => {
            error.value = errors.url || 'Có lỗi xảy ra, vui lòng thử lại.'
            toast.error(error.value)
            scanning.value = false
        },
    })
}
</script>

<template>
    <Head title="Lấy mã giảm giá ngay" />
    <AppLayout>
        <!-- Hero -->
        <section class="relative overflow-hidden pt-16 pb-20 px-4">
            <!-- Background blobs -->
            <div class="absolute top-0 left-1/4 w-96 h-96 bg-[--color-peach] rounded-full blur-3xl opacity-40 animate-float pointer-events-none"></div>
            <div class="absolute bottom-0 right-1/4 w-64 h-64 bg-[--color-green-soft] rounded-full blur-3xl opacity-40 animate-float pointer-events-none" style="animation-delay:3s"></div>

            <div class="relative max-w-3xl mx-auto text-center">
                <!-- Badge -->
                <div class="inline-flex items-center gap-2 bg-white border border-[--color-line] rounded-full px-4 py-2 text-sm font-semibold text-[--color-ink] mb-6 shadow-sm">
                    <span class="w-2 h-2 rounded-full bg-[--color-brand-green] animate-blink-dot"></span>
                    Hỗ trợ Shopee · Lazada · TikTok Shop · Tiki
                </div>

                <!-- H1 -->
                <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-[--color-ink] leading-tight mb-4">
                    Lấy <span class="relative inline-block px-2 rounded-lg bg-[--color-peach] text-[--color-accent]">mã giảm</span> giá<br>siêu tốc — 3 giây
                </h1>
                <p class="text-[--color-muted] text-lg mb-10 max-w-xl mx-auto">
                    Dán link sản phẩm, nhận ngay voucher giảm giá tốt nhất + link affiliate hoàn tiền. Miễn phí, không cần cài app.
                </p>

                <!-- URL Input -->
                <div class="flex gap-3 max-w-xl mx-auto mb-4">
                    <div class="relative flex-1">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[--color-muted]">🔗</span>
                        <input
                            v-model="url"
                            type="url"
                            @keydown.enter="scan"
                            placeholder="Dán link sản phẩm vào đây..."
                            class="w-full pl-10 pr-4 py-4 border border-[--color-line] rounded-2xl text-sm bg-white focus:outline-none focus:border-[--color-accent] focus:ring-2 focus:ring-[--color-peach] transition shadow-sm"
                        />
                    </div>
                    <button
                        @click="scan"
                        :disabled="scanning || !url.trim()"
                        class="bg-[--color-accent] hover:bg-[--color-accent-deep] text-white font-bold px-7 py-4 rounded-2xl transition shadow-md disabled:opacity-60 flex items-center gap-2 whitespace-nowrap"
                    >
                        <svg v-if="scanning" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" stroke-dasharray="30 70" />
                        </svg>
                        {{ scanning ? 'Đang quét...' : 'Lấy mã' }}
                    </button>
                </div>
                <p v-if="error" class="text-red-500 text-sm text-center mb-2">{{ error }}</p>

                <!-- Trust strip -->
                <div class="flex flex-wrap justify-center gap-6 text-xs text-[--color-muted] font-medium">
                    <span>⭐ 4.9/5 (50,000+ đánh giá)</span>
                    <span>💰 ₫4.2 tỷ đã tiết kiệm</span>
                    <span>⚡ Quét trong 3 giây</span>
                    <span>🔐 Bảo mật tuyệt đối</span>
                </div>
            </div>
        </section>

        <!-- How it works -->
        <section class="py-16 px-4 bg-white">
            <div class="max-w-4xl mx-auto text-center">
                <h2 class="text-2xl md:text-3xl font-extrabold text-[--color-ink] mb-12">Chỉ 3 bước đơn giản</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div v-for="(step, i) in [
                        { icon: '📎', title: 'Dán link sản phẩm', desc: 'Sao chép link từ Shopee, Lazada, TikTok Shop hoặc Tiki và dán vào ô tìm kiếm.' },
                        { icon: '🔍', title: 'Quét voucher', desc: 'Hệ thống tự động tìm tất cả mã giảm giá tốt nhất đang có hiệu lực.' },
                        { icon: '🛍️', title: 'Copy & mua ngay', desc: 'Copy mã, nhấn link affiliate để mua và nhận cashback sau khi đơn thành công.' },
                    ]" :key="i" class="flex flex-col items-center text-center">
                        <div class="w-16 h-16 rounded-2xl bg-[--color-peach-soft] flex items-center justify-center text-3xl mb-4">{{ step.icon }}</div>
                        <div class="w-8 h-8 rounded-full bg-[--color-accent] text-white font-extrabold text-sm flex items-center justify-center -mt-8 mb-4 ml-8">{{ i+1 }}</div>
                        <h3 class="font-extrabold text-[--color-ink] mb-2">{{ step.title }}</h3>
                        <p class="text-[--color-muted] text-sm leading-relaxed">{{ step.desc }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ -->
        <section class="py-16 px-4 bg-[--color-bg]">
            <div class="max-w-2xl mx-auto">
                <h2 class="text-2xl font-extrabold text-[--color-ink] text-center mb-10">Câu hỏi thường gặp</h2>
                <div class="space-y-3">
                    <div
                        v-for="(faq, i) in faqs"
                        :key="i"
                        class="bg-white rounded-2xl border border-[--color-line] overflow-hidden"
                    >
                        <button
                            @click="openFaq = openFaq === i ? null : i"
                            class="w-full px-6 py-4 text-left flex justify-between items-center font-semibold text-[--color-ink] text-sm"
                        >
                            {{ faq.q }}
                            <span class="text-[--color-muted] ml-4 transition-transform" :class="openFaq === i ? 'rotate-180' : ''">▾</span>
                        </button>
                        <Transition name="fade-up">
                            <div v-if="openFaq === i" class="px-6 pb-4 text-sm text-[--color-muted] leading-relaxed">
                                {{ faq.a }}
                            </div>
                        </Transition>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="py-16 px-4 bg-[--color-accent]">
            <div class="max-w-xl mx-auto text-center">
                <h2 class="text-2xl md:text-3xl font-extrabold text-white mb-4">Sẵn sàng tiết kiệm tiền?</h2>
                <p class="text-white/80 mb-8">Hơn 1.2 triệu mã đã được tạo. Tham gia ngay hôm nay.</p>
                <button @click="$el.querySelector('input')?.focus()" onclick="window.scrollTo({top:0,behavior:'smooth'})"
                    class="bg-white text-[--color-accent] font-bold px-8 py-4 rounded-2xl hover:shadow-xl transition">
                    Lấy mã ngay — Miễn phí
                </button>
            </div>
        </section>
    </AppLayout>
</template>
