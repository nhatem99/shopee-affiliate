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
        <!-- Thẻ ghim là THÔNG TIN chứ không phải nút bấm nên bỏ màu nhấn, về nền .panel trung
             tính — màu lửa trong ô soạn để dành đúng cho nút Gửi. Nút ✕ lên 44px: trước là 28px,
             nằm sát mép phải nơi ngón cái hay trượt. -->
        <div v-if="pinnedOrder" class="px-3 pt-3 flex items-center gap-2">
            <div class="flex-1 min-w-0 panel px-3 py-2">
                <p class="text-xs font-bold text-[var(--color-ink)]">🧾 Đang hỏi về đơn <span class="num">{{ pinnedOrder.order_id }}</span></p>
                <p v-if="pinnedOrder.product_name" class="text-xs text-[var(--color-muted)] truncate">{{ pinnedOrder.product_name }}</p>
            </div>
            <button
                type="button"
                @click="emit('unpin')"
                aria-label="Bỏ ghim đơn hàng"
                class="focus-ring touch flex-none rounded-xl flex items-center justify-center text-[var(--color-muted)] hover:bg-[var(--color-peach-soft)] hover:text-[var(--color-ink)] transition"
            >✕</button>
        </div>

        <!-- Nút bỏ ảnh tách hẳn ra cạnh ảnh thay vì dán chồng lên góc: một nút 44px đè lên ảnh
             xem trước 80px là che mất nửa cái ảnh khách vừa chọn, mà 24px như cũ thì lại quá nhỏ
             để bấm trúng bằng ngón cái. -->
        <div v-if="imagePreview" class="px-3 pt-3 flex items-center gap-3">
            <img :src="imagePreview" alt="Ảnh sắp gửi" class="h-20 w-auto rounded-xl border border-[var(--color-line)]" />
            <button
                type="button"
                @click="removeImage"
                class="focus-ring inline-flex items-center min-h-[44px] px-3 rounded-xl border border-[var(--color-line)] text-xs font-semibold text-[var(--color-ink)] hover:bg-[var(--color-peach-soft)] transition"
            >✕ Bỏ ảnh</button>
        </div>

        <!-- Cả ba thứ trong hàng này đều cao 44px: nút kẹp ảnh (trước 40px), ô gõ và nút Gửi.
             Chiều cao đặt bằng min-h chứ không py-* — py-2.5 và py-3 đang chia đôi cùng một vai
             trò khắp web và chênh nhau 4px. -->
        <div class="p-3 flex items-end gap-2">
            <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onFilePicked" />
            <button
                type="button"
                @click="pickImage"
                aria-label="Đính kèm ảnh"
                title="Đính kèm ảnh"
                class="focus-ring touch flex-none rounded-xl border border-[var(--color-line)] text-[var(--color-muted)] hover:text-[var(--color-accent-deep)] hover:border-[var(--color-accent)] transition flex items-center justify-center"
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
                aria-label="Nội dung tin nhắn"
                @input="onType"
                @keydown.enter.exact.prevent="submit"
                class="focus-ring flex-1 min-w-0 min-h-[44px] resize-none px-4 py-2.5 rounded-xl border border-[var(--color-line)] bg-[var(--color-bg)] text-sm text-[var(--color-ink)] focus:border-[var(--color-accent)]"
            ></textarea>

            <button
                type="button"
                @click="submit"
                :disabled="sending || (!body.trim() && !image)"
                class="btn-fire inline-flex items-center justify-center px-5 rounded-xl text-sm whitespace-nowrap"
            >{{ sending ? 'Đang gửi...' : 'Gửi' }}</button>
        </div>
    </div>
</template>
