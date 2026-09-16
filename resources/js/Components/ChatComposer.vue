<script setup>
import { onBeforeUnmount, ref } from 'vue'
import { useToast } from '@/composables/useToast'

// Ô soạn tin dùng chung cho trang khách và hộp thư admin (xem ChatMessages.vue cùng lý do).
// Component này KHÔNG tự gửi: nó phát sự kiện `send` kèm { body, image } và để trang gọi router
// — trang khách gửi kèm mã đơn, trang admin gửi tới hội thoại đang mở, hai URL khác nhau.
defineProps({
    placeholder: { type: String, default: 'Nhập tin nhắn...' },
    // { order_id, product_name } — thẻ ghim đơn hàng nằm ngay trên ô soạn, chỉ trang khách dùng.
    pinnedOrder: { type: Object, default: null },
})

const emit = defineEmits(['send', 'unpin'])

const toast = useToast()
const body = ref('')
const image = ref(null)
const imagePreview = ref(null)
const sending = ref(false)
const fileInput = ref(null)

// Mốc "vừa gõ xong" để nhịp poll của trang biết có nên báo "đang gõ" hay không. Dùng biến thường
// chứ không ref: giá trị này chỉ được ĐỌC lúc poll, không có gì trên màn hình phụ thuộc vào nó.
let typedAt = 0
const TYPING_WINDOW_MS = 8000

const MAX_IMAGE_BYTES = 5 * 1024 * 1024

function onType() {
    typedAt = Date.now()
}

function pickImage() {
    fileInput.value?.click()
}

function onFilePicked(event) {
    const file = event.target.files?.[0]
    event.target.value = '' // chọn lại đúng file vừa bỏ ra vẫn phải kích hoạt change

    if (!file) return

    if (!file.type.startsWith('image/')) {
        toast.error('Chỉ gửi được ảnh (jpg, png, webp).')

        return
    }

    // Chặn ngay trên máy khách: để server từ chối thì khách đã ngồi chờ upload xong 20MB qua
    // mạng điện thoại rồi mới biết là không được.
    if (file.size > MAX_IMAGE_BYTES) {
        toast.error('Ảnh tối đa 5MB. Chụp màn hình thường nhẹ hơn nhiều, thử gửi ảnh khác nhé.')

        return
    }

    clearPreview()
    image.value = file
    imagePreview.value = URL.createObjectURL(file)
}

function clearPreview() {
    if (imagePreview.value) URL.revokeObjectURL(imagePreview.value)
    imagePreview.value = null
}

function removeImage() {
    clearPreview()
    image.value = null
}

onBeforeUnmount(clearPreview)

function submit() {
    const text = body.value.trim()

    if (sending.value || (!text && !image.value)) return

    sending.value = true
    emit('send', { body: text, image: image.value })
}

defineExpose({
    // Trang đọc mấy cái này ở mỗi nhịp poll và sau mỗi lần gửi.
    busy: sending,
    // Hàm chứ không computed: điều kiện phụ thuộc Date.now(), mà computed thì nhớ kết quả cũ cho
    // tới khi có dependency reactive nào đổi — tức "đang gõ" sẽ kẹt ở true mãi.
    isTyping: () => body.value.trim() !== '' && Date.now() - typedAt < TYPING_WINDOW_MS,
    reset: () => {
        body.value = ''
        removeImage()
    },
    fail: (errors) => toast.error(errors?.body || errors?.image || 'Không gửi được tin nhắn, vui lòng thử lại.'),
    done: () => { sending.value = false },
})
</script>

<template>
    <div class="border-t border-[var(--color-line)]">
        <div v-if="pinnedOrder" class="px-3 pt-3 flex items-start gap-2">
            <div class="flex-1 min-w-0 rounded-xl bg-[var(--color-peach-soft)] px-3 py-2">
                <p class="text-[11px] font-bold text-[var(--color-accent)]">🧾 Đang hỏi về đơn {{ pinnedOrder.order_id }}</p>
                <p v-if="pinnedOrder.product_name" class="text-xs text-[var(--color-muted)] truncate">{{ pinnedOrder.product_name }}</p>
            </div>
            <button
                type="button"
                @click="emit('unpin')"
                aria-label="Bỏ ghim đơn hàng"
                class="flex-none w-7 h-7 rounded-lg flex items-center justify-center text-[var(--color-muted)] hover:bg-[var(--color-peach-soft)] hover:text-[var(--color-ink)] transition"
            >✕</button>
        </div>

        <div v-if="imagePreview" class="px-3 pt-3">
            <div class="relative inline-block">
                <img :src="imagePreview" alt="Ảnh sắp gửi" class="h-20 w-auto rounded-xl border border-[var(--color-line)]" />
                <button
                    type="button"
                    @click="removeImage"
                    aria-label="Bỏ ảnh"
                    class="absolute -top-2 -right-2 w-6 h-6 rounded-full bg-[var(--color-ink)] text-[var(--color-surface)] text-xs font-bold flex items-center justify-center shadow"
                >✕</button>
            </div>
        </div>

        <div class="p-3 flex items-end gap-2">
            <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onFilePicked" />
            <button
                type="button"
                @click="pickImage"
                aria-label="Đính kèm ảnh"
                title="Đính kèm ảnh"
                class="flex-none w-10 h-10 rounded-xl border border-[var(--color-line)] text-[var(--color-muted)] hover:text-[var(--color-accent)] hover:border-[var(--color-accent)] transition flex items-center justify-center"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5">
                    <path d="M21.44 11.05 12.25 20.24a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" />
                </svg>
            </button>

            <!-- Enter gửi, Shift+Enter xuống dòng: đúng thói quen gõ chat, và phần lớn tin nhắn
                 hỗ trợ chỉ một dòng. -->
            <textarea
                v-model="body"
                rows="1"
                maxlength="2000"
                :placeholder="placeholder"
                @input="onType"
                @keydown.enter.exact.prevent="submit"
                class="flex-1 min-w-0 resize-none px-4 py-2.5 rounded-xl border border-[var(--color-line)] bg-[var(--color-bg)] text-sm text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]"
            ></textarea>

            <button
                type="button"
                @click="submit"
                :disabled="sending || (!body.trim() && !image)"
                class="btn-fire px-5 py-2.5 rounded-xl text-sm whitespace-nowrap disabled:opacity-60"
            >{{ sending ? 'Đang gửi...' : 'Gửi' }}</button>
        </div>
    </div>
</template>
