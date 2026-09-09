<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { useLocalStorage } from '@vueuse/core'
import AppLayout from '@/Layouts/AppLayout.vue'
import CouponTicket from '@/Components/CouponTicket.vue'
import RestockSchedule from '@/Components/RestockSchedule.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps({
    vouchers: { type: Array, default: () => [] },
    voucherResult: { type: Object, default: null },
    canUseVoucherTool: { type: Boolean, default: true },
    // Khi admin đã bật chuyển hướng qua comment Facebook kèm chế độ tự chuyển hướng: ẩn luôn
    // nút "Mua ngay", đưa khách thẳng tới comment ngay sau khi dán link. Mỗi sản phẩm chỉ sinh
    // 1 comment thay vì mỗi lượt bấm lại thêm 1 cái.
    autoRedirect: { type: Boolean, default: false },
    // Cú bấm "Mua ngay" sẽ mở COMMENT TRÊN FACEBOOK chứ không phải thẳng Shopee — khách phải
    // bấm tiếp link trong comment đó mới về Shopee. Bắt buộc nói trước, nếu không khách bấm
    // xong thấy Facebook hiện ra sẽ tưởng bị lỗi hoặc bị lừa rồi thoát.
    viaFacebookComment: { type: Boolean, default: false },
    // 'reel' = link nằm trong PHẦN MÔ TẢ của reel; 'comment' = link nằm trong BÌNH LUẬN dưới
    // bài viết. Chỉ đúng một chỗ có link, chỉ sai chỗ là khách loay hoay không thấy rồi thoát.
    facebookMode: { type: String, default: 'comment' },
})

// Nơi khách phải bấm sau khi Facebook mở ra — dùng lại ở nhiều câu hướng dẫn nên gom một chỗ.
const linkLocation = computed(() => props.facebookMode === 'reel'
    ? 'link trong phần mô tả của reel'
    : 'link trong bình luận')

const toast = useToast()

// --- tietkiemvi.com: công cụ lấy link voucher công khai, không cần đăng nhập ---
// Nguồn cấp mã (kieushopee) trả về ĐÚNG MỘT link đã áp sẵn mã cho mỗi sản phẩm, nên ở đây
// chỉ có một nút "Mua ngay" — không còn danh sách mã theo từng nền tảng để khách phải chọn.
// Server chỉ trả về `voucher_ref` (token mờ); URL affiliate thật được giải mã lại ở
// /voucher/shorten khi khách thực sự bấm — xem ShopeeVoucherController::maskVoucherLink().

const voucherUrl = ref('')
const voucherUrlInput = ref(null)
const voucherResultEl = ref(null)
const resolving = ref(false)
const voucherError = ref(null)
const history = useLocalStorage('sv_history', [])

// Link đã quét xong gần nhất. So với nội dung đang có trong ô để biết có gì mới cần quét hay
// không — dùng chính chuỗi khách nhập chứ không phải canonical_url của server, vì server đã bung
// link ngắn thành link đầy đủ nên hai bên không bao giờ khớp.
const resolvedUrl = ref(null)

const alreadyResolved = computed(
    () => resolvedUrl.value !== null && voucherUrl.value.trim() === resolvedUrl.value,
)

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
    // Chặn gửi trùng ở ngay đây, không chỉ dựa vào :disabled của nút: Enter và sự kiện dán đều
    // gọi thẳng hàm này mà không đi qua nút. Trong log hoạt động đã thấy khách dán/bấm lặp lại
    // cách nhau 2-3 giây, và mỗi lượt trượt cache của nguồn ganma là một job ~20 giây.
    if (resolving.value) return
    resolving.value = true
    voucherError.value = null
    // Xoá link đã lấy của lần quét trước: nút kết quả dùng chung key 'result', còn các dòng
    // lịch sử đánh key theo chỉ số nên bị dịch đi khi có mục mới chèn lên đầu — không xoá là
    // khách bấm phải comment của sản phẩm khác.
    readyLinks.value = {}

    router.post('/voucher/resolve', { url: voucherUrl.value }, {
        preserveScroll: true,
        onSuccess: () => {
            resolving.value = false
            // Ghi lại link vừa quét xong để khoá nút. Chỉ đặt ở onSuccess: quét lỗi thì phải cho
            // khách bấm thử lại, không được khoá.
            resolvedUrl.value = voucherUrl.value.trim()
            const result = props.voucherResult
            if (result?.voucher_ref) {
                history.value = [
                    {
                        product_name: result.product?.product_name || null,
                        product_image: result.product?.product_image || null,
                        created_at: new Date().toISOString(),
                        // Lưu token mờ để "mua lại" sau này chỉ cần bấm nút, không cần hiện đường
                        // dẫn thô cho khách. ref do server phát ra (xem maskVoucherLink) và sống
                        // 7 ngày trong cache — hết hạn thì /voucher/shorten trả 422 và báo lỗi.
                        ref: result.voucher_ref,
                    },
                    ...history.value,
                ].slice(0, 5)
            }
            if (props.autoRedirect && result?.voucher_ref) {
                goStraightToVoucher()

                return
            }
            // Cuộn thẳng tới khu vực chọn mã ngay khi có kết quả — khách không cần tự kéo xuống.
            nextTick(() => voucherResultEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
        },
        onError: (errors) => {
            voucherError.value = errors.voucher_url || 'Có lỗi xảy ra, vui lòng thử lại.'
            toast.error(voucherError.value)
        },
        // onFinish chạy trong MỌI trường hợp, kể cả request đứt giữa đường (mạng yếu, khách
        // đang trong webview Facebook) — nơi onSuccess/onError đều không được gọi. Bỏ nút đi
        // rồi thì `resolving` còn khoá cả ô nhập, nên kẹt cờ này nghĩa là khách không sửa
        // được link mà cũng không thử lại được, phải tải lại trang.
        onFinish: () => {
            resolving.value = false
        },
    })
}

const shorteningKey = ref(null)
const autoRedirecting = ref(false)

// Nhãn nút phải nói đúng nơi nó dẫn tới. "Mua ngay" mà mở ra Facebook là khách tưởng lỗi.
const ctaLabel = computed(() => {
    if (shorteningKey.value === 'result') return props.viaFacebookComment ? 'Đang lấy mã...' : 'Đang mở...'
    return props.viaFacebookComment ? 'Lấy mã qua Facebook' : 'Mua ngay (đã áp mã)'
})

// Link comment Facebook đã lấy xong, theo từng nút (key -> URL). Khi đã có, nút bấm được thay
// bằng THẺ <a> THẬT — đây là điểm mấu chốt để mở được app Facebook:
//  • iOS chỉ kích hoạt universal link khi khách CHẠM TRỰC TIẾP vào anchor. Điều hướng bằng JS
//    (window.open rồi gán location.href) luôn bị Safari giữ lại trong trình duyệt.
//  • Android cũng ưu tiên anchor thật, và anchor cho phép bọc intent:// (xem facebookAppLink).
// Đổi lại khách phải chạm 2 nhịp: chạm để lấy mã → chạm để sang Facebook. Không gộp được vì
// giữa hai nhịp có request đăng comment, xong request là mất "user gesture".
const readyLinks = ref({})

const ua = typeof navigator !== 'undefined' ? navigator.userAgent : ''
const isAndroid = /Android/i.test(ua)
// Đang ở trong webview của Facebook/Instagram/Zalo thì intent:// không mở được gì cả — giữ
// nguyên URL https để webview tự xử lý.
const isInAppBrowser = /FBAN|FBAV|FB_IAB|Instagram|Zalo|Line\//i.test(ua)

// Đăng comment có thể thất bại (token lỗi, Facebook sập...) — khi đó server trả thẳng
// /go/{code} về Shopee thay vì link comment. Nhãn nút phải đổi theo, nếu không khách bấm
// "Mở Facebook ngay" mà lại ra Shopee thì tưởng hỏng.
function isFacebookLink(url) {
    return typeof url === 'string' && url.startsWith('https://www.facebook.com/')
}

/**
 * Android: bọc URL Facebook thành intent:// trỏ thẳng vào package com.facebook.katana. Cần
 * bước này vì Chrome chỉ tự mở app với App Link đã verify qua assetlinks.json, mà facebook.com
 * không nằm trong nhóm đó — link https thường vì vậy luôn ở lại trình duyệt.
 * browser_fallback_url giữ nguyên đường web cho máy chưa cài app.
 *
 * Khác hẳn vụ intent:// gây 502 trước đây (xem ShortLinkController::redirect): lần đó nó nằm ở
 * Location header phía server nên bị proxy/CDN xử lý, còn ở đây nó chỉ là href trong trình
 * duyệt của khách, không đi qua hạ tầng nào.
 *
 * iOS không có cơ chế tương đương — fb:// không trỏ được tới đúng comment (đã test, xem
 * ShortLinkController::commentUrl) nên để nguyên https và trông cậy vào universal link.
 */
function facebookAppLink(url) {
    if (!url || !isAndroid || isInAppBrowser) return url
    if (!isFacebookLink(url)) return url

    return `intent://${url.slice('https://'.length)}#Intent;scheme=https;package=com.facebook.katana;S.browser_fallback_url=${encodeURIComponent(url)};end`
}

// Đổi voucher_ref (token mờ) lấy short-link thật. Dùng chung cho mọi đường: bấm mở, tự chuyển
// hướng, và copy link.
async function fetchVoucherUrl(entry, productName = null, productImage = null) {
    const { data } = await axios.post('/voucher/shorten', {
        ref: entry.ref,
        product_name: productName ?? props.voucherResult?.product?.product_name ?? null,
        product_image: productImage ?? props.voucherResult?.product?.product_image ?? null,
    })

    return data.short_url
}

/**
 * Chế độ tự chuyển hướng: khách dán link xong là đi thẳng tới đích, không bấm nút nào nữa.
 * Điều hướng ngay trên tab hiện tại thay vì mở tab mới — hàm này chạy sau khi request resolve
 * trả về nên đã mất "user gesture", window.open() lúc này chắc chắn bị trình duyệt chặn.
 */
async function goStraightToVoucher() {
    autoRedirecting.value = true

    try {
        const url = await fetchVoucherUrl({ ref: props.voucherResult.voucher_ref })

        // Chế độ Facebook: dừng ở đây và hiện anchor thay vì tự điều hướng — window.location
        // sang facebook.com chỉ mở trình duyệt, không bật được app (xem readyLinks).
        if (props.viaFacebookComment) {
            readyLinks.value.result = url
            autoRedirecting.value = false

            return
        }

        window.location.href = url
    } catch (e) {
        autoRedirecting.value = false
        toast.error('Không thể tạo link, vui lòng thử lại.')
    }
}

// key chỉ để biết nút nào đang quay (nút kết quả hay một dòng trong lịch sử) — mỗi lần
// chỉ cho bấm một nút, vì cả hai đều dẫn tới cùng một hành động điều hướng.
async function openVoucherLink(entry, productName = null, productImage = null) {
    if (!entry?.ref || shorteningKey.value) return

    shorteningKey.value = entry.key

    // Chế độ Facebook: chỉ lấy link rồi hiện anchor, tuyệt đối không window.open/location.href
    // — điều hướng bằng JS là lý do app Facebook không bao giờ được bật lên (xem readyLinks).
    if (props.viaFacebookComment) {
        try {
            readyLinks.value[entry.key] = await fetchVoucherUrl(entry, productName, productImage)
        } catch (e) {
            toast.error('Không thể tạo link, vui lòng thử lại.')
        } finally {
            shorteningKey.value = null
        }

        return
    }

    // Mở tab trắng NGAY trong lúc click (đồng bộ) để trình duyệt không chặn popup —
    // nếu đợi axios xong mới gọi window.open() thì đã mất "user gesture", dễ bị chặn.
    const newTab = window.open('', '_blank')
    // Ghi tạm 1 trang loading vào tab đó — bước tạo link có thể mất vài giây (theo dõi
    // redirect chuỗi + có thể đăng comment Facebook), tab trắng trơn trong lúc chờ dễ khiến
    // khách tưởng bị treo rồi đóng tab/bấm lại.
    newTab?.document.write('<!DOCTYPE html><html lang="vi"><head><meta charset="utf-8"><title>Đang tạo liên kết...</title><style>body{display:flex;align-items:center;justify-content:center;height:100vh;margin:0;font-family:system-ui,sans-serif;color:#666;background:#fafafa}</style></head><body>Đang tạo liên kết, vui lòng đợi giây lát...</body></html>')

    try {
        const url = await fetchVoucherUrl(entry, productName, productImage)
        if (newTab) {
            newTab.location.href = url
        } else {
            // Popup bị chặn — điều hướng ngay tab hiện tại thay vì bỏ cuộc.
            window.location.href = url
        }
    } catch (e) {
        newTab?.close()
        toast.error('Không thể tạo link, vui lòng thử lại.')
    } finally {
        shorteningKey.value = null
    }
}

const copyingKey = ref(null)

// Lấy short-link /go/{code} (cùng link được dùng khi bấm mở) rồi copy vào clipboard —
// để người dùng dán thẳng lên Facebook/Zalo mà không cần tự mở link ra rồi copy từ URL bar.
async function copyVoucherLink(entry) {
    if (!entry?.ref || copyingKey.value) return

    copyingKey.value = entry.key
    try {
        await navigator.clipboard.writeText(await fetchVoucherUrl(entry))
        toast.success('Đã sao chép link!')
    } catch (e) {
        toast.error('Không thể sao chép link, vui lòng thử lại.')
    } finally {
        copyingKey.value = null
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
    { q: 'Công cụ này hoạt động như thế nào?', a: 'Bạn dán link sản phẩm Shopee vào ô ở đầu trang — hệ thống tự tìm mã giảm giá đang áp dụng cho sản phẩm đó và trả về một link đã gắn sẵn mã, không phải nhập mã thủ công.' },
    { q: 'Vì sao bấm nút lại mở ra Facebook?', a: 'Vì đây là mã dành riêng cho người mua đến từ Facebook — Shopee chỉ áp mã khi bạn bấm vào link nằm trên Facebook. Quy trình là: bấm "Lấy mã qua Facebook" → bấm tiếp "Mở Facebook ngay" (ứng dụng Facebook sẽ mở ra) → bấm link hiện ở đó → về Shopee với mã đã được áp sẵn. Bỏ qua bước này thì mã sẽ không có hiệu lực.' },
    { q: 'Mấy giờ thì có mã mới (back mã)?', a: 'Mã YouTube được nạp lại lượt vào 0h, 9h, 12h và 18h. Mã IG – FB nạp lại vào 0h, 9h, 15h và 20h (giờ Việt Nam). Mã có số lượng giới hạn nên thường hết rất nhanh — nếu Shopee báo hết lượt, bạn quay lại đúng khung giờ trên để lấy mã mới.' },
    { q: 'Tôi có được hoàn tiền không?', a: 'Công cụ lấy mã giảm giá không tạo hoàn tiền — mục đích là giúp bạn được giảm giá ngay khi thanh toán trên Shopee.' },
    { q: 'Có mất phí không?', a: 'Hoàn toàn miễn phí, bạn không mất phí gì khi dùng công cụ lấy mã.' },
    { q: 'Hỗ trợ những sàn nào?', a: 'Ô dán link ở đầu trang hiện chỉ hỗ trợ Shopee. Riêng mục "Mã giảm giá gợi ý" bên dưới có thêm mã cho Lazada, TikTok Shop và Tiki.' },
]
const openFaq = ref(null)

// Khung dán link dính lên đầu trang khi cuộn (sticky) để khách lúc nào cũng dán được link.
// Lúc đã dính thì thu gọn tiêu đề lại, nếu không khung chiếm gần hết màn hình điện thoại.
// Đo bằng vị trí thật của khung chứ không bằng scrollY: phía trên nó còn banner khung giờ
// back mã, lấy mốc scrollY cố định sẽ thu gọn sớm và làm nội dung giật một nhịp.
const HEADER_HEIGHT = 64 // AppLayout: header sticky h-16
const stickyEl = ref(null)
const stuck = ref(false)
let scrollTicking = false

function measureStuck() {
    scrollTicking = false
    const top = stickyEl.value?.getBoundingClientRect().top
    stuck.value = top !== undefined && top <= HEADER_HEIGHT + 0.5
}

function onScroll() {
    if (scrollTicking) return
    scrollTicking = true
    requestAnimationFrame(measureStuck)
}

onMounted(() => {
    window.addEventListener('scroll', onScroll, { passive: true })
    window.addEventListener('resize', onScroll, { passive: true })
    measureStuck()
})
onUnmounted(() => {
    window.removeEventListener('scroll', onScroll)
    window.removeEventListener('resize', onScroll)
})
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
                <!-- Khung giờ back mã: đặt trên cùng để khách vừa vào trang là biết ngay
                     lúc nào nguồn cấp mã nạp lại lượt, trước cả ô dán link. -->
                <RestockSchedule class="mb-4" />

                <!-- top-16 = chiều cao header sticky của AppLayout (h-16). Nền đặc + blur chỉ
                     bật khi đã dính, để lúc chưa cuộn khung vẫn phẳng với nền trang.
                     -mx-4 px-4 kéo nền ra sát mép để nội dung cuộn phía dưới không lòi ra hai bên. -->
                <div
                    ref="stickyEl"
                    class="sticky top-16 z-30 -mx-4 px-4 pt-2 pb-3 transition-shadow duration-200"
                    :class="stuck ? 'bg-[var(--color-bg)]/95 backdrop-blur-md shadow-[0_10px_24px_rgba(0,0,0,.12)]' : ''"
                >
                    <div v-if="canUseVoucherTool" class="rounded-3xl bg-gradient-to-br from-[var(--color-peach)] via-[var(--color-peach-soft)] to-[var(--color-green-soft)] border border-[var(--color-line)] transition-all duration-200" :class="stuck ? 'p-4' : 'p-6 md:p-8'">
                        <!-- Giữ h1 trong DOM (chỉ thu chiều cao) để không mất thẻ h1 của trang. -->
                        <div class="overflow-hidden transition-all duration-200" :class="stuck ? 'max-h-0 opacity-0' : 'max-h-40 opacity-100 mb-4'">
                            <h1 class="text-xl md:text-2xl font-extrabold text-[var(--color-ink)] mb-1">
                                Dán link sản phẩm Shopee để lấy mã giảm giá
                            </h1>
                            <p class="text-sm text-[var(--color-muted)]">Nhận ngay link đã áp sẵn mã giảm giá — không cần nhập mã, miễn phí.</p>
                        </div>

                        <div class="flex flex-col md:flex-row gap-3">
                            <div class="relative flex-1">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[var(--color-muted)]">🔗</span>
                                <input
                                    ref="voucherUrlInput"
                                    v-model="voucherUrl"
                                    type="url"
                                    enterkeyhint="search"
                                    @keydown.enter="resolveVoucher"
                                    @paste="onVoucherUrlPaste"
                                    placeholder="Dán link Shopee (shopee.vn hoặc s.shopee.vn)..."
                                    class="w-full pl-10 pr-20 border border-[var(--color-line)] rounded-xl text-sm bg-[var(--color-surface)] focus:outline-none focus:border-[var(--color-accent)] focus:ring-2 focus:ring-[var(--color-peach)] transition-all duration-200"
                                    :class="stuck ? 'py-3' : 'py-4'"
                                />
                                <button
                                    @click="pasteVoucherUrl"
                                    type="button"
                                    class="absolute right-1.5 top-1/2 -translate-y-1/2 text-xs font-bold text-[var(--color-accent)] bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] border border-[var(--color-accent)]/30 rounded-lg px-3 py-1.5 transition"
                                >Dán</button>
                            </div>
                            <!-- Dán link là tự quét luôn, nên nút này gần như không còn việc gì:
                                 khoá lại khi ĐANG quét và cả khi link trong ô ĐÃ quét xong, để khách
                                 không bấm thêm một lượt vô ích (mỗi lượt trượt cache là một vòng gọi
                                 nguồn mã, riêng ganma mất ~20 giây). Sửa lại link thì nút tự mở. -->
                            <button
                                @click="resolveVoucher"
                                :disabled="resolving || !voucherUrl.trim() || alreadyResolved"
                                class="btn-fire rounded-xl flex items-center justify-center gap-2 whitespace-nowrap transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                                :class="stuck ? 'px-6 py-3' : 'px-8 py-4'"
                            >
                                <svg v-if="resolving" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" stroke-dasharray="30 70" />
                                </svg>
                                <span v-else>{{ alreadyResolved ? '✓' : '🔍' }}</span>
                                {{ resolving ? 'Đang tìm mã...' : (alreadyResolved ? 'Đã tìm xong' : 'Tìm mã ngay') }}
                            </button>
                        </div>
                        <p v-if="voucherError" class="text-red-500 text-sm mt-2">{{ voucherError }}</p>
                    </div>

                    <!-- Máy tính (không phải admin): ẩn khung tìm mã, chỉ hiện thông báo dùng điện thoại -->
                    <div v-else class="rounded-3xl p-6 md:p-8 bg-gradient-to-br from-[var(--color-peach)] via-[var(--color-peach-soft)] to-[var(--color-green-soft)] border border-[var(--color-line)] text-center">
                        <p class="text-2xl mb-2">📱</p>
                        <p class="font-semibold text-[var(--color-ink)]">Chức năng lấy mã chỉ dùng được trên điện thoại.</p>
                        <p class="text-sm text-[var(--color-muted)] mt-1">Vui lòng mở tietkiemvi.com bằng trình duyệt trên điện thoại.</p>
                    </div>
                </div>

                <!-- Kết quả -->
                <div v-if="canUseVoucherTool && voucherResult" ref="voucherResultEl" class="mt-4 card-glass rounded-2xl p-5 scroll-mt-48">
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
                    <p v-else class="text-sm text-[var(--color-muted)] mb-5">Không lấy được thông tin sản phẩm, nhưng bạn vẫn có thể dùng link bên dưới.</p>

                    <!-- Chế độ tự chuyển hướng: khách không bấm gì cả, chỉ báo đang đi. -->
                    <div v-if="autoRedirecting" class="flex items-center gap-3 mb-4 mt-3 px-4 py-3 rounded-xl bg-[var(--color-peach-soft)] text-[var(--color-ink)] text-sm font-semibold">
                        <span class="w-4 h-4 rounded-full border-2 border-[var(--color-accent)] border-t-transparent animate-spin flex-none"></span>
                        <span v-if="viaFacebookComment">Đang mở Facebook... Bấm vào <b>{{ linkLocation }}</b> để nhận mã nhé.</span>
                        <span v-else>Đang chuyển tới mã giảm giá, vui lòng đợi giây lát...</span>
                    </div>

                    <!-- Nói trước khi khách bấm: nút này mở Facebook, không phải mở thẳng Shopee. -->
                    <div v-else-if="viaFacebookComment && voucherResult.voucher_ref" class="mb-3 mt-3 rounded-xl border border-[#1877F2]/30 bg-[#1877F2]/5 px-4 py-3">
                        <p class="text-sm font-bold text-[var(--color-ink)] mb-2">Mã này nhận qua Facebook — làm 2 bước:</p>
                        <ol class="text-xs text-[var(--color-ink)] leading-relaxed space-y-1 list-decimal list-inside">
                            <li v-if="!isFacebookLink(readyLinks.result)">Bấm nút bên dưới → hệ thống lấy mã và hiện nút <b>Mở Facebook ngay</b>.</li>
                            <li v-else-if="facebookMode === 'reel'">Bấm <b>Mở Facebook ngay</b> → <b>ứng dụng Facebook mở ra</b> tại một reel.</li>
                            <li v-else>Bấm <b>Mở Facebook ngay</b> → <b>Facebook sẽ mở ra</b> tại một bình luận.</li>
                            <li>Bấm tiếp vào <b>{{ linkLocation }}</b> → về Shopee, mã đã áp sẵn.</li>
                        </ol>
                        <p class="text-xs text-[var(--color-muted)] mt-2">Phải đi qua Facebook thì mã mới có hiệu lực — đừng đóng giữa chừng nhé.</p>
                    </div>

                    <!-- Mã đã được áp sẵn trong link nên khách không phải chọn/nhập gì, chỉ bấm mở. -->
                    <div v-if="!autoRedirecting && voucherResult.voucher_ref" class="flex items-stretch gap-1.5 mb-4">
                        <!-- Đã lấy được link comment: chuyển hẳn sang thẻ <a>. Cú chạm vào anchor
                             thật là điều kiện bắt buộc để iOS bật app Facebook; không dùng
                             target="_blank" vì tab mới cũng làm hỏng universal link. -->
                        <a
                            v-if="readyLinks.result"
                            :href="facebookAppLink(readyLinks.result)"
                            class="btn-fire flex-1 min-w-0 px-6 py-4 rounded-xl flex items-center justify-center gap-2 text-base animate-pulse-ring no-underline"
                        >
                            <span>{{ isFacebookLink(readyLinks.result) ? '👉' : '🛒' }}</span>
                            <span class="truncate">{{ isFacebookLink(readyLinks.result) ? 'Mở Facebook ngay' : 'Mua ngay (đã áp mã)' }}</span>
                        </a>
                        <button
                            v-else
                            @click="openVoucherLink({ key: 'result', ref: voucherResult.voucher_ref })"
                            :disabled="shorteningKey === 'result'"
                            class="btn-fire flex-1 min-w-0 px-6 py-4 rounded-xl flex items-center justify-center gap-2 text-base animate-pulse-ring disabled:opacity-60"
                        >
                            <span>{{ viaFacebookComment ? '👉' : '🛒' }}</span>
                            <span class="truncate">{{ ctaLabel }}</span>
                        </button>
                        <button
                            @click="copyVoucherLink({ key: 'result', ref: voucherResult.voucher_ref })"
                            :disabled="copyingKey === 'result'"
                            title="Sao chép link để dán lên Facebook/Zalo"
                            class="flex-none w-12 rounded-xl flex items-center justify-center transition-all bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] text-[var(--color-ink)] disabled:opacity-60"
                        >
                            <svg v-if="copyingKey !== 'result'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 animate-spin"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                        </button>
                    </div>
                    <!-- v-if tường minh chứ không v-else: đang tự chuyển hướng thì đã có spinner
                         ở trên rồi, v-else sẽ hiện thêm dòng "chưa lấy được mã" gây hoang mang. -->
                    <p v-if="!autoRedirecting && !voucherResult.voucher_ref" class="text-sm text-[var(--color-muted)] mb-4">Chưa lấy được mã cho sản phẩm này — có thể do lỗi kết nối tạm thời, thử dán lại link nhé.</p>

                    <div class="flex items-start gap-2 bg-[var(--color-peach-soft)] border border-[var(--color-accent)]/25 rounded-xl px-3 py-2.5">
                        <span class="text-sm leading-none">⚠️</span>
                        <p v-if="viaFacebookComment" class="text-xs text-[var(--color-accent-deep)] leading-relaxed">Nhớ bấm <b>{{ linkLocation }}</b> thì mã mới được áp — bấm nhầm chỗ khác là mua không có giảm giá. Sang Shopee rồi thì đặt hàng bình thường, không cần nhập mã. Nếu Shopee báo mã hết lượt, thử lại sau ít phút nhé.</p>
                        <p v-else class="text-xs text-[var(--color-accent-deep)] leading-relaxed">Mã đã gắn sẵn trong link — bấm "Mua ngay" rồi đặt hàng như bình thường, không cần nhập mã. Nếu Shopee báo mã hết lượt, thử lại sau ít phút nhé.</p>
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
                            <!-- Mục cũ (trước khi chuyển sang một mã duy nhất) không có h.ref nên
                                 không hiện nút — chúng tự trôi khỏi danh sách sau 5 lần quét mới. -->
                            <a
                                v-if="h.ref && readyLinks[`hist-${hi}`]"
                                :href="facebookAppLink(readyLinks[`hist-${hi}`])"
                                class="btn-fire px-4 py-2 rounded-lg text-xs inline-flex items-center gap-1.5 no-underline"
                            >
                                <span>{{ isFacebookLink(readyLinks[`hist-${hi}`]) ? '👉' : '🛒' }}</span>
                                {{ isFacebookLink(readyLinks[`hist-${hi}`]) ? 'Mở Facebook ngay' : 'Mua ngay' }}
                            </a>
                            <button
                                v-else-if="h.ref"
                                @click="openVoucherLink({ key: `hist-${hi}`, ref: h.ref }, h.product_name, h.product_image)"
                                :disabled="shorteningKey === `hist-${hi}`"
                                class="btn-fire px-4 py-2 rounded-lg text-xs flex items-center gap-1.5 disabled:opacity-60"
                            >
                                <span>{{ viaFacebookComment ? '👉' : '🛒' }}</span>
                                {{ shorteningKey === `hist-${hi}` ? (viaFacebookComment ? 'Đang lấy mã...' : 'Đang mở...') : (viaFacebookComment ? 'Lấy mã qua Facebook' : 'Mua ngay') }}
                            </button>
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
