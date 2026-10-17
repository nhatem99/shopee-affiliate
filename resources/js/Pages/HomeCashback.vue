<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { useLocalStorage } from '@vueuse/core'
import AppLayout from '@/Layouts/AppLayout.vue'
import CashbackExplainer from '@/Components/CashbackExplainer.vue'
import CashbackLeaderboard from '@/Components/CashbackLeaderboard.vue'
import MembershipTiers from '@/Components/MembershipTiers.vue'
import DailyCheckIn from '@/Components/DailyCheckIn.vue'
import { useToast } from '@/composables/useToast'
import { useFestive } from '@/composables/useFestive'
import { useCashback } from '@/composables/useCashback'
import { useAuthStore } from '@/Stores/useAuthStore'

// Trang chủ khi admin TẮT "Lấy mã giảm giá trên web" (HomeController): chỉ làm hoàn tiền, bố cục
// theo dealngon. Dán link Shopee → CashbackLinkController (prop cashbackResult). Bật lại công tắc
// thì trang chủ về Pages/Home.vue (lấy mã) như cũ.
//
// Khác dealngon: khách vãng lai VẪN nhận link ngay (không bắt đăng nhập) — chỉ là link không mang
// mã khách nên đơn không được hoàn tiền.
const props = defineProps({
    // Bảng vàng hoàn tiền tháng này — server chỉ gửi khi chương trình đang bật (null khi tắt).
    leaderboard: { type: Object, default: null },
    // Bảng hạng thành viên + tiến độ của chính khách này — null khi admin tắt chương trình hạng
    // hoặc khi tỉ lệ hoàn tiền đang là 0 (xem MembershipTierService::enabled).
    membershipTiers: { type: Object, default: null },
    // Thẻ Điểm danh nhận quà — null khi admin tắt chương trình (xem DailyCheckInService).
    dailyCheckIn: { type: Object, default: null },
    // Link Shopee vừa dán: { product, link, logged_in, cashback_rate, estimate } — xem CashbackLinkService.
    cashbackResult: { type: Object, default: null },
})

const toast = useToast()
const { celebrate } = useFestive()
const auth = useAuthStore()
const { cashbackRate, cashbackOn: cashbackProgramOn, minWithdrawal, joinHref, joinLabel, vnd: money, dealngonUrl, dealngonHost } = useCashback()

// Hoàn tiền có được HỨA trên trang này không. Đang mời sang dealngon (DealngonInvite) thì không:
// hoàn tiền đã chuyển sang đó, trang này chỉ còn là chỗ dán link lấy link mua — không tiền dự kiến,
// không mời đăng nhập/đăng ký để được hoàn, không bảng vàng/hạng. Tắt công tắc là về như cũ.
const cashbackOn = computed(() => cashbackProgramOn.value && !dealngonUrl.value)

const linkUrl = ref('')
const linkUrlInput = ref(null)
const resultEl = ref(null)
const resultCtaEl = ref(null)
const resolving = ref(false)
const linkError = ref(null)

// Khách vãng lai bấm "Đăng nhập" ở thẻ kết quả: nhớ link để đăng nhập xong quay về trang chủ là
// link hoàn tiền tự tạo lại, khỏi phải đi copy lần nữa.
const PENDING_KEY = 'sv_pending_link'

function loginKeepingLink() {
    try {
        if (linkUrl.value.trim()) sessionStorage.setItem(PENDING_KEY, linkUrl.value.trim())
    } catch (e) {
        // Không lưu được thì đăng nhập xong khách dán lại link là xong.
    }
    router.visit('/login')
}

// --- Lịch sử: chỉ nằm trong trình duyệt của khách ---
// Lưu link khách đã dán; bấm "Mở lại" là tạo link MỚI chứ không dùng lại link cũ — link hoàn tiền
// gắn với tài khoản đang đăng nhập LÚC TẠO. Khoá riêng, không dùng chung 'sv_history' của trang
// lấy mã (mục ở đó là ref mã, không mở lại được ở đây).
const HISTORY_KEY = 'sv_link_history'
const HISTORY_LIMIT = 20
const history = useLocalStorage(HISTORY_KEY, [])
history.value = (Array.isArray(history.value) ? history.value : [])
    .filter((h) => typeof h?.url === 'string' && !Number.isNaN(Date.parse(h?.created_at)))

function rememberInHistory(url, result) {
    const item = {
        url,
        product_name: result?.product?.product_name || null,
        product_image: result?.product?.product_image || null,
        created_at: new Date().toISOString(),
    }
    // Dán lại cùng một link thì đưa mục cũ lên đầu thay vì nhân đôi.
    history.value = [item, ...history.value.filter((h) => h.url !== url)].slice(0, HISTORY_LIMIT)
}

function clearHistory() {
    if (!window.confirm('Xoá toàn bộ lịch sử dán link trên máy này?')) return
    history.value = []
}

function timeAgo(iso) {
    const seconds = Math.max(0, (Date.now() - Date.parse(iso)) / 1000)
    if (seconds < 60) return 'vừa xong'
    if (seconds < 3600) return `${Math.floor(seconds / 60)} phút trước`
    if (seconds < 86400) return `${Math.floor(seconds / 3600)} giờ trước`
    if (seconds < 172800) return 'hôm qua'
    return new Date(iso).toLocaleDateString('vi-VN')
}

function resolveAgain(h) {
    linkUrl.value = h.url
    resolveLink()
}

// Tab dưới khối kết quả: "Bảng vàng" (chỉ khi chương trình đang bật) | "Lịch sử".
const hasLeaderboard = computed(() => cashbackOn.value && !!props.leaderboard)
const tabs = computed(() => [
    ...(hasLeaderboard.value ? [{ key: 'leaderboard', label: '🏆 Bảng vàng' }] : []),
    { key: 'history', label: '🕘 Lịch sử' },
])
const activeTab = ref(hasLeaderboard.value && !history.value.length ? 'leaderboard' : 'history')

// Link đã xử lý xong gần nhất — so với nội dung ô để khoá nút khi không có gì mới.
const resolvedUrl = ref(null)
const alreadyResolved = computed(
    () => resolvedUrl.value !== null && linkUrl.value.trim() === resolvedUrl.value,
)

// Moi link ra khỏi đoạn text vừa dán. App (và Zalo/Messenger khi chuyển tiếp) hay copy kèm lời dẫn
// kiểu "Xem sản phẩm ... https://s.shopee.vn/..." — gửi nguyên cả câu lên server là rớt validate.
function extractUrl(text) {
    return String(text || '').match(/https?:\/\/\S+/i)?.[0] ?? String(text || '').trim()
}

async function pasteLink() {
    try {
        let text = (await navigator.clipboard.readText()).trim()
        // Clipboard chỉ có kiểu URL (một số app iOS copy link bằng UIPasteboard.url, không kèm
        // plain text): readText() trả rỗng dù dán tay vẫn ra chữ. Đọc thêm text/uri-list.
        if (!text && navigator.clipboard.read) {
            for (const item of await navigator.clipboard.read()) {
                if (item.types.includes('text/uri-list')) {
                    text = (await (await item.getType('text/uri-list')).text()).trim()
                    break
                }
            }
        }
        // Không có gì để dán thì phải nói ra: im lặng là khách tưởng nút hỏng.
        if (!text) {
            toast.info('Bộ nhớ tạm đang trống — copy link sản phẩm Shopee rồi bấm Dán nhé.')

            return
        }
        linkUrl.value = extractUrl(text)
        resolveLink()
    } catch (e) {
        toast.error('Không thể đọc bộ nhớ tạm. Hãy nhấn giữ vào ô rồi chọn Dán.')
    }
}

// Dán link vào ô là tự xử lý luôn, không cần bấm nút.
function onLinkPaste(e) {
    const data = e.clipboardData || window.clipboardData
    const text = (data?.getData('text') || data?.getData('text/uri-list') || data?.getData('url') || '').trim()
    if (!text) return
    e.preventDefault()
    linkUrl.value = extractUrl(text)
    resolveLink()
}

// Giá trị của ô NGAY TRƯỚC lần đổi gần nhất — để onLinkInput biết đoạn nào vừa được chèn. Bắt
// bằng watcher sync (chạy trước handler @input): gán bằng code (nút Dán, sự kiện paste) không sinh
// sự kiện input, ghi trong handler thì mốc so sánh bị cũ.
let linkUrlBefore = ''
watch(linkUrl, (_, oldValue) => {
    linkUrlBefore = oldValue
}, { flush: 'sync' })

/**
 * Lưới hứng cho những đường dán KHÔNG đi qua sự kiện `paste` mà v-model vẫn nhận được chữ: gợi ý
 * clipboard trên thanh QuickType của iPhone (WebKit coi là gõ chữ), kéo-thả, tự điền. Chỉ nhận
 * ĐOẠN VỪA CHÈN: chèn vào ô đang có link cũ thì lấy link trong đoạn mới. Gõ tay từng ký tự thì
 * đoạn chèn không bao giờ thành link → không tự gửi.
 */
function onLinkInput(e) {
    const value = e.target.value
    const before = linkUrlBefore
    let head = 0
    while (head < before.length && head < value.length && before[head] === value[head]) head++
    let tail = 0
    while (tail < before.length - head && tail < value.length - head
        && before[before.length - 1 - tail] === value[value.length - 1 - tail]) tail++
    const inserted = value.slice(head, value.length - tail)
    const url = inserted.match(/https?:\/\/\S+/i)?.[0]
    if (!url) return
    linkUrl.value = url
    resolveLink()
}

// AppLayout: header sticky h-16. BottomNav chỉ có dưới md (md:hidden).
const HEADER_HEIGHT = 64
const BOTTOM_NAV_HEIGHT = 80

// Khung dán link dính dưới header khi cuộn. `stuck` chỉ để bật bóng đổ ở mép dưới.
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

/**
 * Cuộn cho khối kết quả hiện ngay dưới vùng dính (header + khung dán link), ưu tiên nút mua: đầu
 * kết quả vừa khít mà nút vẫn tụt dưới mép màn hình (hoặc dưới BottomNav) thì cuộn thêm. Đợi một
 * nhịp vẽ vì hàm chạy ngay sau khi Inertia thay props.
 */
function scrollToResult() {
    nextTick(() => requestAnimationFrame(() => {
        const result = resultEl.value
        if (!result) return
        const topGap = HEADER_HEIGHT + (stickyEl.value?.offsetHeight ?? 0) + 4
        let top = result.getBoundingClientRect().top + window.scrollY - topGap

        const cta = resultCtaEl.value
        if (cta) {
            const ctaBottom = cta.getBoundingClientRect().bottom + window.scrollY + 16
            const bottomNav = window.matchMedia('(min-width: 768px)').matches ? 0 : BOTTOM_NAV_HEIGHT
            const visibleHeight = window.innerHeight - bottomNav
            if (ctaBottom - top > visibleHeight) top = ctaBottom - visibleHeight
        }

        window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' })
    }))
}

function focusLinkInput() {
    linkUrlInput.value?.focus()
}

// Link đang xử lý (null khi rảnh) và số thứ tự lượt mới nhất. Mỗi lượt giữ số của mình và chỉ đụng
// vào trạng thái chung khi vẫn là lượt mới nhất — lượt cũ bị Inertia huỷ (khách dán link khác đè
// lên) vẫn được gọi onFinish, không có số thì nó tắt cờ `resolving` của lượt mới.
let resolvingUrl = null
let resolveSeq = 0
// Request bị iOS cắt ngầm khi Safari nằm nền không gọi callback nào — `resolving` kẹt true mãi.
// Lượt nào quá 90 giây chắc chắn đã chết, cho chạy lại.
let resolvingSince = 0
const RESOLVE_STALE_MS = 90_000

function resolveLink() {
    const url = linkUrl.value.trim()
    if (!url) return
    // Chặn gửi trùng ngay đây (Enter và sự kiện dán đều gọi thẳng hàm này). Chỉ chặn khi CÙNG
    // link: dán link KHÁC trong lúc chờ là ý khách đã đổi, cho đi luôn (router.post tự huỷ lượt cũ).
    if (resolving.value && url === resolvingUrl && Date.now() - resolvingSince < RESOLVE_STALE_MS) {
        toast.info('Đang xử lý link này rồi, đợi chút nhé...')

        return
    }

    const seq = ++resolveSeq
    resolvingUrl = url
    resolvingSince = Date.now()
    resolving.value = true
    linkError.value = null
    resolvedUrl.value = null

    router.post('/link-hoan-tien', { url }, {
        preserveScroll: true,
        onSuccess: () => {
            if (seq !== resolveSeq) return
            resolving.value = false
            resolvedUrl.value = url

            const r = props.cashbackResult
            if (r?.link) {
                rememberInHistory(url, r)
                celebrate()
            }

            scrollToResult()
        },
        onError: (errors) => {
            if (seq !== resolveSeq) return
            linkError.value = errors.link_url || errors.url || 'Có lỗi xảy ra, vui lòng thử lại.'
            toast.error(linkError.value)
        },
        // onFinish chạy cả khi request đứt giữa đường (mạng yếu, webview Facebook) — nơi
        // onSuccess/onError đều không được gọi. Kẹt cờ này là khoá luôn ô nhập.
        onFinish: () => {
            if (seq !== resolveSeq) return
            resolving.value = false
            resolvingUrl = null
        },
    })
}

/**
 * Trang được lấy lại từ bộ nhớ đệm quay-lui (bfcache) — khách bấm mua sang app Shopee rồi quay lại
 * bằng nút Back là Safari trả nguyên trang cũ. Request nào đang chạy lúc rời trang đã bị cắt mà
 * không gọi callback nào, nên cờ nào còn bật là khoá vĩnh viễn.
 */
function onPageShow(e) {
    if (!e.persisted) return
    resolveSeq++
    resolving.value = false
    resolvingUrl = null
}

const shopeeProduct = computed(() => props.cashbackResult?.product ?? null)

const copying = ref(false)

async function copyLink() {
    if (!props.cashbackResult?.link || copying.value) return
    copying.value = true
    try {
        await navigator.clipboard.writeText(props.cashbackResult.link)
        toast.success('Đã sao chép link!')
    } catch (e) {
        toast.error('Không thể sao chép link, vui lòng thử lại.')
    } finally {
        copying.value = false
    }
}

function vnd(n) {
    return Number(n || 0).toLocaleString('vi-VN') + 'đ'
}

const heroSteps = computed(() => cashbackOn.value ? ['Dán link', 'Bấm mua', 'Tiền về ví'] : ['Dán link', 'Bấm mua', 'Đặt hàng'])

const steps = computed(() => cashbackOn.value
    ? [
        { title: 'Đăng nhập', desc: 'Một lần là xong — dùng Google cũng được.' },
        { title: 'Copy link', desc: 'Trong app Shopee: mở sản phẩm → Chia sẻ → Sao chép liên kết.' },
        { title: 'Dán & bấm mua', desc: 'Dán vào ô, bấm Mua và đặt hàng ngay lượt đó.' },
        { title: 'Nhận tiền', desc: 'Đơn hoàn thành, tiền vào ví — rút về MoMo/ZaloPay.' },
    ]
    : [
        { title: 'Copy link', desc: 'Trong app Shopee: mở sản phẩm → Chia sẻ → Sao chép liên kết.' },
        { title: 'Dán link', desc: 'Dán vào ô ở đầu trang.' },
        { title: 'Bấm mua', desc: 'Bấm "Mua trên Shopee" để mở sản phẩm.' },
        { title: 'Đặt hàng', desc: 'Đặt hàng ngay trong lượt mở đó như bình thường.' },
    ])

const faqs = computed(() => [
    ...(cashbackOn.value ? [
        { q: 'Hoàn tiền là gì? Tiền ở đâu ra?', a: `Khi bạn mua qua link của tietkiemvi, Shopee trả cho tụi mình một khoản hoa hồng tiếp thị. Tụi mình chia lại ${cashbackRate.value}% khoản đó cho chính bạn — vào ví trên web, rút về MoMo/ZaloPay.` },
    ] : []),
    { q: 'Mua qua link có đắt hơn không?', a: 'Không. Giá, phí ship và mã giảm giá của Shopee/shop y hệt như bạn tự mở app mua — link chỉ để Shopee biết đơn đi qua tietkiemvi.' },
    ...(cashbackOn.value ? [
        { q: 'Vì sao phải đăng nhập trước khi dán link?', a: 'Link mua mang mã riêng của tài khoản bạn — nhờ nó mà đơn trong báo cáo Shopee quy về đúng bạn. Link tạo lúc chưa đăng nhập không mang mã của bạn: đơn đó không quy về ai được và sau này không cứu lại được.' },
        { q: 'Hoàn dự kiến là gì?', a: 'Là số tiền tạm tính cho 1 sản phẩm, theo hoa hồng Shopee đang trả cho sản phẩm đó ở giá hiện tại. Số thật tính theo báo cáo Shopee khi đơn hoàn thành — đổi theo giá lúc mua, voucher, số lượng.' },
        { q: 'Bao lâu thì tiền về ví?', a: 'Sau khi đơn chuyển sang Hoàn thành trên Shopee (qua hạn đổi trả) và tụi mình đối soát báo cáo — thường vài tuần. Theo dõi từng đơn ở mục Đơn hàng.' },
        { q: 'Rút tiền thế nào?', a: `Khai ví MoMo hoặc ZaloPay ở trang Tài khoản. Số dư đủ ${money(minWithdrawal.value)} là gửi yêu cầu rút được — bên mình duyệt rồi chuyển.` },
        { q: 'Mua rồi mà không thấy đơn?', a: 'Đơn mới đặt cần vài ngày mới có trong báo cáo Shopee. Nếu lâu vẫn không thấy: kiểm tra lúc dán link bạn đã đăng nhập chưa, có đặt ngay lượt mở đó không (bấm link của người khác sau đó thì đơn tính cho họ). Vẫn không ra thì nhắn hỗ trợ kèm mã đơn.' },
    ] : []),
    { q: 'Hỗ trợ những sàn nào?', a: 'Hiện chỉ Shopee. Dán link sản phẩm (link Chia sẻ trong app hoặc link trên web) vào ô ở đầu trang.' },
])
const openFaq = ref(null)

onMounted(() => {
    window.addEventListener('pageshow', onPageShow)
    window.addEventListener('scroll', onScroll, { passive: true })
    window.addEventListener('resize', onScroll, { passive: true })
    measureStuck()

    // Vừa đăng nhập xong sau khi bấm "Đăng nhập" ở kết quả trước: tạo lại link đang dang dở.
    if (auth.isLoggedIn && !props.cashbackResult) {
        let pending = null
        try {
            pending = sessionStorage.getItem(PENDING_KEY)
            sessionStorage.removeItem(PENDING_KEY)
        } catch (e) {
            pending = null
        }
        if (pending) {
            linkUrl.value = pending
            resolveLink()
        }
    }
})
onUnmounted(() => {
    window.removeEventListener('pageshow', onPageShow)
    window.removeEventListener('scroll', onScroll)
    window.removeEventListener('resize', onScroll)
})
</script>

<template>
    <Head>
        <title>{{ cashbackOn ? 'Mua Shopee Hoàn Tiền Về Ví | tietkiemvi.com' : 'Link Mua Shopee | tietkiemvi.com' }}</title>
        <meta
            name="description"
            :content="cashbackOn
                ? `Dán link sản phẩm Shopee, mua qua link của tietkiemvi và nhận lại ${cashbackRate}% hoa hồng của đơn vào ví — rút về MoMo, ZaloPay.`
                : 'Dán link sản phẩm Shopee để nhận link mua chính thức của Shopee.'"
        />
    </Head>
    <AppLayout>
        <section class="px-4 pt-4 md:pt-8 pb-10">
            <div class="max-w-3xl mx-auto">
                <!-- Khối đầu trang trên nền cam (màu thương hiệu tietkiemvi). -->
                <div
                    class="relative overflow-hidden rounded-3xl p-4 md:p-6 text-white bg-[linear-gradient(150deg,#FF7A45_0%,#F5511E_45%,#B8360D_100%)] shadow-[0_18px_40px_-14px_rgba(212,63,18,.6)]"
                >
                    <span aria-hidden="true" class="pointer-events-none absolute -right-12 -top-12 w-44 h-44 rounded-full bg-white/10"></span>
                    <span aria-hidden="true" class="pointer-events-none absolute -left-10 top-24 w-28 h-28 rounded-full bg-[#FFE08A]/15"></span>

                    <p class="relative inline-flex items-center gap-1.5 rounded-full bg-black/20 px-3 py-1 text-[11px] font-bold">
                        💰 Mua Shopee — {{ cashbackOn ? 'nhận lại tiền' : 'giá như cũ' }}
                    </p>
                    <h1 class="relative mt-2 text-[26px] md:text-4xl font-black leading-tight tracking-tight">
                        Dán link, <span class="text-[#FFE08A]">{{ cashbackOn ? 'nhận hoàn tiền' : 'mua ngay' }}</span>
                    </h1>
                    <p v-if="cashbackOn" class="relative mt-1 text-sm text-white/90">
                        Hoàn <b class="text-[#FFE08A]">{{ cashbackRate }}% hoa hồng</b> mỗi đơn Shopee — tiền vào ví, rút về MoMo/ZaloPay.
                    </p>
                    <p v-else class="relative mt-1 text-sm text-white/90">Dán link sản phẩm Shopee để nhận link mua chính thức.</p>

                    <ol class="relative mt-4 grid grid-cols-3 gap-2 text-center">
                        <li v-for="(s, i) in heroSteps" :key="s" class="rounded-2xl bg-black/15 border border-white/15 px-2 py-2.5">
                            <span class="block text-[#FFE08A] font-black text-sm">{{ i + 1 }}</span>
                            <span class="block text-xs font-bold">{{ s }}</span>
                        </li>
                    </ol>
                </div>

                <!-- Mời sang dealngon: tietkiemvi đang xây dựng lại, hoàn tiền đã chuyển sang đó. -->
                <a
                    v-if="dealngonUrl"
                    :href="dealngonUrl"
                    class="mt-3 flex items-center gap-3 rounded-2xl border-2 border-[var(--color-brand-green)]/40 bg-[var(--color-green-soft)] px-4 py-3 no-underline"
                >
                    <span class="text-2xl leading-none">📢</span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-sm font-black text-[var(--color-ink)]">Hoàn tiền đã chuyển sang {{ dealngonHost }}</span>
                        <span class="block text-xs text-[var(--color-muted)] leading-snug mt-0.5">
                            Tietkiemvi đang xây dựng lại — mua qua {{ dealngonHost }} để được hoàn tiền. Ở đây vẫn dán link lấy link mua bình thường.
                        </span>
                    </span>
                    <span class="flex-none rounded-xl bg-[var(--color-brand-green)] text-white px-3 py-2 text-xs font-black whitespace-nowrap">Sang ngay →</span>
                </a>

                <!-- Khối bọc giới hạn vùng dính: khung dán link chỉ dính trong lúc cuộn qua kết quả
                     và các tab — qua hết thì trôi đi, không che phần hướng dẫn và FAQ. -->
                <div>
                    <!-- Khung dán link DÍNH dưới header (top-16 = h-16 của AppLayout). Nền đặc chứ không
                         backdrop-blur — blur một vùng cỡ này mỗi khung hình làm khựng khi cuộn trên máy yếu. -->
                    <div
                        ref="stickyEl"
                        class="sticky top-16 z-30 -mx-4 px-4 pt-3 pb-3 bg-[var(--color-bg)] transition-shadow duration-200"
                        :class="stuck ? 'shadow-[0_12px_20px_-14px_rgba(0,0,0,.35)]' : ''"
                    >
                        <div class="rounded-3xl border-2 border-[var(--color-accent)]/70 bg-[var(--color-surface)] p-3 shadow-[0_8px_20px_-12px_rgba(212,63,18,.5)]">
                            <p class="px-1 mb-2 text-[11px] font-extrabold uppercase tracking-wider text-[var(--color-accent)]">⚡ Dán link sản phẩm Shopee</p>
                            <div class="flex flex-col md:flex-row gap-2">
                                <div class="relative flex-1">
                                    <input
                                        ref="linkUrlInput"
                                        v-model="linkUrl"
                                        type="url"
                                        enterkeyhint="go"
                                        @keydown.enter="resolveLink"
                                        @paste="onLinkPaste"
                                        @input="onLinkInput"
                                        placeholder="Dán link sản phẩm vào đây..."
                                        aria-label="Link sản phẩm"
                                        class="w-full rounded-2xl bg-[var(--color-peach-soft)] border border-dashed border-[var(--color-accent)]/40 text-[var(--color-ink)] placeholder:text-[var(--color-muted)] pl-4 pr-20 py-3.5 text-[15px] font-medium focus:outline-none focus:border-solid focus:border-[var(--color-accent)] focus:ring-4 focus:ring-[var(--color-accent)]/15"
                                    />
                                    <button
                                        @click="pasteLink"
                                        type="button"
                                        class="absolute right-1.5 top-1/2 -translate-y-1/2 rounded-xl bg-[#FFC531] text-[#3A1A00] px-3.5 py-2 text-xs font-black shadow-sm active:scale-95 transition"
                                    >Dán</button>
                                </div>
                                <button
                                    @click="resolveLink"
                                    :disabled="resolving || !linkUrl.trim() || alreadyResolved"
                                    class="btn-fire rounded-2xl px-7 py-3.5 text-base flex items-center justify-center gap-2 whitespace-nowrap disabled:opacity-60"
                                >
                                    <svg v-if="resolving" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" stroke-dasharray="30 70" />
                                    </svg>
                                    {{ resolving ? 'Đang tạo link...' : (alreadyResolved ? '✓ Đã tạo link' : (auth.isLoggedIn && cashbackOn ? 'Lấy link hoàn tiền' : 'Lấy link')) }}
                                </button>
                            </div>
                            <p v-if="linkError" class="mt-2 rounded-xl bg-[#FF0000]/10 text-[#c00000] dark:text-[#ff8080] px-3 py-2 text-sm font-semibold">⚠️ {{ linkError }}</p>
                            <p
                                v-else-if="auth.isLoggedIn && !auth.isAdmin && cashbackOn"
                                class="mt-2 rounded-xl bg-[var(--color-green-soft)] border border-[var(--color-brand-green)]/25 px-3 py-1.5 text-[11px] leading-tight truncate text-[var(--color-brand-green)] font-semibold"
                            >✓ Đã đăng nhập — mua qua link ở đây để được hoàn tiền</p>
                            <p v-else-if="!auth.isLoggedIn && cashbackOn" class="mt-2 px-1 text-[11px] text-[var(--color-muted)]">
                                🔑 Đăng nhập trước khi dán link thì đơn mới được hoàn tiền. Chưa có tài khoản?
                                <Link :href="joinHref" class="font-bold text-[var(--color-accent)] underline underline-offset-2">Đăng ký miễn phí</Link>
                            </p>
                        </div>
                    </div>

                    <!-- Kết quả -->
                    <div
                        v-if="cashbackResult"
                        ref="resultEl"
                        class="mt-1 rounded-3xl bg-[var(--color-surface)] border border-[var(--color-line)] p-4 md:p-5 shadow-[0_8px_28px_-12px_rgba(0,0,0,.15)]"
                    >
                        <div class="flex gap-3 items-start mb-4">
                            <div class="w-[72px] h-[72px] rounded-2xl bg-[var(--color-peach-soft)] flex-none overflow-hidden">
                                <img v-if="shopeeProduct?.product_image" :src="shopeeProduct.product_image" :alt="shopeeProduct.product_name" class="w-full h-full object-cover" />
                                <div v-else class="w-full h-full flex items-center justify-center text-2xl">🛍️</div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <span class="inline-flex items-center rounded-full bg-[#EE4D2D] text-white text-[10px] font-bold px-2 py-0.5">Shopee</span>
                                <p class="mt-1 font-semibold text-[var(--color-ink)] text-sm leading-snug line-clamp-2">{{ shopeeProduct?.product_name || 'Sản phẩm Shopee' }}</p>
                                <p v-if="shopeeProduct?.discounted_price" class="mt-1 flex items-baseline gap-2 flex-wrap">
                                    <span class="font-black text-[var(--color-accent)]">{{ vnd(shopeeProduct.discounted_price) }}</span>
                                    <span
                                        v-if="shopeeProduct.original_price > shopeeProduct.discounted_price"
                                        class="text-xs text-[var(--color-muted)] line-through"
                                    >{{ vnd(shopeeProduct.original_price) }}</span>
                                </p>
                                <span
                                    v-if="cashbackOn && cashbackResult.logged_in && cashbackResult.cashback_rate > 0"
                                    class="mt-1.5 inline-flex items-center gap-1 rounded-full bg-[var(--color-green-soft)] text-[var(--color-brand-green)] text-[11px] font-bold px-2 py-0.5"
                                >💰 Hoàn {{ cashbackResult.cashback_rate }}% hoa hồng</span>
                            </div>
                        </div>

                        <!-- Tiền hoàn dự kiến = hoa hồng ước tính của sản phẩm (nguồn đã tính trần hoa hồng
                             Shopee) × tỉ lệ khách THỰC nhận — xem CashbackService::estimateFor. Không ghi công
                             thức: chữ % khách thấy là tỉ lệ hiển thị, có thể khác tỉ lệ thực (Cài đặt). -->
                        <div v-if="cashbackResult.estimate" class="mb-4 rounded-2xl bg-[var(--color-green-soft)] border border-[var(--color-brand-green)]/25 px-4 py-3">
                            <p class="flex items-baseline justify-between gap-3">
                                <span class="text-sm font-bold text-[var(--color-ink)]">💰 Hoàn dự kiến</span>
                                <span class="text-xl font-black text-[var(--color-brand-green)]">~{{ vnd(cashbackResult.estimate) }}</span>
                            </p>
                            <p class="mt-1 text-[11px] text-[var(--color-muted)] leading-relaxed">
                                Tạm tính cho 1 sản phẩm theo hoa hồng Shopee của sản phẩm. Số thật theo báo cáo Shopee khi đơn
                                hoàn thành — đổi theo giá lúc mua, voucher và số lượng.
                            </p>
                        </div>

                        <!-- Thẻ <a> thật, cùng tab: điều kiện để điện thoại mở thẳng app Shopee. -->
                        <div ref="resultCtaEl" class="flex items-stretch gap-2">
                            <a
                                :href="cashbackResult.link"
                                class="btn-fire flex-1 min-w-0 px-6 py-4 rounded-2xl flex items-center justify-center gap-2 text-base animate-pulse-ring no-underline"
                            >
                                <span>🛒</span>
                                <span>Mua trên Shopee</span>
                            </a>
                            <button
                                type="button"
                                @click="copyLink"
                                :disabled="copying"
                                title="Sao chép link"
                                aria-label="Sao chép link"
                                class="flex-none w-14 rounded-2xl flex items-center justify-center bg-[var(--color-peach-soft)] border border-[var(--color-line)] text-[var(--color-ink)] hover:bg-[var(--color-peach)] transition disabled:opacity-60"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                            </button>
                        </div>

                        <div class="mt-3 flex items-start gap-2 bg-[var(--color-ticket)] border border-[var(--color-ticket-border)] rounded-xl px-3 py-2.5">
                            <span class="text-sm leading-none">💡</span>
                            <p class="text-xs text-[var(--color-ink)] leading-relaxed">
                                Đặt hàng <b>ngay trong lượt mở này</b>. Bấm link của người khác sau đó thì đơn tính cho họ.
                                <template v-if="cashbackOn && cashbackResult.logged_in">
                                    Đơn hoàn thành bên Shopee là tiền vào ví — theo dõi ở
                                    <Link href="/don-hang" class="font-bold text-[var(--color-accent)] underline underline-offset-2">Đơn hàng</Link>.
                                </template>
                            </p>
                        </div>

                        <p v-if="dealngonUrl" class="mt-3 text-center text-xs text-[var(--color-muted)] leading-relaxed">
                            Muốn được hoàn tiền cho đơn này? Dán link ở
                            <a :href="dealngonUrl" class="font-bold text-[var(--color-brand-green)] underline underline-offset-2">{{ dealngonHost }}</a>
                            rồi mua qua link bên đó.
                        </p>

                        <!-- Khách vãng lai VẪN có link (không chặn đường mua), nhưng phải nói rõ đơn này
                             không được hoàn tiền — và cách để được. -->
                        <p v-if="cashbackOn && !cashbackResult.logged_in" class="mt-3 text-center text-xs text-[var(--color-muted)] leading-relaxed">
                            Link này không mang mã của bạn nên đơn <b>không được hoàn tiền</b>.
                            <button type="button" @click="loginKeepingLink" class="font-bold text-[var(--color-accent)] underline underline-offset-2">Đăng nhập</button>
                            rồi lấy lại link để được hoàn {{ cashbackResult.cashback_rate }}% hoa hồng.
                        </p>
                    </div>

                    <!-- Bảng vàng | Lịch sử -->
                    <section class="mt-6">
                        <div
                            v-if="tabs.length > 1"
                            role="tablist"
                            aria-label="Bảng vàng và lịch sử"
                            class="grid grid-cols-2 gap-1 p-1 rounded-2xl bg-[var(--color-peach-soft)] border border-[var(--color-line)]"
                        >
                            <button
                                v-for="tab in tabs"
                                :key="tab.key"
                                role="tab"
                                :aria-selected="activeTab === tab.key"
                                @click="activeTab = tab.key"
                                class="rounded-xl py-2.5 text-sm font-bold transition flex items-center justify-center gap-1.5 whitespace-nowrap"
                                :class="activeTab === tab.key ? 'bg-[var(--color-surface)] text-[var(--color-accent)] shadow-sm' : 'text-[var(--color-muted)]'"
                            >
                                {{ tab.label }}
                                <span v-if="tab.key === 'history' && history.length" class="text-[10px] font-mono px-1.5 py-0.5 rounded-full bg-[var(--color-accent)] text-white">{{ history.length }}</span>
                            </button>
                        </div>
                        <h2 v-else class="text-lg font-black text-[var(--color-ink)]">🕘 Link đã dán</h2>

                        <div class="mt-3">
                            <CashbackLeaderboard v-if="activeTab === 'leaderboard' && hasLeaderboard" :leaderboard="leaderboard" compact />

                            <template v-else>
                                <ul v-if="history.length" class="space-y-2">
                                    <li
                                        v-for="h in history"
                                        :key="h.url"
                                        class="flex items-center gap-3 rounded-2xl bg-[var(--color-surface)] border border-[var(--color-line)] px-3 py-2.5"
                                    >
                                        <div class="w-12 h-12 rounded-xl bg-[var(--color-peach-soft)] flex-none overflow-hidden">
                                            <img v-if="h.product_image" :src="h.product_image" :alt="h.product_name" class="w-full h-full object-cover" loading="lazy" />
                                            <div v-else class="w-full h-full flex items-center justify-center text-lg">🛍️</div>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-semibold text-[var(--color-ink)] line-clamp-1">{{ h.product_name || h.url }}</p>
                                            <p class="text-[11px] text-[var(--color-muted)] mt-0.5">{{ timeAgo(h.created_at) }} · Shopee</p>
                                        </div>
                                        <button
                                            type="button"
                                            @click="resolveAgain(h)"
                                            :disabled="resolving && linkUrl.trim() === h.url"
                                            class="flex-none rounded-xl border border-[var(--color-accent)]/40 text-[var(--color-accent)] px-3 py-2 text-xs font-bold hover:bg-[var(--color-peach-soft)] transition disabled:opacity-50"
                                        >Mở lại</button>
                                    </li>
                                </ul>
                                <div v-else class="rounded-2xl border border-dashed border-[var(--color-line)] bg-[var(--color-surface)] px-5 py-6 text-center">
                                    <p class="text-2xl mb-1">🕘</p>
                                    <p class="text-sm font-semibold text-[var(--color-ink)]">Chưa có lịch sử</p>
                                    <p class="text-xs text-[var(--color-muted)] mt-1">Những link bạn dán sẽ hiện ở đây để mở lại nhanh.</p>
                                    <button type="button" @click="focusLinkInput" class="mt-3 text-xs font-bold text-[var(--color-accent)] underline underline-offset-2">Dán link đầu tiên →</button>
                                </div>
                                <div class="mt-2 flex items-center justify-between gap-3 text-[11px] text-[var(--color-muted)]">
                                    <span>🔒 Lịch sử chỉ lưu trên máy này.</span>
                                    <button v-if="history.length" type="button" @click="clearHistory" class="font-semibold underline underline-offset-2 hover:text-[var(--color-accent)]">Xoá lịch sử</button>
                                </div>
                            </template>
                        </div>
                    </section>
                </div>

                <!-- Điểm danh nhận quà: lý do để quay lại vào ngày khách KHÔNG có gì để mua. -->
                <!-- Đang mời sang dealngon thì thôi: thẻ này mời đăng ký/đăng nhập tài khoản ở đây. -->
                <DailyCheckIn v-if="dailyCheckIn && !dealngonUrl" :state="dailyCheckIn" class="mt-6" />

                <!-- Các bước -->
                <section class="mt-8">
                    <h2 class="text-lg font-black text-[var(--color-ink)]">{{ cashbackOn ? 'Mua hoàn tiền trong 4 bước' : 'Mua qua link trong 4 bước' }}</h2>
                    <ol class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <li
                            v-for="(s, i) in steps"
                            :key="i"
                            class="rounded-2xl bg-[var(--color-surface)] border border-[var(--color-line)] p-3 text-center"
                        >
                            <span class="mx-auto w-8 h-8 rounded-full bg-[var(--color-accent)] text-white font-black text-sm flex items-center justify-center">{{ i + 1 }}</span>
                            <p class="mt-2 font-bold text-sm text-[var(--color-ink)]">{{ s.title }}</p>
                            <p class="mt-0.5 text-[11px] text-[var(--color-muted)] leading-snug">{{ s.desc }}</p>
                        </li>
                    </ol>
                </section>
            </div>
        </section>

        <!-- Hoàn tiền (bản rút gọn: hai cột ✓/✕ rồi dẫn sang /hoan-tien) + hạng thành viên. -->
        <CashbackExplainer v-if="cashbackOn" compact />
        <MembershipTiers v-if="cashbackOn && membershipTiers" v-bind="membershipTiers" />

        <section class="px-4 pb-10">
            <div class="max-w-3xl mx-auto">
                <!-- FAQ -->
                <section class="mt-8">
                    <h2 class="text-lg font-black text-[var(--color-ink)] mb-3">Câu hỏi thường gặp</h2>
                    <div class="space-y-2">
                        <div
                            v-for="(faq, i) in faqs"
                            :key="i"
                            class="rounded-2xl bg-[var(--color-surface)] border border-[var(--color-line)] overflow-hidden"
                        >
                            <button
                                @click="openFaq = openFaq === i ? null : i"
                                :aria-expanded="openFaq === i"
                                :aria-controls="`faq-panel-${i}`"
                                class="w-full px-4 py-3.5 text-left flex justify-between items-center gap-3 font-semibold text-[var(--color-ink)] text-sm"
                            >
                                {{ faq.q }}
                                <span class="text-[var(--color-muted)] flex-none transition-transform" :class="openFaq === i ? 'rotate-180' : ''">▾</span>
                            </button>
                            <Transition name="fade-up">
                                <div v-if="openFaq === i" :id="`faq-panel-${i}`" class="px-4 pb-4 text-sm text-[var(--color-muted)] leading-relaxed">
                                    {{ faq.a }}
                                </div>
                            </Transition>
                        </div>
                    </div>
                </section>
            </div>
        </section>

        <!-- Lời mời tạo tài khoản cho khách vãng lai — để cuối trang, chỗ không cản đường. Kể đúng
             thứ tự các chặng (đăng nhập → đơn hoàn thành → đối soát → vào ví). -->
        <section v-if="cashbackOn && !auth.isLoggedIn" class="px-4 pb-12">
            <div class="max-w-3xl mx-auto rounded-3xl p-6 md:p-8 text-center text-white bg-[linear-gradient(150deg,#FF7A45_0%,#F5511E_45%,#B8360D_100%)] shadow-[0_18px_40px_-14px_rgba(212,63,18,.6)]">
                <h2 class="text-xl md:text-2xl font-black">Mua thì vẫn phải mua — sao không lấy lại một phần?</h2>
                <p class="mt-3 text-sm text-white/90 leading-relaxed">
                    Đăng nhập rồi dán link trước khi mua: Shopee chốt đơn ở trạng thái Hoàn thành, tụi mình đối soát báo cáo,
                    rồi {{ cashbackRate }}% hoa hồng của đơn vào ví bạn. Không nhanh, nhưng có thật.
                </p>
                <Link :href="joinHref" class="mt-5 inline-block bg-white text-[var(--color-accent-deep)] font-black px-7 py-3.5 rounded-2xl no-underline">{{ joinLabel }}</Link>
            </div>
        </section>
    </AppLayout>
</template>
