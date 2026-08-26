<script setup>
import { ref, computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { useLocalStorage } from '@vueuse/core'
import AppLayout from '@/Layouts/AppLayout.vue'
import CouponTicket from '@/Components/CouponTicket.vue'
import Disclaimer from '@/Components/Disclaimer.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps({
    vouchers: { type: Array, default: () => [] },
    voucherResult: { type: Object, default: null },
})

const url = ref('')
const scanning = ref(false)
const error = ref(null)
const toast = useToast()

// --- sanvoucher.vn: công cụ lấy link voucher công khai, không cần đăng nhập ---
// Link CTA (voucher_links) trỏ thẳng tới link affiliate của salesoc.vn — nơi mã giảm giá
// thực sự được áp dụng. Đơn hàng qua link này tính hoa hồng cho salesoc.vn, không phải
// cho mình; đây là đánh đổi có chủ đích để người dùng nhận được mã giảm giá thật.
const SOURCE_LABELS = { facebook: 'Facebook', instagram: 'Instagram', zalo: 'Zalo', youtube: 'YouTube' }

const voucherUrl = ref('')
const resolving = ref(false)
const voucherError = ref(null)
const history = useLocalStorage('sv_history', [])

function resolveVoucher() {
    if (!voucherUrl.value.trim()) return
    resolving.value = true
    voucherError.value = null

    router.post('/voucher/resolve', { url: voucherUrl.value }, {
        preserveScroll: true,
        onSuccess: () => {
            resolving.value = false
            const result = props.voucherResult
            if (result) {
                history.value = [
                    {
                        url: result.canonical_url,
                        product_name: result.product?.product_name || null,
                        product_image: result.product?.product_image || null,
                        created_at: new Date().toISOString(),
                    },
                    ...history.value,
                ].slice(0, 5)
            }
        },
        onError: (errors) => {
            voucherError.value = errors.voucher_url || 'Có lỗi xảy ra, vui lòng thử lại.'
            toast.error(voucherError.value)
            resolving.value = false
        },
    })
}

// salesoc.vn không trả trạng thái còn/hết lượt của từng mã, nên hiển thị TẤT CẢ lựa chọn
// mỗi nền tảng (không chỉ mã % cao nhất) — bấm thử lần lượt nếu mã đầu đã hết lượt.
const voucherLinkEntries = computed(() => {
    const links = props.voucherResult?.voucher_links || {}
    const entries = []
    for (const [source, options] of Object.entries(links)) {
        (options || []).forEach((opt, i) => {
            entries.push({
                key: `${source}-${i}`,
                source,
                url: opt.url,
                label: opt.label || SOURCE_LABELS[source],
            })
        })
    }
    return entries
})

const shorteningKey = ref(null)

async function openVoucherLink(entry) {
    if (!entry?.url || shorteningKey.value) return

    shorteningKey.value = entry.key
    // Mở tab trắng NGAY trong lúc click (đồng bộ) để trình duyệt không chặn popup —
    // nếu đợi axios xong mới gọi window.open() thì đã mất "user gesture", dễ bị chặn.
    const newTab = window.open('', '_blank')

    try {
        const { data } = await axios.post('/voucher/shorten', {
            url: entry.url,
            source: entry.source,
            product_name: props.voucherResult?.product?.product_name || null,
        })
        if (newTab) {
            newTab.location.href = data.short_url
        } else {
            // Popup bị chặn — điều hướng ngay tab hiện tại thay vì bỏ cuộc.
            window.location.href = data.short_url
        }
    } catch (e) {
        newTab?.close()
        toast.error('Không thể tạo link, vui lòng thử lại.')
    } finally {
        shorteningKey.value = null
    }
}

function vnd(n) {
    return '₫' + Number(n || 0).toLocaleString('vi-VN')
}

// --- Mã gợi ý ---
const platformTabs = [
    { key: 'all', label: 'Tất cả' },
    { key: 'shopee', label: 'Shopee' },
    { key: 'lazada', label: 'Lazada' },
    { key: 'tiki', label: 'Tiki' },
    { key: 'tiktok', label: 'TikTok' },
]
const activePlatform = ref('all')

const filteredVouchers = computed(() => {
    if (activePlatform.value === 'all') return props.vouchers
    return props.vouchers.filter(v => v.platform === activePlatform.value || v.platform === 'all')
})

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
    <Head>
        <title>Tìm Voucher Shopee Facebook YouTube Instagram | sanvoucher.vn</title>
        <meta name="description" content="Dán link sản phẩm Shopee → nhận link voucher độc quyền Facebook, YouTube, Instagram. Xem giá sau giảm ngay." />
    </Head>
    <AppLayout>
        <!-- Hero -->
        <section class="relative overflow-hidden pt-16 pb-20 px-4">
            <!-- Background blobs -->
            <div class="absolute top-0 left-1/4 w-96 h-96 bg-[var(--color-peach)] rounded-full blur-3xl opacity-40 animate-float pointer-events-none"></div>
            <div class="absolute bottom-0 right-1/4 w-64 h-64 bg-[var(--color-green-soft)] rounded-full blur-3xl opacity-40 animate-float pointer-events-none" style="animation-delay:3s"></div>

            <div class="relative max-w-3xl mx-auto text-center">
                <!-- Badge -->
                <div class="inline-flex items-center gap-2 bg-[var(--color-surface)] border border-[var(--color-line)] rounded-full px-4 py-2 text-sm font-semibold text-[var(--color-ink)] mb-6 shadow-sm">
                    <span class="w-2 h-2 rounded-full bg-[var(--color-brand-green)] animate-blink-dot"></span>
                    Hỗ trợ Shopee · Lazada · TikTok Shop · Tiki
                </div>

                <!-- H1 -->
                <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-[var(--color-ink)] leading-tight mb-4">
                    Lấy <span class="relative inline-block px-2 rounded-lg bg-[var(--color-peach)] text-[var(--color-accent)]">mã giảm</span> giá<br>siêu tốc — 3 giây
                </h1>
                <p class="text-[var(--color-muted)] text-lg mb-10 max-w-xl mx-auto">
                    Dán link sản phẩm, nhận ngay voucher giảm giá tốt nhất + link affiliate hoàn tiền. Miễn phí, không cần cài app.
                </p>

                <!-- URL Input -->
                <div class="flex gap-3 max-w-xl mx-auto mb-4">
                    <div class="relative flex-1">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[var(--color-muted)]">🔗</span>
                        <input
                            v-model="url"
                            type="url"
                            @keydown.enter="scan"
                            placeholder="Dán link sản phẩm vào đây..."
                            class="w-full pl-10 pr-4 py-4 border border-[var(--color-line)] rounded-2xl text-sm bg-[var(--color-surface)] focus:outline-none focus:border-[var(--color-accent)] focus:ring-2 focus:ring-[var(--color-peach)] transition shadow-sm"
                        />
                    </div>
                    <button
                        @click="scan"
                        :disabled="scanning || !url.trim()"
                        class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-bold px-7 py-4 rounded-2xl transition shadow-md disabled:opacity-60 flex items-center gap-2 whitespace-nowrap"
                    >
                        <svg v-if="scanning" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" stroke-dasharray="30 70" />
                        </svg>
                        {{ scanning ? 'Đang quét...' : 'Lấy mã' }}
                    </button>
                </div>
                <p v-if="error" class="text-red-500 text-sm text-center mb-2">{{ error }}</p>

                <!-- Trust strip -->
                <div class="flex flex-wrap justify-center gap-6 text-xs text-[var(--color-muted)] font-medium">
                    <span>⭐ 4.9/5 (50,000+ đánh giá)</span>
                    <span>💰 ₫4.2 tỷ đã tiết kiệm</span>
                    <span>⚡ Quét trong 3 giây</span>
                    <span>🔐 Bảo mật tuyệt đối</span>
                </div>
            </div>
        </section>

        <!-- sanvoucher.vn: Công cụ lấy voucher công khai (không cần đăng nhập) -->
        <section class="py-12 px-4 bg-[var(--color-bg)]">
            <div class="max-w-xl mx-auto">
                <div class="text-center mb-6">
                    <h2 class="text-2xl font-extrabold text-[var(--color-ink)] mb-2">sanvoucher.vn — Lấy link voucher Shopee</h2>
                    <p class="text-[var(--color-muted)] text-sm">Dán link sản phẩm Shopee, nhận link voucher riêng cho Facebook, YouTube, Instagram.</p>
                </div>

                <!-- Mobile-first: 1 cột, input trên, nút to bên dưới -->
                <div class="flex flex-col gap-3">
                    <input
                        v-model="voucherUrl"
                        type="url"
                        @keydown.enter="resolveVoucher"
                        placeholder="Dán link Shopee (shopee.vn hoặc s.shopee.vn)..."
                        class="w-full px-4 py-4 border border-[var(--color-line)] rounded-2xl text-sm bg-[var(--color-surface)] focus:outline-none focus:border-[var(--color-accent)] focus:ring-2 focus:ring-[var(--color-peach)] transition shadow-sm"
                    />
                    <button
                        @click="resolveVoucher"
                        :disabled="resolving || !voucherUrl.trim()"
                        class="w-full bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-bold px-7 py-4 rounded-2xl transition shadow-md disabled:opacity-60 flex items-center justify-center gap-2"
                    >
                        <svg v-if="resolving" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" stroke-dasharray="30 70" />
                        </svg>
                        {{ resolving ? 'Đang xử lý...' : 'Lấy link voucher' }}
                    </button>
                    <p v-if="voucherError" class="text-red-500 text-sm text-center">{{ voucherError }}</p>
                </div>

                <!-- Kết quả -->
                <div v-if="voucherResult" class="mt-6 bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5">
                    <div v-if="voucherResult.product" class="flex gap-4 items-start mb-5">
                        <div class="w-16 h-16 rounded-xl bg-[var(--color-peach-soft)] flex-none overflow-hidden">
                            <img v-if="voucherResult.product.product_image" :src="voucherResult.product.product_image" :alt="voucherResult.product.product_name" class="w-full h-full object-cover" />
                            <div v-else class="w-full h-full flex items-center justify-center text-2xl">🛍️</div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-[var(--color-ink)] text-sm line-clamp-2">{{ voucherResult.product.product_name || 'Sản phẩm' }}</p>
                            <p class="font-bold text-[var(--color-accent)] mt-1">{{ vnd(voucherResult.product.discounted_price) }}</p>
                        </div>
                    </div>
                    <p v-else class="text-sm text-[var(--color-muted)] mb-5">Không lấy được thông tin sản phẩm, nhưng bạn vẫn có thể dùng link voucher bên dưới.</p>

                    <div v-if="voucherResult.voucher_labels?.length" class="flex flex-wrap gap-2 mb-4">
                        <span
                            v-for="(label, i) in voucherResult.voucher_labels"
                            :key="i"
                            class="text-xs font-semibold px-3 py-1 rounded-full bg-[var(--color-green-soft)] text-[var(--color-ink)]"
                        >
                            {{ label }}
                        </span>
                    </div>

                    <div v-if="voucherLinkEntries.length" class="flex flex-col gap-2 mb-4">
                        <button
                            v-for="entry in voucherLinkEntries"
                            :key="entry.key"
                            @click="openVoucherLink(entry)"
                            :disabled="shorteningKey === entry.key"
                            class="w-full bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] text-[var(--color-ink)] font-semibold px-5 py-3 rounded-xl transition text-sm flex items-center justify-between disabled:opacity-60"
                        >
                            {{ shorteningKey === entry.key ? 'Đang chuyển hướng...' : `Mua ngay — ${entry.label}` }}
                            <span class="text-[var(--color-accent)]">→</span>
                        </button>
                    </div>
                    <p v-else class="text-sm text-[var(--color-muted)] mb-4">Không tìm thấy link voucher cho sản phẩm này.</p>

                    <p class="text-xs text-[var(--color-muted)] mb-4">Nếu 1 mã báo hết lượt, thử mã khác bên trên — salesoc.vn không báo trước mã nào còn hạn.</p>

                    <Disclaimer />
                </div>

                <!-- Lịch sử link (lưu trên trình duyệt) -->
                <div v-if="history.length" class="mt-8">
                    <h3 class="text-sm font-bold text-[var(--color-ink)] mb-3">Link gần đây</h3>
                    <div class="flex flex-col gap-2">
                        <a
                            v-for="(h, i) in history"
                            :key="i"
                            :href="h.url"
                            target="_blank"
                            rel="noopener"
                            class="flex items-center gap-3 bg-[var(--color-surface)] border border-[var(--color-line)] rounded-xl px-4 py-3 text-sm text-[var(--color-ink)] hover:border-[var(--color-accent)] transition"
                        >
                            <span class="truncate flex-1">{{ h.product_name || h.url }}</span>
                            <span class="text-[var(--color-muted)] text-xs whitespace-nowrap">{{ new Date(h.created_at).toLocaleDateString('vi-VN') }}</span>
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Mã giảm giá gợi ý -->
        <section v-if="vouchers.length" class="py-16 px-4 bg-[var(--color-bg)]">
            <div class="max-w-5xl mx-auto">
                <div class="text-center mb-8">
                    <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-ink)] mb-2">🎁 Mã giảm giá gợi ý</h2>
                    <p class="text-[var(--color-muted)] text-sm">Mã từ Facebook & YouTube đang có hiệu lực — copy và dùng ngay khi mua hàng.</p>
                </div>

                <!-- Platform filter -->
                <div class="flex flex-wrap justify-center gap-2 mb-8">
                    <button
                        v-for="tab in platformTabs"
                        :key="tab.key"
                        @click="activePlatform = tab.key"
                        :class="activePlatform === tab.key
                            ? 'bg-[var(--color-accent)] text-white'
                            : 'bg-[var(--color-surface)] text-[var(--color-ink)] border border-[var(--color-line)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)]'"
                        class="px-4 py-2 rounded-xl text-sm font-semibold transition"
                    >
                        {{ tab.label }}
                    </button>
                </div>

                <!-- Voucher grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <CouponTicket
                        v-for="v in filteredVouchers"
                        :key="v.id"
                        :code="v.code"
                        :discount-type="v.discount_type"
                        :discount-value="Number(v.discount_value)"
                        :minimum-order="Number(v.minimum_order)"
                        :expires-at="v.expires_at"
                        :is-freeship="v.discount_type === 'freeship'"
                        :source="v.source"
                        :subtitle="v.title"
                    />
                </div>
                <p v-if="!filteredVouchers.length" class="text-center text-[var(--color-muted)] text-sm py-8">
                    Chưa có mã cho sàn này. Thử chọn sàn khác nhé!
                </p>
            </div>
        </section>

        <!-- How it works -->
        <section class="py-16 px-4 bg-[var(--color-surface)]">
            <div class="max-w-4xl mx-auto text-center">
                <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-ink)] mb-12">Chỉ 3 bước đơn giản</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div v-for="(step, i) in [
                        { icon: '📎', title: 'Dán link sản phẩm', desc: 'Sao chép link từ Shopee, Lazada, TikTok Shop hoặc Tiki và dán vào ô tìm kiếm.' },
                        { icon: '🔍', title: 'Quét voucher', desc: 'Hệ thống tự động tìm tất cả mã giảm giá tốt nhất đang có hiệu lực.' },
                        { icon: '🛍️', title: 'Copy & mua ngay', desc: 'Copy mã, nhấn link affiliate để mua và nhận cashback sau khi đơn thành công.' },
                    ]" :key="i" class="flex flex-col items-center text-center">
                        <div class="w-16 h-16 rounded-2xl bg-[var(--color-peach-soft)] flex items-center justify-center text-3xl mb-4">{{ step.icon }}</div>
                        <div class="w-8 h-8 rounded-full bg-[var(--color-accent)] text-white font-extrabold text-sm flex items-center justify-center -mt-8 mb-4 ml-8">{{ i+1 }}</div>
                        <h3 class="font-extrabold text-[var(--color-ink)] mb-2">{{ step.title }}</h3>
                        <p class="text-[var(--color-muted)] text-sm leading-relaxed">{{ step.desc }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ -->
        <section class="py-16 px-4 bg-[var(--color-bg)]">
            <div class="max-w-2xl mx-auto">
                <h2 class="text-2xl font-extrabold text-[var(--color-ink)] text-center mb-10">Câu hỏi thường gặp</h2>
                <div class="space-y-3">
                    <div
                        v-for="(faq, i) in faqs"
                        :key="i"
                        class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] overflow-hidden"
                    >
                        <button
                            @click="openFaq = openFaq === i ? null : i"
                            class="w-full px-6 py-4 text-left flex justify-between items-center font-semibold text-[var(--color-ink)] text-sm"
                        >
                            {{ faq.q }}
                            <span class="text-[var(--color-muted)] ml-4 transition-transform" :class="openFaq === i ? 'rotate-180' : ''">▾</span>
                        </button>
                        <Transition name="fade-up">
                            <div v-if="openFaq === i" class="px-6 pb-4 text-sm text-[var(--color-muted)] leading-relaxed">
                                {{ faq.a }}
                            </div>
                        </Transition>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="py-16 px-4 bg-[var(--color-accent)]">
            <div class="max-w-xl mx-auto text-center">
                <h2 class="text-2xl md:text-3xl font-extrabold text-white mb-4">Sẵn sàng tiết kiệm tiền?</h2>
                <p class="text-white/80 mb-8">Hơn 1.2 triệu mã đã được tạo. Tham gia ngay hôm nay.</p>
                <button @click="$el.querySelector('input')?.focus()" onclick="window.scrollTo({top:0,behavior:'smooth'})"
                    class="bg-white text-[var(--color-accent)] font-bold px-8 py-4 rounded-2xl hover:shadow-xl transition">
                    Lấy mã ngay — Miễn phí
                </button>
            </div>
        </section>
    </AppLayout>
</template>
