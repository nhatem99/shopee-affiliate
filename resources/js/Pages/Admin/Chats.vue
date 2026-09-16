<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import ChatComposer from '@/Components/ChatComposer.vue'
import ChatMessages from '@/Components/ChatMessages.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps({
    conversations: Object, // paginator
    active: { type: Object, default: null },
})

const toast = useToast()
const list = ref(null)
const composer = ref(null)

const items = computed(() => props.conversations?.data ?? [])
const messages = computed(() => props.active?.messages ?? [])

// Cùng cách làm với trang chat của khách (xem Chat.vue): không WebSocket, chỉ xin lại đúng mấy
// prop này. Nhịp thưa hơn bên khách vì admin thường mở tab này cả ngày.
const POLL_MS = 10000
let timer = null

function poll() {
    if (document.visibilityState !== 'visible' || composer.value?.busy) return

    router.reload({
        // 'chat' là badge ở sidebar — xin luôn ở đây để AdminLayout khỏi poll chồng lên trang này.
        only: ['conversations', 'active', 'chat'],
        // Luôn gửi cả 0 lẫn 1 — xem lý do ở Chat.vue.
        data: { typing: composer.value?.isTyping() ? 1 : 0 },
        replace: true,
        preserveScroll: true,
        preserveState: true,
    })
}

onMounted(() => {
    timer = setInterval(poll, POLL_MS)
    scrollToBottom()
})
onBeforeUnmount(() => clearInterval(timer))

function scrollToBottom() {
    nextTick(() => {
        if (list.value) list.value.scrollTop = list.value.scrollHeight
    })
}

watch(() => messages.value.length, scrollToBottom)
watch(() => props.active?.meta?.peer_typing, scrollToBottom)
// Đổi sang hội thoại khác thì ô soạn phải trống — không để lời đang gõ dở cho khách A trôi sang
// khung của khách B.
watch(() => props.active?.id, () => { composer.value?.reset(); scrollToBottom() })

function send(payload) {
    if (!props.active) return

    // Bỏ key rỗng đi — xem lý do ở Chat.vue.
    const data = { body: payload.body }
    if (payload.image) data.image = payload.image

    router.post(`/admin/chats/${props.active.id}/reply`, data, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => composer.value?.reset(),
        onError: (errors) => composer.value?.fail(errors),
        onFinish: () => composer.value?.done(),
    })
}

// Thông báo trên máy tính (AdminLayout bắn khi số khách chờ tăng). Trình duyệt chỉ cho xin quyền
// từ một cú bấm thật, nên nút xin quyền nằm đây chứ không tự bật lên.
const canAskNotify = ref(false)

function refreshNotifyState() {
    canAskNotify.value = typeof Notification !== 'undefined' && Notification.permission === 'default'
}

onMounted(refreshNotifyState)

function askNotify() {
    Notification.requestPermission().then((result) => {
        refreshNotifyState()
        if (result === 'granted') toast.success('Đã bật — có khách nhắn là hiện thông báo, miễn là còn mở tab quản trị.')
        else toast.info('Bạn đã từ chối. Bật lại trong phần quyền của trình duyệt cho trang này.')
    })
}
</script>

<template>
    <Head title="Admin — Hộp thư hỗ trợ" />
    <AdminLayout>
        <template #title>Hộp thư hỗ trợ</template>

        <div v-if="canAskNotify" class="mb-4 flex flex-wrap items-center gap-3 rounded-2xl border border-[var(--color-line)] bg-[var(--color-surface)] px-4 py-3">
            <span class="text-sm text-[var(--color-muted)] flex-1 min-w-[200px]">
                Bật thông báo trên máy tính để biết có khách nhắn mà không phải ngồi canh trang này.
            </span>
            <button type="button" @click="askNotify" class="btn-fire px-4 py-2 rounded-xl text-sm">🔔 Bật thông báo</button>
        </div>

        <div class="flex gap-4 items-start">
            <!-- Danh sách hội thoại. Trên điện thoại ẩn đi khi đang mở một hội thoại: hai cột
                 cạnh nhau ở 375px thì cột nào cũng không đọc được. -->
            <div
                class="w-full md:w-[320px] md:flex-none bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] overflow-hidden"
                :class="active ? 'hidden md:block' : ''"
            >
                <div class="px-4 py-3 border-b border-[var(--color-line)] text-sm font-bold text-[var(--color-ink)]">
                    Khách đang nhắn
                </div>

                <ul v-if="items.length" class="divide-y divide-[var(--color-line)] max-h-[70vh] overflow-y-auto">
                    <li v-for="c in items" :key="c.id">
                        <Link
                            :href="`/admin/chats/${c.id}`"
                            preserve-scroll
                            class="block px-4 py-3 hover:bg-[var(--color-peach-soft)] transition-colors"
                            :class="active?.id === c.id ? 'bg-[var(--color-peach-soft)]' : ''"
                        >
                            <div class="flex items-center gap-2">
                                <span class="font-semibold text-sm text-[var(--color-ink)] truncate flex-1">{{ c.name }}</span>
                                <span
                                    v-if="c.unread"
                                    class="flex-none min-w-[18px] h-[18px] px-1 rounded-full bg-[var(--color-accent)] text-white text-[10px] font-extrabold flex items-center justify-center"
                                >{{ c.unread > 9 ? '9+' : c.unread }}</span>
                            </div>
                            <p class="text-xs text-[var(--color-muted)] truncate mt-0.5">{{ c.preview }}</p>
                            <p class="text-[11px] text-[var(--color-muted)] mt-0.5">{{ c.ago }}</p>
                        </Link>
                    </li>
                </ul>
                <p v-else class="px-4 py-10 text-center text-sm text-[var(--color-muted)]">
                    Chưa có ai nhắn tin.
                </p>

                <div v-if="conversations?.last_page > 1" class="flex items-center justify-center gap-2 text-xs px-4 py-3 border-t border-[var(--color-line)]">
                    <Link v-if="conversations.prev_page_url" :href="conversations.prev_page_url" class="text-[var(--color-accent)] font-semibold">← Mới hơn</Link>
                    <span class="text-[var(--color-muted)]">{{ conversations.current_page }}/{{ conversations.last_page }}</span>
                    <Link v-if="conversations.next_page_url" :href="conversations.next_page_url" class="text-[var(--color-accent)] font-semibold">Cũ hơn →</Link>
                </div>
            </div>

            <!-- Khung hội thoại -->
            <div
                class="flex-1 min-w-0 bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] overflow-hidden flex flex-col"
                :class="active ? '' : 'hidden md:flex'"
            >
                <template v-if="active">
                    <div class="px-4 py-3 border-b border-[var(--color-line)] flex items-center gap-3">
                        <Link href="/admin/chats" class="md:hidden text-sm text-[var(--color-accent)] font-semibold flex-none">←</Link>
                        <div class="min-w-0">
                            <p class="font-bold text-sm text-[var(--color-ink)] truncate">{{ active.user.name }}</p>
                            <p class="text-xs text-[var(--color-muted)] truncate">{{ active.user.email }}</p>
                        </div>
                    </div>

                    <div ref="list" class="flex-1 overflow-y-auto px-4 py-5 min-h-[45vh] max-h-[60vh]">
                        <ChatMessages
                            :messages="messages"
                            :viewer-is-admin="true"
                            :peer-read-ts="active.meta?.peer_read_ts"
                            :peer-typing="active.meta?.peer_typing ?? false"
                            :peer-label="active.user.name"
                            own-label="Bạn"
                            empty-text="Chưa có tin nhắn nào trong hội thoại này."
                        />
                    </div>

                    <ChatComposer ref="composer" placeholder="Trả lời khách..." @send="send" />
                </template>

                <p v-else class="px-4 py-20 text-center text-sm text-[var(--color-muted)]">
                    Chọn một khách ở danh sách bên trái để đọc và trả lời.
                </p>
            </div>
        </div>
    </AdminLayout>
</template>
