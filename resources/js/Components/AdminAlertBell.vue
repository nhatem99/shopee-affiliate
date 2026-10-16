<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useDesktopNotify } from '@/composables/useDesktopNotify'

// Chuông ở header trang quản trị. Khác chuông của khách (NotificationBell.vue): không có danh sách
// thông báo để đánh dấu đã đọc — mỗi dòng là một loại việc khách đang chờ, xử lý xong thì số tự
// về 0. Số liệu do AdminLayout truyền vào (nó còn dùng cho badge sidebar và tiêu đề tab).
defineProps({
    // [{ key, href, icon, label, count }]
    items: { type: Array, required: true },
    total: { type: Number, required: true },
})

const open = ref(false)
const root = ref(null)
const { permission, ask } = useDesktopNotify()

function onDocClick(e) {
    if (root.value && !root.value.contains(e.target)) open.value = false
}
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            @click="open = !open"
            class="relative w-9 h-9 inline-flex items-center justify-center rounded-xl border border-[var(--color-line)] text-[var(--color-muted)] hover:text-[var(--color-ink)] hover:bg-[var(--color-peach-soft)] transition-colors"
            :aria-label="total ? `${total} việc đang chờ` : 'Việc đang chờ'"
            aria-haspopup="true"
            :aria-expanded="open"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                <path d="M13.73 21a2 2 0 0 1-3.46 0" />
            </svg>
            <span
                v-if="total"
                class="absolute -top-1.5 -right-1.5 min-w-[18px] h-[18px] px-1 rounded-full bg-[var(--color-accent)] text-white text-[10px] font-extrabold flex items-center justify-center shadow"
            >{{ total > 9 ? '9+' : total }}</span>
        </button>

        <Transition name="fade-up">
            <div
                v-if="open"
                class="absolute right-0 mt-2 w-[min(92vw,22rem)] card-glass rounded-2xl shadow-xl border border-[var(--color-line)] overflow-hidden z-50"
            >
                <div class="px-4 py-3 border-b border-[var(--color-line)] font-bold text-sm text-[var(--color-ink)]">Việc đang chờ</div>

                <ul class="divide-y divide-[var(--color-line)]">
                    <li v-for="item in items" :key="item.key">
                        <Link
                            :href="item.href"
                            @click="open = false"
                            class="flex items-center gap-3 px-4 py-3 hover:bg-[var(--color-peach-soft)] transition-colors"
                        >
                            <span class="text-xl leading-none">{{ item.icon }}</span>
                            <span
                                class="flex-1 min-w-0 text-sm"
                                :class="item.count ? 'font-semibold text-[var(--color-ink)]' : 'text-[var(--color-muted)]'"
                            >{{ item.label }}</span>
                            <span
                                v-if="item.count"
                                class="flex-none min-w-[22px] h-[22px] px-1.5 rounded-full bg-[var(--color-accent)] text-white text-xs font-extrabold flex items-center justify-center"
                            >{{ item.count }}</span>
                            <span v-else class="flex-none text-xs text-[var(--color-muted)]">0</span>
                        </Link>
                    </li>
                </ul>

                <div class="px-4 py-3 border-t border-[var(--color-line)] text-xs text-[var(--color-muted)] leading-relaxed space-y-2">
                    <button
                        v-if="permission === 'default'"
                        type="button"
                        @click="ask"
                        class="btn-fire w-full px-4 py-2 rounded-xl text-sm"
                    >🔔 Bật thông báo trên máy tính</button>
                    <p v-else-if="permission === 'granted'">✅ Đã bật thông báo trên máy tính.</p>
                    <p v-else-if="permission === 'denied'">
                        Trình duyệt đang chặn thông báo của trang này — bấm biểu tượng cạnh địa chỉ web để cho phép lại.
                    </p>
                    <!-- Thông báo máy tính chỉ chạy khi còn mở tab: chỗ hở này là việc của Zalo Bot. -->
                    <p>
                        Đóng hết tab quản trị thì web không báo được —
                        <Link href="/admin/zalo-bot" @click="open = false" class="font-semibold text-[var(--color-accent)] hover:underline">nhận báo qua Zalo</Link>.
                    </p>
                </div>
            </div>
        </Transition>
    </div>
</template>
