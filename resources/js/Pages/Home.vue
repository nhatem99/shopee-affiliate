<script setup>
import { ref, computed, nextTick } from 'vue'
import { Head } from '@inertiajs/vue3'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { useLocalStorage } from '@vueuse/core'
import AppLayout from '@/Layouts/AppLayout.vue'
import CouponTicket from '@/Components/CouponTicket.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps({
    vouchers: { type: Array, default: () => [] },
    voucherResult: { type: Object, default: null },
})

const toast = useToast()

// --- tietkiemvi.com: công cụ lấy link voucher công khai, không cần đăng nhập ---
// Link CTA (voucher_links) trỏ thẳng tới link affiliate của salesoc.vn — nơi mã giảm giá
// thực sự được áp dụng. Đơn hàng qua link này tính hoa hồng cho salesoc.vn, không phải
// cho mình; đây là đánh đổi có chủ đích để người dùng nhận được mã giảm giá thật.
const SOURCE_LABELS = { facebook: 'Facebook', instagram: 'Instagram', zalo: 'Zalo', youtube: 'YouTube' }
// Icon SVG đơn sắc (kế thừa màu chữ nút) thay cho emoji — emoji hiển thị không đồng nhất giữa các máy/font.
const SOURCE_ICON_SVG = {
    facebook: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M22 12.06C22 6.48 17.52 2 11.94 2 6.36 2 1.88 6.48 1.88 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.42V9.91c0-2.39 1.42-3.71 3.6-3.71 1.04 0 2.13.19 2.13.19v2.34h-1.2c-1.18 0-1.55.73-1.55 1.48v1.78h2.64l-.42 2.91h-2.22V22c4.78-.76 8.44-4.92 8.44-9.94Z"/></svg>',
    youtube: '<svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="4" fill="#FF0000"/><path d="M10 8.5v7l6-3.5-6-3.5Z" fill="#fff"/></svg>',
    instagram: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1.2" fill="currentColor" stroke="none"/></svg>',
    zalo: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 5.94 2 10.8c0 2.68 1.4 5.06 3.6 6.66-.16 1.1-.6 2.5-1.4 3.54 0 0 2.2-.24 4.24-1.7.8.2 1.66.3 2.56.3 5.52 0 10-3.94 10-8.8S17.52 2 12 2Z"/></svg>',
    default: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.07 0l1.93-1.93a5 5 0 0 0-7.07-7.07L10.5 5.5"/><path d="M14 11a5 5 0 0 0-7.07 0l-1.93 1.93a5 5 0 0 0 7.07 7.07L13.5 18.5"/></svg>',
}
// Màu nút theo thương hiệu từng nền tảng, giống cách salesoc.vn phân biệt Mã FB/YTB/IG.
const SOURCE_STYLES = {
    facebook: 'text-white bg-gradient-to-r from-blue-600 to-blue-500 shadow-[0_4px_14px_rgba(37,99,235,0.35)] hover:brightness-110',
    youtube: 'text-white bg-gradient-to-r from-[#f97316] to-[#ea580c] shadow-[0_4px_14px_rgba(249,115,22,0.4)] hover:brightness-110',
    instagram: 'text-white bg-gradient-to-r from-[#e1306c] via-[#fd1d1d] to-[#fcb045] shadow-[0_4px_14px_rgba(225,48,108,0.35)] hover:brightness-110',
    zalo: 'text-white bg-gradient-to-r from-[#0068ff] to-[#0052cc] shadow-[0_4px_14px_rgba(0,104,255,0.35)] hover:brightness-110',
}

const voucherUrl = ref('')
const voucherUrlInput = ref(null)
const voucherResultEl = ref(null)
const resolving = ref(false)
const voucherError = ref(null)
const history = useLocalStorage('sv_history', [])

async function pasteVoucherUrl() {
    try {
        const text = (await navigator.clipboard.readText()).trim()
        if (text) {
            voucherUrl.value = text
            resolveVoucher()
        }
    } catch (e) {
        toast.error('Không thể đọc clipboard. Hãy dán thủ công (Ctrl+V).')
    }
}

// Dán link vào ô là tự động quét luôn, không cần bấm nút — giống các trang tương tự.
function onVoucherUrlPaste(e) {
    const text = (e.clipboardData || window.clipboardData)?.getData('text')?.trim()
    if (!text) return
    e.preventDefault()
    voucherUrl.value = text
    resolveVoucher()
}

function focusVoucherTool() {
    voucherUrlInput.value?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    voucherUrlInput.value?.focus()
}

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
                        product_name: result.product?.product_name || null,
                        product_image: result.product?.product_image || null,
                        created_at: new Date().toISOString(),
                        // Lưu lại các nút mã (nguồn/label/url) để "mua lại" sau này chỉ cần bấm nút,
                        // không cần hiện đường dẫn thô cho khách.
                        links: voucherLinkEntries.value.map(({ source, url, label }) => ({ source, url, label })),
                    },
                    ...history.value,
                ].slice(0, 5)
            }
            // Cuộn thẳng tới khu vực chọn mã ngay khi có kết quả — khách không cần tự kéo xuống.
            nextTick(() => voucherResultEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
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

async function openVoucherLink(entry, productName = null) {
    if (!entry?.url || shorteningKey.value) return

    shorteningKey.value = entry.key
    // Mở tab trắng NGAY trong lúc click (đồng bộ) để trình duyệt không chặn popup —
    // nếu đợi axios xong mới gọi window.open() thì đã mất "user gesture", dễ bị chặn.
    const newTab = window.open('', '_blank')

    try {
        const { data } = await axios.post('/voucher/shorten', {
            url: entry.url,
            source: entry.source,
            product_name: productName ?? props.voucherResult?.product?.product_name ?? null,
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
    { q: 'Công cụ này hoạt động như thế nào?', a: 'Bạn dán link sản phẩm Shopee vào ô ở đầu trang — hệ thống tự động tìm và hiển thị mã giảm giá Facebook, YouTube, Instagram đang áp dụng cho sản phẩm đó, không cần bấm thêm nút nào.' },
    { q: 'Tôi có được hoàn tiền không?', a: 'Công cụ lấy mã giảm giá không tạo hoàn tiền — mục đích là giúp bạn tìm nhanh mã Facebook/YouTube/Instagram để áp khi thanh toán trên Shopee.' },
    { q: 'Có mất phí không?', a: 'Hoàn toàn miễn phí, bạn không mất phí gì khi dùng công cụ lấy mã.' },
    { q: 'Hỗ trợ những sàn nào?', a: 'Ô dán link ở đầu trang hiện chỉ hỗ trợ Shopee. Riêng mục "Mã giảm giá gợi ý" bên dưới có thêm mã cho Lazada, TikTok Shop và Tiki.' },
]
const openFaq = ref(null)
</script>

<template>
    <Head>
        <title>Tìm Voucher Shopee Facebook YouTube Instagram | tietkiemvi.com</title>
        <meta name="description" content="Dán link sản phẩm Shopee → nhận link voucher độc quyền Facebook, YouTube, Instagram. Xem giá sau giảm ngay." />
    </Head>
    <AppLayout>
        <!-- Công cụ chính: hiện ngay khi vào trang, không cần mô tả dài trước đó -->
        <section id="voucher-tool" class="px-4 pt-6 pb-4">
            <div class="max-w-3xl mx-auto">
                <div class="rounded-3xl p-6 md:p-8 bg-gradient-to-br from-[var(--color-peach)] via-[var(--color-peach-soft)] to-[var(--color-green-soft)] border border-[var(--color-line)]">
                    <h1 class="text-xl md:text-2xl font-extrabold text-[var(--color-ink)] mb-1">
                        Dán link sản phẩm Shopee để lấy mã giảm giá
                    </h1>
                    <p class="text-sm text-[var(--color-muted)] mb-4">Nhận link voucher riêng cho Facebook, YouTube, Instagram — miễn phí.</p>

                    <div class="flex flex-col md:flex-row gap-3">
                        <div class="relative flex-1">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[var(--color-muted)]">🔗</span>
                            <input
                                ref="voucherUrlInput"
                                v-model="voucherUrl"
                                type="url"
                                @keydown.enter="resolveVoucher"
                                @paste="onVoucherUrlPaste"
                                placeholder="Dán link Shopee (shopee.vn hoặc s.shopee.vn)..."
                                class="w-full pl-10 pr-20 py-4 border border-[var(--color-line)] rounded-xl text-sm bg-[var(--color-surface)] focus:outline-none focus:border-[var(--color-accent)] focus:ring-2 focus:ring-[var(--color-peach)] transition"
                            />
                            <button
                                @click="pasteVoucherUrl"
                                type="button"
                                class="absolute right-1.5 top-1/2 -translate-y-1/2 text-xs font-bold text-[var(--color-accent)] bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] border border-[var(--color-accent)]/30 rounded-lg px-3 py-1.5 transition"
                            >Dán</button>
                        </div>
                        <button
                            @click="resolveVoucher"
                            :disabled="resolving || !voucherUrl.trim()"
                            class="btn-fire px-8 py-4 rounded-xl flex items-center justify-center gap-2 whitespace-nowrap"
                        >
                            <svg v-if="resolving" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" stroke-dasharray="30 70" />
                            </svg>
                            <span v-else>🔍</span>
                            {{ resolving ? 'Đang xử lý...' : 'Tìm mã ngay' }}
                        </button>
                    </div>
                    <p v-if="voucherError" class="text-red-500 text-sm mt-2">{{ voucherError }}</p>
                </div>

                <!-- Kết quả -->
                <div v-if="voucherResult" ref="voucherResultEl" class="mt-4 card-glass rounded-2xl p-5 scroll-mt-24">
                    <div v-if="voucherResult.product" class="flex gap-4 items-start mb-5">
                        <div class="w-16 h-16 rounded-xl bg-[var(--color-peach-soft)] flex-none overflow-hidden">
                            <img v-if="voucherResult.product.product_image" :src="voucherResult.product.product_image" :alt="voucherResult.product.product_name" class="w-full h-full object-cover" />
                            <div v-else class="w-full h-full flex items-center justify-center text-2xl">🛍️</div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <span class="inline-flex items-center justify-center w-5 h-5 rounded bg-[#F5511E] text-white text-[10px] font-black mb-1">S</span>
                            <p class="font-semibold text-[var(--color-ink)] text-sm line-clamp-2">{{ voucherResult.product.product_name || 'Sản phẩm' }}</p>
                            <p class="font-bold text-[var(--color-accent)] mt-1">{{ vnd(voucherResult.product.discounted_price) }}</p>
                        </div>
                    </div>
                    <p v-else class="text-sm text-[var(--color-muted)] mb-5">Không lấy được thông tin sản phẩm, nhưng bạn vẫn có thể dùng link voucher bên dưới.</p>

                    <div v-if="voucherResult.voucher_labels?.length" class="flex flex-wrap gap-2 mb-4">
                        <span
                            v-for="(label, i) in voucherResult.voucher_labels"
                            :key="i"
                            class="text-xs font-semibold px-3 py-1 rounded-full bg-[var(--color-green-soft)] text-[var(--color-brand-green)] border border-[var(--color-brand-green)]/20"
                        >
                            {{ label }}
                        </span>
                    </div>

                    <div v-if="voucherLinkEntries.length" class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-4 mt-3">
                        <div v-for="(entry, i) in voucherLinkEntries" :key="entry.key" class="relative">
                            <span
                                v-if="i === 0"
                                class="absolute -top-2.5 left-3 z-10 bg-[#facc15] text-[#1c0a00] text-[10px] font-extrabold px-2.5 py-0.5 rounded-full shadow-md"
                            >Đề xuất</span>
                            <button
                                @click="openVoucherLink(entry)"
                                :disabled="shorteningKey === entry.key"
                                class="w-full font-semibold px-4 py-3 rounded-xl transition-all text-sm flex items-center justify-between gap-2 disabled:opacity-60"
                                :class="[SOURCE_STYLES[entry.source] || 'bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] text-[var(--color-ink)]', i === 0 && 'animate-pulse-ring']"
                            >
                                <span class="flex items-center gap-2 min-w-0">
                                    <span v-html="SOURCE_ICON_SVG[entry.source] || SOURCE_ICON_SVG.default" class="flex-none [&>svg]:w-4 [&>svg]:h-4"></span>
                                    <span class="truncate">{{ shorteningKey === entry.key ? 'Đang chuyển hướng...' : entry.label }}</span>
                                </span>
                                <span class="flex-none">→</span>
                            </button>
                        </div>
                    </div>
                    <p v-else class="text-sm text-[var(--color-muted)] mb-4">Không tìm thấy link voucher cho sản phẩm này.</p>

                    <div class="flex items-start gap-2 bg-[var(--color-peach-soft)] border border-[var(--color-accent)]/25 rounded-xl px-3 py-2.5">
                        <span class="text-sm leading-none">⚠️</span>
                        <p class="text-xs text-[var(--color-accent-deep)] leading-relaxed">Nếu 1 mã báo hết lượt, thử mã khác bên trên nhé.</p>
                    </div>
                </div>

                <!-- Lịch sử chuyển đổi (lưu trên trình duyệt) -->
                <div v-if="history.length" class="mt-8">
                    <h3 class="text-sm font-bold text-[var(--color-ink)] mb-3">Lịch sử chuyển đổi</h3>
                    <div class="flex flex-col gap-3">
                        <div
                            v-for="(h, hi) in history"
                            :key="hi"
                            class="card-glass rounded-xl px-4 py-3"
                        >
                            <div class="flex items-center gap-3 mb-2.5">
                                <div class="w-10 h-10 rounded-lg bg-[var(--color-peach-soft)] flex-none overflow-hidden">
                                    <img v-if="h.product_image" :src="h.product_image" :alt="h.product_name" class="w-full h-full object-cover" />
                                    <div v-else class="w-full h-full flex items-center justify-center text-lg">🛍️</div>
                                </div>
                                <span class="truncate flex-1 text-sm text-[var(--color-ink)] font-medium">{{ h.product_name || 'Sản phẩm' }}</span>
                                <span class="text-[var(--color-muted)] text-xs whitespace-nowrap">{{ new Date(h.created_at).toLocaleDateString('vi-VN') }}</span>
                            </div>
                            <div v-if="h.links?.length" class="flex flex-wrap gap-2">
                                <button
                                    v-for="link in h.links"
                                    :key="`hist-${hi}-${link.source}`"
                                    @click="openVoucherLink({ key: `hist-${hi}-${link.source}`, source: link.source, url: link.url }, h.product_name)"
                                    :disabled="shorteningKey === `hist-${hi}-${link.source}`"
                                    class="font-semibold px-3 py-2 rounded-lg transition-all text-xs flex items-center gap-1.5 disabled:opacity-60"
                                    :class="SOURCE_STYLES[link.source] || 'bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] text-[var(--color-ink)]'"
                                >
                                    <span v-html="SOURCE_ICON_SVG[link.source] || SOURCE_ICON_SVG.default" class="flex-none [&>svg]:w-3.5 [&>svg]:h-3.5"></span>
                                    {{ shorteningKey === `hist-${hi}-${link.source}` ? 'Đang mở...' : link.label }}
                                </button>
                            </div>
                        </div>
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
                            ? 'btn-fire'
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
        <section class="py-16 px-4 bg-[var(--color-bg)]">
            <div class="max-w-4xl mx-auto text-center">
                <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-ink)] mb-12">Chỉ 3 bước đơn giản</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div v-for="(step, i) in [
                        { icon: '📎', title: 'Dán link sản phẩm Shopee', desc: 'Copy link sản phẩm từ app hoặc web Shopee, dán vào ô ở đầu trang.', badge: 'cyan' },
                        { icon: '🔍', title: 'Tự động quét mã', desc: 'Không cần bấm nút — hệ thống tự tìm mã Facebook, YouTube, Instagram còn hiệu lực ngay khi bạn dán link.', badge: 'orange' },
                        { icon: '🛍️', title: 'Chọn mã & mua ngay', desc: 'Bấm vào mã phù hợp (mã có nhãn “Đề xuất” là tốt nhất) — link mua hàng đã áp sẵn voucher sẽ tự mở ra.', badge: 'emerald' },
                    ]" :key="i" class="card-glass rounded-2xl p-6 flex flex-col items-center text-center">
                        <span class="step-badge px-2.5 py-1 text-xs mb-4" :class="`step-badge--${step.badge}`">BƯỚC {{ i + 1 }}</span>
                        <div class="w-16 h-16 rounded-2xl bg-[var(--color-peach-soft)] flex items-center justify-center text-3xl mb-4">{{ step.icon }}</div>
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
                        class="card-glass rounded-2xl overflow-hidden"
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
        <section class="py-16 px-4 bg-gradient-to-br from-[var(--color-accent)] to-[var(--color-accent-deep)]">
            <div class="max-w-xl mx-auto text-center">
                <h2 class="text-2xl md:text-3xl font-extrabold text-white mb-4">Sẵn sàng tiết kiệm tiền?</h2>
                <p class="text-white/80 mb-8">Hơn 1.2 triệu mã đã được tạo. Tham gia ngay hôm nay.</p>
                <button @click="focusVoucherTool"
                    class="bg-white text-[var(--color-accent)] font-bold px-8 py-4 rounded-2xl hover:shadow-xl transition">
                    Lấy link ngay — Miễn phí
                </button>
            </div>
        </section>
    </AppLayout>
</template>
