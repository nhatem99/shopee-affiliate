<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import FlashSaleItemCard from '@/Components/FlashSaleItemCard.vue'

/**
 * Trang Flash Sale (/flashsale) — cùng cấu trúc cuộn vô tận với /ma-giam-gia, xem
 * Vouchers.vue cho lý do từng quyết định (trang đầu server trả sẵn, lọc gọi thẳng
 * axios + replaceState, requestId chặn response cũ đáp muộn).
 *
 * Khác biệt: lọc theo KHUNG GIỜ (`slot`) thay vì sàn, và mỗi khung giờ có TRẠNG THÁI
 * (đang diễn ra/sắp tới/đã qua) tính từ giờ hiện tại — xem slotStatus().
 */
const props = defineProps({
    initial: { type: Object, required: true },
    filters: { type: Object, required: true },
    slots: { type: Array, default: () => [] },
    lastSyncedAt: { type: String, default: null },
})

const slot = ref(props.filters.slot)
const keyword = ref(props.filters.keyword)

const items = ref([...props.initial.items])
const page = ref(props.initial.pagination.page)
const total = ref(props.initial.pagination.total)
const hasMore = ref(props.initial.pagination.hasMore)
const loading = ref(false)
const failed = ref(false)

const now = ref(Date.now())
let ticker = null

const sentinel = ref(null)
let observer = null
let searchTimer = null
let requestId = 0

/**
 * Trạng thái một khung giờ TẠI THỜI ĐIỂM HIỆN TẠI, coi mọi khung giờ đều thuộc HÔM NAY
 * — nguồn không kèm ngày (chỉ "HH:MM"), và tiêu đề trang nguồn ghi "cập nhật liên tục"
 * nên đây là giả định hợp lý, không phải số chắc chắn. Dùng để TÔ MÀU/nhãn, không dùng
 * để ẩn bớt sản phẩm — khách vẫn thấy đủ, chỉ biết suất nào đáng bấm trước.
 */
function slotStatus(s) {
    if (!props.slots.length) return 'current'

    const idx = props.slots.indexOf(s)
    const toToday = t => {
        const [h, m] = t.split(':').map(Number)
        const d = new Date()
        d.setHours(h, m, 0, 0)
        return d.getTime()
    }

    const start = toToday(s)
    const nextSlot = props.slots[idx + 1]
    const end = nextSlot ? toToday(nextSlot) : new Date().setHours(23, 59, 59, 999)

    if (now.value < start) return 'upcoming'
    if (now.value >= start && now.value < end) return 'current'

    return 'past'
}

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
        slot: slot.value,
        keyword: keyword.value,
    }
}

async function fetchPage(nextPage, { replace = false } = {}) {
    if (loading.value) return

    loading.value = true
    failed.value = false
    const ticket = ++requestId

    try {
        const { data } = await axios.get('/api/flashsale', { params: queryParams(nextPage) })

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
    if (slot.value !== 'all') params.set('slot', slot.value)
    if (keyword.value) params.set('keyword', keyword.value)

    const qs = params.toString()
    window.history.replaceState({}, '', qs ? `/flashsale?${qs}` : '/flashsale')

    fetchPage(1, { replace: true })
}

function selectSlot(s) {
    if (slot.value === s) return

    slot.value = s
    applyFilters()
}

watch(keyword, () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(applyFilters, 350)
})

onMounted(() => {
    // Không cần đếm giây như voucher (không có countdown theo mili-giây) — chỉ cần biết
    // đã sang khung giờ khác hay chưa, nên 30s/lần là đủ mà đỡ tốn hơn 1s/lần.
    ticker = setInterval(() => (now.value = Date.now()), 30000)

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
        <title>Flash Sale Shopee hôm nay — giảm sâu theo khung giờ</title>
        <meta
            name="description"
            content="Tổng hợp sản phẩm Flash Sale Shopee giảm sâu theo từng khung giờ trong ngày, cập nhật liên tục. Bấm Mua ngay để mở thẳng sản phẩm."
        />
    </Head>

    <AppLayout>
        <div class="max-w-5xl mx-auto px-4 py-8 pb-28">
            <header class="mb-6">
                <h1 class="text-2xl md:text-4xl font-extrabold text-[var(--color-ink)] mb-3">
                    ⚡ Flash Sale hôm nay
                </h1>
                <p class="text-sm md:text-base text-[var(--color-muted)] leading-relaxed max-w-2xl">
                    Sản phẩm giảm sâu theo từng khung giờ trong ngày. Bấm “Mua ngay” để mở
                    thẳng Shopee — không cần dán link, không cần tìm mã.
                </p>
                <p v-if="syncedLabel" class="text-xs text-[var(--color-muted)] mt-2">
                    Cập nhật lúc {{ syncedLabel }}
                </p>
            </header>

            <!-- Bộ lọc -->
            <section class="card-glass rounded-2xl p-4 mb-6 space-y-4">
                <div v-if="slots.length > 1">
                    <p class="text-xs uppercase tracking-wide font-bold text-[var(--color-muted)] mb-2">Khung giờ</p>
                    <!-- Chiều cao nút đặt bằng min-h-[44px], không bằng py — cùng ngưỡng chạm
                         với mọi hàng bấm được khác trong trang.
                         Khung giờ đang chọn KHÔNG dùng btn-fire nữa: lưới bên dưới đã có
                         hàng chục nút "Mua ngay →" màu lửa, thêm một ngọn ở bộ lọc thì mắt
                         khách không biết đâu là việc cần làm. nav-pill--active là đúng cái
                         Home.vue dùng cho cùng vai trò "tab đang chọn". -->
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            :class="slot === 'all'
                                ? 'nav-pill--active'
                                : 'bg-[var(--color-surface)] border-[var(--color-line)]'"
                            class="nav-pill focus-ring inline-flex items-center justify-center min-h-[44px] px-4 rounded-xl text-sm font-semibold"
                            @click="selectSlot('all')"
                        >
                            Tất cả
                        </button>
                        <button
                            v-for="s in slots"
                            :key="s"
                            type="button"
                            :class="slot === s
                                ? 'nav-pill--active'
                                : 'bg-[var(--color-surface)] border-[var(--color-line)]'"
                            class="num nav-pill focus-ring relative inline-flex items-center justify-center min-h-[44px] px-4 rounded-xl text-sm font-semibold"
                            @click="selectSlot(s)"
                        >
                            {{ s }}
                            <!-- Chấm "khung giờ này đang chạy": màu tiền có sẵn cả bản sáng
                                 lẫn tối, emerald-500 gõ thẳng thì chỉ đúng ở một chế độ. -->
                            <span v-if="slotStatus(s) === 'current'" class="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-[var(--color-money)] border-2 border-[var(--color-bg)]"></span>
                        </button>
                    </div>
                </div>

                <!-- Bỏ: đổi màu viền thôi thì người dùng bàn phím gần như
                     không thấy ô nào đang được chọn. .focus-ring là viền focus dùng chung. -->
                <label class="sr-only" for="flashsale-search">Tìm sản phẩm Flash Sale</label>
                <input
                    id="flashsale-search"
                    v-model="keyword"
                    type="search"
                    placeholder="Tìm theo tên sản phẩm..."
                    class="focus-ring w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-surface)] px-4 min-h-[48px] text-sm text-[var(--color-ink)] placeholder:text-[var(--color-muted)] focus:border-[var(--color-accent)]"
                />
            </section>

            <p v-if="total" class="num text-xs text-[var(--color-muted)] mb-3">
                {{ total }} sản phẩm đang giảm sâu
            </p>

            <!-- Danh sách sản phẩm -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <FlashSaleItemCard
                    v-for="item in items"
                    :key="item.id"
                    :item="item"
                    :status="slotStatus(item.timeSlot)"
                />
            </div>

            <p v-if="!items.length && !loading" class="text-center text-[var(--color-muted)] text-sm py-12">
                Không tìm thấy sản phẩm nào khớp. Thử đổi khung giờ hoặc xoá bớt từ khoá nhé!
            </p>

            <div ref="sentinel" class="h-4"></div>

            <p v-if="loading" class="text-center text-sm text-[var(--color-muted)] py-6">Đang tải thêm sản phẩm...</p>

            <div v-if="failed" class="text-center py-6">
                <p class="text-sm text-[var(--color-muted)] mb-3">Không tải được thêm sản phẩm. Kiểm tra kết nối rồi thử lại nhé.</p>
                <button type="button" class="btn-fire inline-flex items-center justify-center min-h-[44px] px-5 rounded-xl text-sm font-bold" @click="fetchPage(page + 1)">
                    Thử lại
                </button>
            </div>

            <p v-else-if="!hasMore && items.length" class="text-center text-xs text-[var(--color-muted)] py-6">
                Đã hiện hết sản phẩm đang giảm sâu.
            </p>
        </div>
    </AppLayout>
</template>
