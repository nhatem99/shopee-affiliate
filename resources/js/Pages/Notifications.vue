<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AccountLayout from '@/Layouts/AccountLayout.vue'

const props = defineProps({
    notifications: Object, // paginator
})

const items = computed(() => props.notifications?.data ?? [])
const hasUnread = computed(() => items.value.some(n => !n.read))

function readAll() {
    router.post('/thong-bao/doc-het', {}, { preserveScroll: true })
}

function openItem(n) {
    router.post(`/thong-bao/${n.id}/doc`)
}
</script>

<template>
    <Head title="Thông báo" />
    <AccountLayout>
        <div class="space-y-6">
            <div class="flex items-end justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-extrabold text-[var(--color-ink)]">Thông báo</h1>
                    <p class="text-sm text-[var(--color-muted)] mt-1">Thưởng, hoàn tiền và tiến độ các lệnh rút của bạn.</p>
                </div>
                <!-- Nút phụ nên mang hình dáng nút phụ: viền trung tính, không dùng màu nhấn.
                     Ngọn lửa duy nhất của màn hình này để dành cho dấu CHƯA ĐỌC ở dưới — đó mới
                     là thứ khách cần nhìn ra trước tiên. -->
                <button
                    v-if="hasUnread"
                    type="button"
                    @click="readAll"
                    class="focus-ring inline-flex items-center min-h-[44px] px-4 rounded-xl border border-[var(--color-line)] text-sm font-semibold text-[var(--color-ink)] hover:bg-[var(--color-peach-soft)] transition-colors"
                >Đánh dấu tất cả đã đọc</button>
            </div>

            <div class="card overflow-hidden">
                <ul v-if="items.length" class="divide-y divide-[var(--color-line)]">
                    <li v-for="n in items" :key="n.id">
                        <!-- Viền trái 3px đánh dấu tin chưa đọc. Trước đây chưa đọc chỉ khác nhau
                             ở nền peach-soft/60 — đúng bằng nền hover của hàng ĐÃ đọc, nên rê tay
                             qua một hàng cũ là nó trông y như tin mới. Hàng đã đọc giữ viền trong
                             suốt cùng bề dày để chữ hai loại không lệch nhau 3px. -->
                        <button
                            type="button"
                            @click="openItem(n)"
                            class="focus-ring w-full text-left px-4 sm:px-5 py-4 min-h-[44px] flex gap-3 border-l-[3px] transition-colors"
                            :class="n.read
                                ? 'border-transparent hover:bg-[var(--color-peach-soft)]'
                                : 'border-[var(--color-accent)] bg-[var(--color-peach-soft)]'"
                        >
                            <span class="text-2xl leading-none mt-0.5">{{ n.icon }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-2">
                                    <span class="font-bold text-sm text-[var(--color-ink)]">{{ n.title }}</span>
                                    <span v-if="!n.read" class="w-2 h-2 rounded-full bg-[var(--color-accent)] flex-none"></span>
                                    <span v-if="!n.read" class="sr-only">(chưa đọc)</span>
                                </span>
                                <span class="block text-sm text-[var(--color-muted)] leading-snug mt-1">{{ n.body }}</span>
                                <span class="block text-xs text-[var(--color-muted)] mt-1.5 num">{{ n.created_at }} · {{ n.ago }}</span>
                            </span>
                        </button>
                    </li>
                </ul>
                <p v-else class="px-5 py-12 text-center text-sm text-[var(--color-muted)] leading-relaxed">
                    Chưa có thông báo nào. Khi có tiền vào ví hay lệnh rút được duyệt, bạn sẽ thấy ở đây.
                </p>
            </div>

            <div v-if="notifications?.last_page > 1" class="flex items-center justify-center gap-2 text-sm">
                <Link
                    v-if="notifications.prev_page_url"
                    :href="notifications.prev_page_url"
                    class="focus-ring inline-flex items-center min-h-[44px] px-4 rounded-xl border border-[var(--color-line)] font-semibold text-[var(--color-ink)] hover:bg-[var(--color-peach-soft)]"
                >← Mới hơn</Link>
                <span class="text-[var(--color-muted)] num px-2">Trang {{ notifications.current_page }}/{{ notifications.last_page }}</span>
                <Link
                    v-if="notifications.next_page_url"
                    :href="notifications.next_page_url"
                    class="focus-ring inline-flex items-center min-h-[44px] px-4 rounded-xl border border-[var(--color-line)] font-semibold text-[var(--color-ink)] hover:bg-[var(--color-peach-soft)]"
                >Cũ hơn →</Link>
            </div>
        </div>
    </AccountLayout>
</template>
