<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import VoucherOfferCard from '@/Components/VoucherOfferCard.vue'

/**
 * Kho mã giảm giá toàn sàn (/ma-giam-gia).
 *
 * Trang đầu do server trả sẵn trong `initial` — không gọi thêm một lượt XHR nữa mới có gì
 * hiện. Từ trang 2 mới gọi /api/vouchers khi khách cuộn tới đáy.
 *
 * Đổi sàn / gõ tìm kiếm KHÔNG dùng Inertia visit mà gọi thẳng axios rồi replaceState: giữ
 * nguyên vị trí cuộn và không dựng lại cả trang cho một thao tác lọc, trong khi URL vẫn
 * đúng để chia sẻ hoặc F5.
 */
const props = defineProps({
    initial: { type: Object, required: true },
    filters: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    lastSyncedAt: { type: String, default: null },
})

const platformTabs = [
    { key: 'all', label: 'Tất cả sàn' },
    { key: 'shopee', label: 'Shopee' },
    { key: 'shopeefood', label: 'ShopeeFood' },
    { key: 'lazada', label: 'Lazada' },
    { key: 'tiktok', label: 'TikTok Shop' },
    { key: 'tiki', label: 'Tiki' },
]

const platform = ref(props.filters.platform)
const keyword = ref(props.filters.keyword)

const items = ref([...props.initial.items])
const page = ref(props.initial.pagination.page)
const total = ref(props.initial.pagination.total)
const hasMore = ref(props.initial.pagination.hasMore)
const loading = ref(false)
const failed = ref(false)

// Một bộ đếm giây duy nhất cho mọi thẻ — xem VoucherOfferCard.
const now = ref(Date.now())
let ticker = null

const sentinel = ref(null)
let observer = null
let searchTimer = null
// Mỗi lượt lọc mang một số thứ tự; response về muộn của lượt cũ bị bỏ qua. Không có nó thì
// gõ nhanh vài ký tự là kết quả của từ khoá cũ đáp xuống sau và đè lên kết quả đúng.
let requestId = 0

// Chỉ hiện tab của sàn thật sự có mã, trừ 'Tất cả sàn' luôn hiện. Bày một tab bấm vào
// trống trơn thì khách tưởng trang hỏng.
const visibleTabs = computed(() =>
    platformTabs.filter(tab => tab.key === 'all' || (props.counts[tab.key] ?? 0) > 0),
)

// Đang chỉ có một sàn thì bộ lọc sàn không lọc được gì — giấu đi thay vì bày hai nút
// cho ra cùng một kết quả.
const showPlatformFilter = computed(() => visibleTabs.value.length > 2)

// Tiêu đề và mô tả dựng TỪ SÀN ĐANG CÓ MÃ THẬT, không đặt cứng. Đặt cứng "Shopee, Lazada,
// TikTok" là trang hứa ba sàn trong khi config mới bật một — khách bấm vào tìm Lazada
// không thấy gì, và bọ tìm kiếm ghi nhận một trang nói sai về chính nó.
const platformNames = computed(() => visibleTabs.value.filter(t => t.key !== 'all').map(t => t.label))

const pageTitle = computed(() =>
    platformNames.value.length ? `Mã giảm giá ${platformNames.value.join(', ')}` : 'Mã giảm giá',
)

const syncedLabel = computed(() => {
    if (!props.lastSyncedAt) return null

    return new Date(props.lastSyncedAt).toLocaleString('vi-VN', {
        hour: '2-digit',
        minute: '2-digit',
        day: '2-digit',
        month: '2-digit',
    })
})

function queryParams(nextPage) {
    return {
        page: nextPage,
        page_size: props.initial.pagination.pageSize,
        platform: platform.value,
        keyword: keyword.value,
    }
}

async function fetchPage(nextPage, { replace = false } = {}) {
    if (loading.value) return

    loading.value = true
    failed.value = false
    const ticket = ++requestId

    try {
        const { data } = await axios.get('/api/vouchers', { params: queryParams(nextPage) })

        if (ticket !== requestId) return

        items.value = replace ? data.items : [...items.value, ...data.items]
        page.value = data.pagination.page
        total.value = data.pagination.total
        hasMore.value = data.pagination.hasMore
    } catch {
        if (ticket === requestId) failed.value = true
    } finally {
        if (ticket === requestId) loading.value = false
    }
}

function applyFilters() {
    const params = new URLSearchParams()
    if (platform.value !== 'all') params.set('platform', platform.value)
    if (keyword.value) params.set('keyword', keyword.value)

    const qs = params.toString()
    window.history.replaceState({}, '', qs ? `/ma-giam-gia?${qs}` : '/ma-giam-gia')

    fetchPage(1, { replace: true })
}

function selectPlatform(key) {
    if (platform.value === key) return

    platform.value = key
    applyFilters()
}

watch(keyword, () => {
    clearTimeout(searchTimer)
    // 350ms: đủ để người gõ xong một từ mà chưa thấy trang đứng hình.
    searchTimer = setTimeout(applyFilters, 350)
})

onMounted(() => {
    ticker = setInterval(() => (now.value = Date.now()), 1000)

    // rootMargin 400px: nạp trước khi khách chạm đáy, để cuộn không khựng lại chờ.
    observer = new IntersectionObserver(
        entries => {
            if (entries[0].isIntersecting && hasMore.value && !loading.value && !failed.value) {
                fetchPage(page.value + 1)
            }
        },
        { rootMargin: '400px' },
    )

    if (sentinel.value) observer.observe(sentinel.value)
})

onBeforeUnmount(() => {
    clearInterval(ticker)
    clearTimeout(searchTimer)
    observer?.disconnect()
})
</script>

<template>
    <Head>
        <title>{{ pageTitle }} mới nhất</title>
        <meta
            name="description"
            :content="`Tổng hợp ${pageTitle.toLowerCase()} mới nhất: mã giảm giá, freeship và hoàn xu đang còn hiệu lực. Bấm lấy mã là mã tự lưu vào tài khoản, dùng ngay khi đặt hàng.`"
        />
    </Head>

    <AppLayout>
        <div class="max-w-5xl mx-auto px-4 py-8 pb-28">
            <header class="mb-6">
                <h1 class="text-2xl md:text-4xl font-extrabold text-[var(--color-ink)] mb-3">
                    {{ pageTitle }}
                </h1>
                <p class="text-sm md:text-base text-[var(--color-muted)] leading-relaxed max-w-2xl">
                    Mã giảm giá, freeship và hoàn xu mới nhất. Bấm “Lấy mã” là mã tự lưu vào tài
                    khoản của bạn — không phải chép tay rồi dán lại lúc thanh toán.
                </p>
                <p v-if="syncedLabel" class="text-xs text-[var(--color-muted)] mt-2">
                    Cập nhật lúc {{ syncedLabel }}
                </p>
            </header>

            <!-- Bộ lọc -->
            <section class="card-glass rounded-2xl p-4 mb-6 space-y-4">
                <div v-if="showPlatformFilter">
                    <p class="text-xs uppercase tracking-wide font-bold text-[var(--color-muted)] mb-2">Sàn</p>
                    <!-- Chiều cao nút đặt bằng min-h-[44px], không bằng py: đây là hàng bấm
                         được, và cả trang đang dùng chung một ngưỡng chạm.
                         Tab đang chọn KHÔNG dùng btn-fire nữa: bên dưới đã có vài chục nút
                         "Lấy mã →" cũng màu lửa, thêm một ngọn nữa ở bộ lọc thì không ngọn
                         nào còn nghĩa. Dùng nav-pill--active — đúng cái Home.vue đang dùng
                         cho cùng vai trò "tab sàn đang chọn". -->
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="tab in visibleTabs"
                            :key="tab.key"
                            type="button"
                            :class="platform === tab.key
                                ? 'nav-pill--active'
                                : 'bg-[var(--color-surface)] border-[var(--color-line)]'"
                            class="nav-pill focus-ring inline-flex items-center justify-center min-h-[44px] px-4 rounded-xl text-sm font-semibold"
                            @click="selectPlatform(tab.key)"
                        >
                            {{ tab.label }}
                        </button>
                    </div>
                </div>

                <!-- Bỏ: đổi màu viền thôi thì người dùng bàn phím gần như
                     không thấy ô nào đang được chọn. .focus-ring là viền focus dùng chung. -->
                <label class="sr-only" for="voucher-search">Tìm mã giảm giá</label>
                <input
                    id="voucher-search"
                    v-model="keyword"
                    type="search"
                    placeholder="Tìm theo mã, mô tả hoặc tên shop..."
                    class="focus-ring w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-surface)] px-4 min-h-[48px] text-sm text-[var(--color-ink)] placeholder:text-[var(--color-muted)] focus:border-[var(--color-accent)]"
                />
            </section>

            <p v-if="total" class="num text-xs text-[var(--color-muted)] mb-3">
                {{ total }} mã đang có hiệu lực
            </p>

            <!-- Danh sách mã -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <VoucherOfferCard
                    v-for="offer in items"
                    :key="offer.id"
                    :offer="offer"
                    :now="now"
                />
            </div>

            <p v-if="!items.length && !loading" class="text-center text-[var(--color-muted)] text-sm py-12">
                Không tìm thấy mã nào khớp. Thử đổi sàn hoặc xoá bớt từ khoá nhé!
            </p>

            <!-- Mốc nạp thêm. Luôn nằm trong DOM để observer bám được ngay từ lúc mở trang. -->
            <div ref="sentinel" class="h-4"></div>

            <p v-if="loading" class="text-center text-sm text-[var(--color-muted)] py-6">Đang tải thêm mã...</p>

            <!-- Hỏng thì dừng cuộn vô tận và đưa nút bấm lại: để observer tự thử tiếp là gọi
                 lại liên tục trong khi mạng đang lỗi. -->
            <div v-if="failed" class="text-center py-6">
                <p class="text-sm text-[var(--color-muted)] mb-3">Không tải được thêm mã. Kiểm tra kết nối rồi thử lại nhé.</p>
                <button type="button" class="btn-fire inline-flex items-center justify-center min-h-[44px] px-5 rounded-xl text-sm font-bold" @click="fetchPage(page + 1)">
                    Thử lại
                </button>
            </div>

            <p v-else-if="!hasMore && items.length" class="text-center text-xs text-[var(--color-muted)] py-6">
                Đã hiện hết mã đang có hiệu lực.
            </p>
        </div>
    </AppLayout>
</template>
