<script setup>
import { computed, ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps({
    activities: Object,
    filters: Object,
    summary: Object,
    daily: { type: Array, default: () => [] },
})

const toast = useToast()
const pruning = ref(false)

function pruneBots() {
    if (!confirm('Xoá toàn bộ log của bot/crawler (Facebook, Google...) khỏi lịch sử? Không ảnh hưởng log của người dùng thật.')) return

    pruning.value = true
    router.post('/admin/activities/prune-bots', {}, {
        preserveScroll: true,
        onSuccess: () => toast.success('Đã dọn xong log bot.'),
        onError: () => toast.error('Không dọn được, vui lòng thử lại.'),
        onFinish: () => { pruning.value = false },
    })
}

const eventLabels = {
    page_view: 'Xem trang',
    url_paste: 'Dán link sản phẩm',
    voucher_select: 'Chọn/lấy mã',
    voucher_copy: 'Copy mã',
    short_link_click: 'Click link rút gọn',
    facebook_open: '👉 Mở Facebook (chuyển đổi)',
    login_failed: '🔒 Đăng nhập sai',
    login_success: '✅ Đăng nhập thành công',
    otp_verify_failed: '🔒 OTP sai',
    admin_access_denied: '🚫 Bị chặn vào Admin',
    rate_limited: '⛔ Bị chặn (rate limit)',
}

const securityEventLabels = {
    login_failed: 'Đăng nhập sai',
    login_success: 'Đăng nhập thành công',
    otp_verify_failed: 'OTP sai',
    admin_access_denied: 'Bị chặn vào Admin',
    rate_limited: 'Bị chặn (rate limit)',
}

// Khách bấm "Mở Facebook ngay" khi link nằm ở bình luận hay ở mô tả reel.
const conversionModeLabels = {
    fb_comment: '💬 Qua bình luận',
    fb_reel: '🎬 Qua reel',
}

const deviceLabels = {
    mobile: '📱 Mobile',
    tablet: '📱 Tablet',
    desktop: '💻 Desktop',
}

const trafficSourceLabels = {
    direct: '🔗 Trực tiếp / Gõ link',
    facebook: '📘 Facebook',
    zalo: '💬 Zalo',
    google: '🔍 Google',
    tiktok: '🎵 TikTok',
    youtube: '▶️ YouTube',
    instagram: '📸 Instagram',
}

function trafficSourceLabel(key) {
    return trafficSourceLabels[key] || `🌐 ${key}`
}

function filter(key, value) {
    apply({ ...props.filters, [key]: value })
}

// Gộp nhiều bộ lọc trong 1 lần điều hướng, bỏ các key rỗng cho URL gọn.
function apply(next) {
    const params = Object.fromEntries(
        Object.entries(next).filter(([, v]) => v !== null && v !== undefined && v !== '')
    )
    router.get('/admin/activities', params, { preserveState: true, preserveScroll: true })
}

const ipInput = ref(props.filters?.ip || '')
const fromInput = ref(props.filters?.from || '')
const toInput = ref(props.filters?.to || '')

// preserveState giữ nguyên ô nhập, nên phải đồng bộ lại khi filters đổi từ ngoài
// (bấm nút back, hoặc lọc từ thẻ tóm tắt).
watch(() => props.filters, (f) => {
    ipInput.value = f?.ip || ''
    fromInput.value = f?.from || ''
    toInput.value = f?.to || ''
})

function applySearch() {
    apply({ ...props.filters, ip: ipInput.value.trim(), from: fromInput.value, to: toInput.value })
}

function clearSearch() {
    ipInput.value = ''
    fromInput.value = ''
    toInput.value = ''
    applySearch()
}

const quickRanges = [
    { label: 'Hôm nay', days: 0 },
    { label: '7 ngày', days: 6 },
    { label: '30 ngày', days: 29 },
    { label: '90 ngày', days: 89 },
]

// Chọn nhanh khoảng ngày hay dùng; days = 0 nghĩa là chỉ hôm nay.
function pickRange(days) {
    const end = new Date()
    const start = new Date()
    start.setDate(start.getDate() - days)
    fromInput.value = ymd(start)
    toInput.value = ymd(end)
    applySearch()
}

function ymd(d) {
    const pad = (n) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

// Tải file nên đi thẳng bằng link thường, không qua Inertia (Inertia chỉ nhận JSON).
const exportUrl = computed(() => {
    const params = new URLSearchParams()
    Object.entries(props.filters || {}).forEach(([k, v]) => {
        if (v !== null && v !== undefined && v !== '') params.append(k, v)
    })
    const qs = params.toString()
    return '/admin/activities/export' + (qs ? `?${qs}` : '')
})

const hasSearch = computed(() => !!(props.filters?.ip || props.filters?.from || props.filters?.to))

function goPage(url) {
    if (url) router.get(url, {}, { preserveState: true })
}

// Trên mobile 6 thẻ thống kê đẩy bảng dữ liệu xuống quá sâu — mặc định gấp lại,
// từ md trở lên vẫn hiện đầy đủ như cũ.
const showStats = ref(false)

// '2026-09-09 14:32:10' → '09/09 14:32'. Thẻ trên mobile hẹp, bỏ phần năm và giây.
function shortTime(value) {
    const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(value || '')
    return m ? `${m[3]}/${m[2]} ${m[4]}:${m[5]}` : (value || '—')
}

function deviceLine(a) {
    const device = deviceLabels[a.device_type] || a.device_type
    const ua = [a.browser, a.os_name].filter(Boolean).join(' / ')
    return [device, ua].filter(Boolean).join(' · ')
}

function locationLine(a) {
    return [a.city, a.country].filter(Boolean).join(', ')
}

// Thanh dài nhất = 100%, các ngày khác tính theo tỉ lệ; tránh chia 0 khi chưa có log.
const dailyMax = computed(() => Math.max(1, ...props.daily.map((d) => d.events)))

const dailyTotals = computed(() => props.daily.reduce(
    (acc, d) => ({ page_views: acc.page_views + d.page_views, events: acc.events + d.events }),
    { page_views: 0, events: 0 },
))

const today = ymd(new Date())

// '2026-09-09' → '09/09'
function shortDate(value) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '')
    return m ? `${m[3]}/${m[2]}` : value
}

// Bấm vào một ngày thì lọc bảng bên dưới đúng ngày đó.
function pickDay(date) {
    fromInput.value = date
    toInput.value = date
    applySearch()
}
</script>

<template>
    <Head title="Admin — Theo dõi người dùng" />
    <AdminLayout>
        <template #title>Theo dõi hoạt động người dùng</template>

        <!-- Sự kiện bảo mật (7 ngày gần nhất) -->
        <div class="bg-red-500/5 rounded-2xl border border-red-500/30 p-3 md:p-4 mb-4 md:mb-6">
            <p class="text-xs font-semibold text-red-400 mb-2">🛡️ Sự kiện bảo mật (7 ngày gần nhất)</p>
            <div class="grid grid-cols-2 md:flex md:flex-wrap gap-x-4 md:gap-x-6 gap-y-1.5 text-xs md:text-sm">
                <button
                    v-for="(label, key) in securityEventLabels" :key="key"
                    @click="filter('event_type', key)"
                    class="flex items-baseline justify-between gap-2 min-w-0 text-left text-[var(--color-ink)]/80 hover:text-[var(--color-accent)] transition"
                >
                    <span class="truncate">{{ label }}</span>
                    <b class="flex-none">{{ summary?.security_events?.[key] ?? 0 }}</b>
                </button>
            </div>
        </div>

        <!-- Mobile: gấp/mở khối thống kê để bảng dữ liệu nằm ngay tầm mắt -->
        <button @click="showStats = !showStats"
            class="md:hidden w-full mb-3 px-4 py-2.5 rounded-xl text-sm font-semibold bg-[var(--color-surface)] text-[var(--color-ink)] border border-[var(--color-line)] flex items-center justify-between">
            <span>📊 Thống kê 7 ngày <span class="text-[var(--color-muted)] font-normal">({{ summary?.total ?? 0 }} sự kiện)</span></span>
            <span class="text-[var(--color-muted)]">{{ showStats ? '▲' : '▼' }}</span>
        </button>

        <!-- Summary cards (7 ngày gần nhất) -->
        <div :class="showStats ? 'grid' : 'hidden md:grid'"
            class="grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 md:gap-4 mb-4 md:mb-6">
            <div class="min-w-0 bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4">
                <p class="text-xs text-[var(--color-muted)] mb-1">Tổng sự kiện (7 ngày)</p>
                <p class="text-2xl font-extrabold text-[var(--color-ink)]">{{ summary?.total ?? 0 }}</p>
            </div>
            <div class="min-w-0 bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4">
                <p class="text-xs text-[var(--color-muted)] mb-1">Thiết bị</p>
                <p class="text-sm text-[var(--color-ink)] space-y-0.5">
                    <span v-for="(count, device) in summary?.by_device" :key="device" class="block">
                        {{ deviceLabels[device] || device }}: <b>{{ count }}</b>
                    </span>
                    <span v-if="!Object.keys(summary?.by_device || {}).length" class="text-[var(--color-muted)]">Chưa có dữ liệu</span>
                </p>
            </div>
            <div class="min-w-0 bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4">
                <p class="text-xs text-[var(--color-muted)] mb-1">Top mã voucher được dùng</p>
                <p class="text-sm text-[var(--color-ink)] space-y-0.5">
                    <span v-for="(count, code) in summary?.top_vouchers" :key="code" class="block truncate">
                        {{ code }}: <b>{{ count }}</b>
                    </span>
                    <span v-if="!Object.keys(summary?.top_vouchers || {}).length" class="text-[var(--color-muted)]">Chưa có dữ liệu</span>
                </p>
            </div>
            <div class="min-w-0 bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4">
                <p class="text-xs text-[var(--color-muted)] mb-1">Top quốc gia</p>
                <p class="text-sm text-[var(--color-ink)] space-y-0.5">
                    <span v-for="(count, country) in summary?.top_countries" :key="country" class="block">
                        {{ country }}: <b>{{ count }}</b>
                    </span>
                    <span v-if="!Object.keys(summary?.top_countries || {}).length" class="text-[var(--color-muted)]">Chưa có dữ liệu</span>
                </p>
            </div>
            <div class="min-w-0 bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4">
                <p class="text-xs text-[var(--color-muted)] mb-1">Khách vào web từ đâu</p>
                <p class="text-sm text-[var(--color-ink)] space-y-0.5">
                    <button
                        v-for="(count, src) in summary?.top_traffic_sources" :key="src"
                        @click="filter('traffic_source', src)"
                        class="flex items-center justify-between w-full text-left hover:text-[var(--color-accent)] transition"
                    >
                        <span class="truncate">{{ trafficSourceLabel(src) }}</span>
                        <b class="flex-none ml-2">{{ count }}</b>
                    </button>
                    <span v-if="!Object.keys(summary?.top_traffic_sources || {}).length" class="text-[var(--color-muted)]">Chưa có dữ liệu</span>
                </p>
            </div>
            <div class="min-w-0 bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4">
                <p class="text-xs text-[var(--color-muted)] mb-1">Top IP truy cập</p>
                <p class="text-sm text-[var(--color-ink)] space-y-0.5">
                    <button
                        v-for="(count, ip) in summary?.top_ips" :key="ip"
                        @click="ipInput = ip; applySearch()"
                        class="flex items-center justify-between w-full text-left hover:text-[var(--color-accent)] transition"
                    >
                        <span class="truncate font-mono text-xs">{{ ip }}</span>
                        <b class="flex-none ml-2">{{ count }}</b>
                    </button>
                    <span v-if="!Object.keys(summary?.top_ips || {}).length" class="text-[var(--color-muted)]">Chưa có dữ liệu</span>
                </p>
            </div>
        </div>

        <!-- Truy cập theo ngày: lượt xem trang (khách ghé) và tổng sự kiện (mọi hành động) -->
        <div :class="showStats ? 'block' : 'hidden md:block'"
            class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-3 md:p-4 mb-4 md:mb-6">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 mb-3">
                <p class="text-xs font-semibold text-[var(--color-ink)]">📅 Truy cập theo ngày (14 ngày gần nhất)</p>
                <p class="text-xs text-[var(--color-muted)]">
                    <span class="inline-block w-2.5 h-2.5 rounded-sm bg-[var(--color-accent)] align-middle mr-1"></span>Lượt xem trang: <b class="text-[var(--color-ink)]">{{ dailyTotals.page_views }}</b>
                    <span class="mx-2">·</span>
                    <span class="inline-block w-2.5 h-2.5 rounded-sm bg-[var(--color-accent)]/25 align-middle mr-1"></span>Tổng sự kiện: <b class="text-[var(--color-ink)]">{{ dailyTotals.events }}</b>
                </p>
            </div>
            <div class="space-y-1">
                <button
                    v-for="d in daily" :key="d.date"
                    @click="pickDay(d.date)"
                    :title="`Lọc bảng theo ngày ${d.date}`"
                    class="group flex items-center gap-2 md:gap-3 w-full text-left text-xs hover:bg-[var(--color-accent)]/5 rounded-lg px-1 py-0.5 transition"
                    :class="filters?.from === d.date && filters?.to === d.date ? 'bg-[var(--color-accent)]/10' : ''"
                >
                    <span class="flex-none w-11 font-mono tabular-nums"
                        :class="d.date === today ? 'font-bold text-[var(--color-accent)]' : 'text-[var(--color-muted)]'">
                        {{ d.date === today ? 'Nay' : shortDate(d.date) }}
                    </span>
                    <span class="relative flex-1 h-4 min-w-0 rounded bg-[var(--color-line)]/40 overflow-hidden">
                        <span class="absolute inset-y-0 left-0 rounded bg-[var(--color-accent)]/25"
                            :style="{ width: `${(d.events / dailyMax) * 100}%` }"></span>
                        <span class="absolute inset-y-0 left-0 rounded bg-[var(--color-accent)]"
                            :style="{ width: `${(d.page_views / dailyMax) * 100}%` }"></span>
                    </span>
                    <span class="flex-none w-[5.5rem] md:w-28 text-right tabular-nums text-[var(--color-ink)]">
                        <b>{{ d.page_views }}</b><span class="text-[var(--color-muted)]"> / {{ d.events }}</span>
                    </span>
                </button>
            </div>
            <p class="mt-2 text-[11px] text-[var(--color-muted)]">Số đậm là lượt xem trang, số nhạt là tổng sự kiện. Bấm vào một ngày để lọc bảng bên dưới.</p>
        </div>

        <!-- Chuyển đổi: khách bấm "Mở Facebook ngay" — bước cuối trước khi sang FB lấy mã -->
        <div :class="showStats ? 'block' : 'hidden md:block'"
            class="bg-[#1877F2]/5 rounded-2xl border border-[#1877F2]/30 p-3 md:p-4 mb-4 md:mb-6">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 mb-3">
                <button @click="filter('event_type', 'facebook_open')"
                    class="text-xs font-semibold text-[#1877F2] hover:underline text-left">
                    🎯 Chuyển đổi — bấm "Mở Facebook ngay" (7 ngày)
                </button>
                <p class="text-xs text-[var(--color-muted)]">
                    <span v-for="(label, mode) in conversionModeLabels" :key="mode" class="mr-3">
                        {{ label }}: <b class="text-[var(--color-ink)]">{{ summary?.conversions?.by_mode?.[mode] ?? 0 }}</b>
                    </span>
                </p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-[auto_1fr] gap-3 md:gap-6 items-start">
                <div class="min-w-0">
                    <p class="text-xs text-[var(--color-muted)] mb-1">Tổng lượt chuyển đổi</p>
                    <p class="text-3xl font-extrabold text-[var(--color-ink)]">{{ summary?.conversions?.total ?? 0 }}</p>
                </div>
                <div class="min-w-0">
                    <p class="text-xs text-[var(--color-muted)] mb-1">Sản phẩm được chuyển đổi</p>
                    <ol class="text-sm text-[var(--color-ink)] space-y-1">
                        <li
                            v-for="(count, name, idx) in summary?.conversions?.top_products" :key="name"
                            class="flex items-baseline gap-2"
                        >
                            <span class="flex-none w-5 text-xs text-[var(--color-muted)] tabular-nums">{{ idx + 1 }}.</span>
                            <span class="min-w-0 flex-1 line-clamp-2 break-words">{{ name }}</span>
                            <b class="flex-none tabular-nums">{{ count }}</b>
                        </li>
                        <li v-if="!Object.keys(summary?.conversions?.top_products || {}).length" class="text-[var(--color-muted)]">
                            Chưa có lượt chuyển đổi nào
                        </li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Tìm theo IP + khoảng ngày, và xuất CSV đúng những gì đang lọc -->
        <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-3 md:p-4 mb-4 md:mb-6">
            <div class="grid grid-cols-2 md:flex md:flex-wrap md:items-end gap-2 md:gap-3">
                <div class="col-span-2 md:flex-1 md:min-w-[200px] min-w-0">
                    <label class="block text-xs text-[var(--color-muted)] mb-1">Tìm theo IP</label>
                    <input
                        v-model="ipInput" type="search" inputmode="decimal" placeholder="VD: 113.161.20.5 hoặc 113.161."
                        @keyup.enter="applySearch"
                        class="w-full px-3 py-2.5 md:py-2 rounded-xl text-sm font-mono bg-[var(--color-bg)] text-[var(--color-ink)] border border-[var(--color-line)] focus:border-[var(--color-accent)] focus:outline-none"
                    />
                </div>
                <div class="min-w-0">
                    <label class="block text-xs text-[var(--color-muted)] mb-1">Từ ngày</label>
                    <input v-model="fromInput" type="date" :max="toInput || undefined"
                        class="w-full px-3 py-2.5 md:py-2 rounded-xl text-sm bg-[var(--color-bg)] text-[var(--color-ink)] border border-[var(--color-line)] focus:border-[var(--color-accent)] focus:outline-none" />
                </div>
                <div class="min-w-0">
                    <label class="block text-xs text-[var(--color-muted)] mb-1">Đến ngày</label>
                    <input v-model="toInput" type="date" :min="fromInput || undefined"
                        class="w-full px-3 py-2.5 md:py-2 rounded-xl text-sm bg-[var(--color-bg)] text-[var(--color-ink)] border border-[var(--color-line)] focus:border-[var(--color-accent)] focus:outline-none" />
                </div>
                <button @click="applySearch"
                    class="px-4 py-2.5 md:py-2 rounded-xl text-sm font-semibold bg-[var(--color-accent)] text-white hover:opacity-90 transition"
                    :class="hasSearch ? '' : 'col-span-2'">
                    Lọc
                </button>
                <button v-if="hasSearch" @click="clearSearch"
                    class="px-4 py-2.5 md:py-2 rounded-xl text-sm font-semibold bg-[var(--color-surface)] text-[var(--color-ink)] border border-[var(--color-line)] hover:border-[var(--color-accent)] transition">
                    Xoá lọc
                </button>
                <a :href="exportUrl" download
                    class="col-span-2 md:col-span-1 px-4 py-2.5 md:py-2 rounded-xl text-sm font-semibold text-center bg-emerald-500/10 text-emerald-500 border border-emerald-500/30 hover:bg-emerald-500/20 transition">
                    ⬇️ Xuất CSV
                </a>
            </div>
            <div class="flex flex-wrap items-center gap-2 mt-3">
                <span class="text-xs text-[var(--color-muted)]">Nhanh:</span>
                <button v-for="r in quickRanges" :key="r.label" @click="pickRange(r.days)"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-[var(--color-bg)] text-[var(--color-ink)]/80 border border-[var(--color-line)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] transition">
                    {{ r.label }}
                </button>
            </div>
            <p v-if="hasSearch" class="text-xs text-[var(--color-muted)] mt-2 break-words">
                Đang lọc:
                <b v-if="filters?.ip" class="text-[var(--color-ink)] font-mono">IP {{ filters.ip }}</b>
                <b v-if="filters?.from || filters?.to" class="text-[var(--color-ink)]">
                    {{ filters?.ip ? ' · ' : '' }}{{ filters?.from || 'đầu' }} → {{ filters?.to || 'nay' }}
                </b>
            </p>
        </div>

        <!-- Lọc theo sự kiện: mobile dùng dropdown cho gọn, desktop giữ dãy nút -->
        <div class="flex flex-col md:flex-row md:flex-wrap md:items-center md:justify-between gap-2 mb-4 md:mb-6">
            <select
                :value="filters?.event_type || ''"
                @change="filter('event_type', $event.target.value)"
                class="md:hidden w-full px-3 py-2.5 rounded-xl text-sm font-semibold bg-[var(--color-surface)] text-[var(--color-ink)] border border-[var(--color-line)] focus:border-[var(--color-accent)] focus:outline-none"
            >
                <option value="">Tất cả sự kiện</option>
                <option v-for="(label, key) in eventLabels" :key="key" :value="key">{{ label }}</option>
            </select>

            <div class="hidden md:flex md:flex-wrap gap-2">
                <button @click="filter('event_type', '')" :class="!filters?.event_type ? 'bg-[var(--color-accent)] text-white' : 'bg-[var(--color-surface)] text-[var(--color-ink)] border border-[var(--color-line)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)]'"
                    class="px-4 py-2 rounded-xl text-sm font-semibold transition">Tất cả sự kiện</button>
                <button v-for="(label, key) in eventLabels" :key="key" @click="filter('event_type', key)"
                    :class="filters?.event_type === key ? 'bg-[var(--color-accent)] text-white' : 'bg-[var(--color-surface)] text-[var(--color-ink)] border border-[var(--color-line)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)]'"
                    class="px-4 py-2 rounded-xl text-sm font-semibold transition">
                    {{ label }}
                </button>
            </div>

            <button v-if="filters?.traffic_source" @click="filter('traffic_source', '')"
                class="px-4 py-2.5 md:py-2 rounded-xl text-sm font-semibold bg-[var(--color-accent)] text-white flex items-center justify-center gap-1.5 md:order-none">
                Nguồn: {{ trafficSourceLabel(filters.traffic_source) }} ✕
            </button>

            <button @click="pruneBots" :disabled="pruning"
                class="px-4 py-2.5 md:py-2 rounded-xl text-sm font-semibold bg-red-500/10 text-red-400 border border-red-500/30 hover:bg-red-500/20 transition disabled:opacity-60 md:ml-auto">
                {{ pruning ? 'Đang dọn...' : '🤖 Xoá log bot' }}
            </button>
        </div>

        <!-- Mobile: mỗi hoạt động là một thẻ, đọc theo chiều dọc, không phải kéo ngang -->
        <div class="md:hidden space-y-2.5">
            <div v-for="a in activities?.data" :key="a.id"
                class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-3.5">
                <div class="flex items-start justify-between gap-2">
                    <p class="text-sm font-bold text-[var(--color-ink)] leading-snug min-w-0">
                        {{ eventLabels[a.event_type] || a.event_type }}
                    </p>
                    <span class="flex-none text-xs text-[var(--color-muted)] tabular-nums pt-0.5">{{ shortTime(a.created_at) }}</span>
                </div>

                <div class="mt-2.5 pt-2.5 border-t border-[var(--color-line)] space-y-1.5 text-xs">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="flex-none text-[var(--color-muted)]">Người dùng</span>
                        <span class="min-w-0 text-right text-[var(--color-ink)]/80 truncate">{{ a.user || 'Khách' }}</span>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="flex-none text-[var(--color-muted)]">IP</span>
                        <button v-if="a.ip_address" @click="ipInput = a.ip_address; applySearch()"
                            class="min-w-0 text-right font-mono text-[var(--color-accent)] underline decoration-dotted break-all">
                            {{ a.ip_address }}
                        </button>
                        <span v-else class="text-[var(--color-muted)]">—</span>
                    </div>
                    <div v-if="deviceLine(a)" class="flex items-baseline justify-between gap-3">
                        <span class="flex-none text-[var(--color-muted)]">Thiết bị</span>
                        <span class="min-w-0 text-right text-[var(--color-ink)]/80 break-words">{{ deviceLine(a) }}</span>
                    </div>
                    <div v-if="locationLine(a)" class="flex items-baseline justify-between gap-3">
                        <span class="flex-none text-[var(--color-muted)]">Vị trí</span>
                        <span class="min-w-0 text-right text-[var(--color-ink)]/80 break-words">{{ locationLine(a) }}</span>
                    </div>
                    <div v-if="a.traffic_source" class="flex items-baseline justify-between gap-3">
                        <span class="flex-none text-[var(--color-muted)]">Nguồn</span>
                        <button @click="filter('traffic_source', a.traffic_source)"
                            class="min-w-0 text-right text-[var(--color-accent)] break-words">
                            {{ trafficSourceLabel(a.traffic_source) }}
                        </button>
                    </div>
                    <div v-if="a.voucher_code" class="flex items-baseline justify-between gap-3">
                        <span class="flex-none text-[var(--color-muted)]">Mã</span>
                        <span class="min-w-0 text-right font-mono font-semibold text-[var(--color-ink)] break-all">{{ a.voucher_code }}</span>
                    </div>
                    <div v-if="a.product_name || a.source" class="flex items-baseline justify-between gap-3">
                        <span class="flex-none text-[var(--color-muted)]">Sản phẩm</span>
                        <span class="min-w-0 text-right text-[var(--color-ink)]/80 break-words line-clamp-2">{{ a.product_name || a.source }}</span>
                    </div>
                </div>
            </div>
            <div v-if="!activities?.data?.length"
                class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] px-6 py-10 text-center text-sm text-[var(--color-muted)]">
                Chưa có hoạt động nào.
            </div>
        </div>

        <div class="hidden md:block bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] overflow-hidden overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-peach-soft)]">
                    <tr class="text-left text-xs text-[var(--color-muted)] whitespace-nowrap">
                        <th class="px-4 py-3 font-semibold">Thời gian</th>
                        <th class="px-4 py-3 font-semibold">Sự kiện</th>
                        <th class="px-4 py-3 font-semibold">Người dùng</th>
                        <th class="px-4 py-3 font-semibold">Sản phẩm / Nguồn</th>
                        <th class="px-4 py-3 font-semibold">Mã</th>
                        <th class="px-4 py-3 font-semibold">Thiết bị</th>
                        <th class="px-4 py-3 font-semibold">Trình duyệt / OS</th>
                        <th class="px-4 py-3 font-semibold">IP</th>
                        <th class="px-4 py-3 font-semibold">Vị trí</th>
                        <th class="px-4 py-3 font-semibold">Nguồn truy cập</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-line)]">
                    <tr v-for="a in activities?.data" :key="a.id">
                        <td class="px-4 py-3 text-[var(--color-muted)] whitespace-nowrap">{{ a.created_at }}</td>
                        <td class="px-4 py-3 text-[var(--color-ink)] font-medium whitespace-nowrap">{{ eventLabels[a.event_type] || a.event_type }}</td>
                        <td class="px-4 py-3 text-[var(--color-ink)]/70">{{ a.user || 'Khách' }}</td>
                        <td class="px-4 py-3 text-[var(--color-ink)]/70 max-w-[200px] truncate">{{ a.product_name || a.source || '—' }}</td>
                        <td class="px-4 py-3 font-mono text-[var(--color-ink)]">{{ a.voucher_code || '—' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ deviceLabels[a.device_type] || a.device_type || '—' }}</td>
                        <td class="px-4 py-3 text-[var(--color-ink)]/70 whitespace-nowrap">{{ [a.browser, a.os_name].filter(Boolean).join(' / ') || '—' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <button v-if="a.ip_address" @click="ipInput = a.ip_address; applySearch()"
                                class="font-mono text-[var(--color-muted)] hover:text-[var(--color-accent)] hover:underline transition">
                                {{ a.ip_address }}
                            </button>
                            <span v-else class="text-[var(--color-muted)]">—</span>
                        </td>
                        <td class="px-4 py-3 text-[var(--color-ink)]/70 whitespace-nowrap">{{ [a.city, a.country].filter(Boolean).join(', ') || '—' }}</td>
                        <td class="px-4 py-3 text-[var(--color-ink)]/70 whitespace-nowrap">{{ a.traffic_source ? trafficSourceLabel(a.traffic_source) : '—' }}</td>
                    </tr>
                    <tr v-if="!activities?.data?.length">
                        <td colspan="10" class="px-6 py-10 text-center text-[var(--color-muted)]">Chưa có hoạt động nào.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-center gap-3 mt-4" v-if="activities?.prev_page_url || activities?.next_page_url">
            <button @click="goPage(activities.prev_page_url)" :disabled="!activities.prev_page_url"
                class="flex-1 md:flex-none px-4 py-2.5 md:py-2 rounded-xl text-sm font-semibold bg-[var(--color-surface)] border border-[var(--color-line)] disabled:opacity-40 disabled:cursor-not-allowed">
                ← Trước
            </button>
            <span class="flex-none text-xs text-[var(--color-muted)] tabular-nums">
                {{ activities?.current_page }} / {{ activities?.last_page }}
            </span>
            <button @click="goPage(activities.next_page_url)" :disabled="!activities.next_page_url"
                class="flex-1 md:flex-none px-4 py-2.5 md:py-2 rounded-xl text-sm font-semibold bg-[var(--color-surface)] border border-[var(--color-line)] disabled:opacity-40 disabled:cursor-not-allowed">
                Sau →
            </button>
        </div>
    </AdminLayout>
</template>
