<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AccountLayout from '@/Layouts/AccountLayout.vue'
import ChatComposer from '@/Components/ChatComposer.vue'
import ChatMessages from '@/Components/ChatMessages.vue'

const props = defineProps({
    messages: { type: Array, default: () => [] },
    meta: { type: Object, default: () => ({}) },
    // Khách bấm "Hỏi về đơn này" ở /don-hang → tới đây kèm ?don=<mã đơn>.
    context: { type: Object, default: null },
})

const list = ref(null)
const composer = ref(null)
// Giữ trong state riêng chứ không đọc thẳng props: gửi xong là gỡ ghim, mà prop thì vẫn còn
// nguyên cho tới lần tải trang đầy đủ kế tiếp.
const pinnedOrder = ref(props.context)

// Không có WebSocket trong dự án này (xem ChatService): trong lúc trang đang mở thì cứ vài giây
// hỏi lại server một lần, chỉ xin đúng hai prop của hội thoại chứ không tải lại cả trang.
const POLL_MS = 5000
let timer = null

function poll() {
    // Tab bị ẩn (khách chuyển sang tab khác, khoá màn hình) thì ngừng hỏi — họ không nhìn, mà
    // điện thoại vẫn tốn pin và mạng. Mở lại tab là nhịp kế tiếp chạy tiếp.
    if (document.visibilityState !== 'visible' || composer.value?.busy) return

    router.reload({
        only: ['messages', 'meta'],
        // Nhịp poll kiêm luôn việc báo "đang gõ" — không tốn thêm request nào, xem ChatService.
        // Luôn gửi cả 0 lẫn 1: Inertia GỘP data vào query string đang có, nên bỏ trống khi ngừng
        // gõ thì `typing=1` của nhịp trước còn nguyên trong URL và bên kia thấy "đang gõ" mãi.
        data: { typing: composer.value?.isTyping() ? 1 : 0 },
        // replace: nếu không, mỗi nhịp poll đổi query string là một mục mới trong lịch sử trình
        // duyệt — bấm Back một cái là kẹt trong hàng chục nhịp poll cũ.
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

// Tin mới về (của mình hoặc của admin) thì kéo xuống đáy — đang đọc dở phía trên mà bị giật
// xuống thì khó chịu, nhưng ở đây khung chat cao cố định và tin mới luôn là thứ cần thấy.
watch(() => props.messages.length, scrollToBottom)
watch(() => props.meta?.peer_typing, scrollToBottom)

function send(payload) {
    // Chỉ gắn key nào thật sự có giá trị: gửi null qua FormData thì nó thành chuỗi rỗng, và lúc
    // đó việc request còn hợp lệ hay không lại phụ thuộc vào middleware ConvertEmptyStringsToNull.
    const data = { body: payload.body }
    if (payload.image) data.image = payload.image
    if (pinnedOrder.value) data.order_id = pinnedOrder.value.order_id

    router.post('/ho-tro/gui', data, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            composer.value?.reset()
            pinnedOrder.value = null
        },
        onError: (errors) => composer.value?.fail(errors),
        onFinish: () => composer.value?.done(),
    })
}

const peerTyping = computed(() => props.meta?.peer_typing ?? false)
</script>

<template>
    <Head title="Hỗ trợ" />
    <AccountLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-extrabold text-[var(--color-ink)]">Chat với hỗ trợ</h1>
                <p class="text-sm text-[var(--color-muted)] mt-1">
                    Hỏi về đơn hàng, tiền hoàn hay lệnh rút. Gửi kèm ảnh chụp màn hình cũng được. Admin trả lời
                    trong giờ làm việc — bạn sẽ nhận thông báo 🔔 khi có trả lời, không cần ngồi đợi ở đây.
                </p>
            </div>

            <!-- Khung chat CỐ Ý cao theo vh chứ không giãn hết màn hình: AppLayout giờ là flex-col
                 và có chân trang ở cuối, nên một khung "toàn màn hình" ở đây sẽ đẩy chân trang
                 ra ngoài tầm với và sinh hai vùng cuộn lồng nhau. 45–60vh trên máy 375px là
                 khoảng 300–400px — đủ thấy vài tin, mà cuộn trang vẫn tới được chân trang. -->
            <div class="card overflow-hidden flex flex-col">
                <div ref="list" class="flex-1 overflow-y-auto px-4 py-5 min-h-[45vh] max-h-[60vh]">
                    <ChatMessages
                        :messages="messages"
                        :viewer-is-admin="false"
                        :peer-read-ts="meta?.peer_read_ts"
                        :peer-typing="peerTyping"
                        peer-label="Hỗ trợ"
                        own-label="Bạn"
                        empty-text="Chưa có tin nhắn nào. Gõ câu hỏi của bạn ở dưới, admin sẽ trả lời ngay khi thấy."
                    />
                </div>

                <ChatComposer
                    ref="composer"
                    placeholder="Nhập tin nhắn..."
                    :pinned-order="pinnedOrder"
                    @unpin="pinnedOrder = null"
                    @send="send"
                />
            </div>
        </div>
    </AccountLayout>
</template>
