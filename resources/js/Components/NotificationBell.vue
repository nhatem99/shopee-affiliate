<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'

// Dữ liệu do HandleInertiaRequests chia sẻ: null với khách vãng lai và admin.
const page = usePage()
const data = computed(() => page.props.notifications)
const unread = computed(() => data.value?.unread ?? 0)
const latest = computed(() => data.value?.latest ?? [])

const open = ref(false)
const root = ref(null)

function onDocClick(e) {
    if (root.value && !root.value.contains(e.target)) open.value = false
}
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))

function readAll() {
    router.post('/thong-bao/doc-het', {}, { preserveScroll: true, preserveState: true })
}

// Bấm một dòng: server đánh dấu đã đọc rồi chuyển tới trang thông báo đó trỏ đến.
function openItem(n) {
    open.value = false
    router.post(`/thong-bao/${n.id}/doc`)
}
</script>

<template>
    <div v-if="data" ref="root" class="relative">
        <button
            type="button"
            @click="open = !open"
            class="focus-ring relative w-11 h-11 inline-flex items-center justify-center rounded-xl border border-[var(--color-line)] text-[var(--color-muted)] hover:text-[var(--color-ink)] hover:bg-[var(--color-peach-soft)] transition-colors"
            :aria-label="unread ? `${unread} thông báo chưa đọc` : 'Thông báo'"
            aria-haspopup="true"
            :aria-expanded="open"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                <path d="M13.73 21a2 2 0 0 1-3.46 0" />
            </svg>
            <span
                v-if="unread"
                class="absolute -top-1.5 -right-1.5 min-w-[18px] h-[18px] px-1 rounded-full bg-[var(--color-accent)] text-white text-[10px] font-extrabold flex items-center justify-center shadow"
            >{{ unread > 9 ? '9+' : unread }}</span>
        </button>

        <Transition name="fade-up">
            <div
                v-if="open"
                class="absolute right-0 mt-2 w-[min(92vw,22rem)] card-glass rounded-2xl shadow-xl border border-[var(--color-line)] overflow-hidden z-50"
            >
                <div class="flex items-center justify-between px-4 py-3 border-b border-[var(--color-line)]">
                    <span class="font-bold text-sm text-[var(--color-ink)]">Thông báo</span>
                    <button
                        v-if="unread"
                        type="button"
                        @click="readAll"
                        class="text-xs font-semibold text-[var(--color-accent)] hover:underline"
                    >Đánh dấu đã đọc</button>
                </div>

                <ul v-if="latest.length" class="max-h-[60vh] overflow-y-auto divide-y divide-[var(--color-line)]">
                    <li v-for="n in latest" :key="n.id">
                        <button
                            type="button"
                            @click="openItem(n)"
                            class="w-full text-left px-4 py-3 flex gap-3 hover:bg-[var(--color-peach-soft)] transition-colors"
                            :class="n.read ? '' : 'bg-[var(--color-peach-soft)]/60'"
                        >
                            <span class="text-xl leading-none mt-0.5">{{ n.icon }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-2">
                                    <span class="font-semibold text-sm text-[var(--color-ink)] truncate">{{ n.title }}</span>
                                    <span v-if="!n.read" class="w-2 h-2 rounded-full bg-[var(--color-accent)] flex-none"></span>
                                </span>
                                <span class="block text-xs text-[var(--color-muted)] leading-snug mt-0.5 line-clamp-2">{{ n.body }}</span>
                                <span class="block text-[11px] text-[var(--color-muted)] mt-1">{{ n.ago }}</span>
                            </span>
                        </button>
                    </li>
                </ul>
                <p v-else class="px-4 py-8 text-center text-sm text-[var(--color-muted)]">Chưa có thông báo nào.</p>

                <Link
                    href="/thong-bao"
                    @click="open = false"
                    class="block text-center px-4 py-3 border-t border-[var(--color-line)] text-sm font-semibold text-[var(--color-ink)] hover:bg-[var(--color-peach-soft)] transition-colors"
                >Xem tất cả thông báo</Link>
            </div>
        </Transition>
    </div>
</template>
