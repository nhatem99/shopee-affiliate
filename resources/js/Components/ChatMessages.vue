<script setup>
import { computed } from 'vue'

// Khung tin nhắn dùng chung cho cả hai phía: trang khách (Pages/Chat.vue) và hộp thư admin
// (Pages/Admin/Chats.vue). Cùng một hội thoại nhìn từ hai đầu, chỉ khác bên nào là "mình" —
// tách ra để "đã xem", ảnh và thẻ ghim đơn không phải viết hai lần rồi lệch nhau một lần sửa.
const props = defineProps({
    messages: { type: Array, default: () => [] },
    viewerIsAdmin: { type: Boolean, default: false },
    // Mốc (epoch giây) bên kia đã đọc tới — null nghĩa là chưa mở lần nào.
    peerReadTs: { type: Number, default: null },
    peerTyping: { type: Boolean, default: false },
    peerLabel: { type: String, default: 'Hỗ trợ' },
    ownLabel: { type: String, default: 'Bạn' },
    emptyText: { type: String, default: '' },
})

function isOwn(m) {
    return m.from_admin === props.viewerIsAdmin
}

/**
 * Chỉ gắn "Đã xem" vào tin CUỐI CÙNG của mình mà bên kia đã đọc tới. Gắn vào mọi tin đã đọc thì
 * cả cột chat lằng nhằng một chữ lặp lại, trong khi thứ người ta muốn biết chỉ là "cái vừa gửi
 * đã tới mắt người kia chưa".
 */
const lastSeenOwnId = computed(() => {
    if (!props.peerReadTs) return null

    const seen = props.messages.filter(m => isOwn(m) && m.ts <= props.peerReadTs)

    return seen.length ? seen[seen.length - 1].id : null
})
</script>

<template>
    <div class="space-y-3">
        <p v-if="!messages.length && emptyText" class="text-center text-sm text-[var(--color-muted)] py-12">
            {{ emptyText }}
        </p>

        <div v-for="m in messages" :key="m.id" class="flex" :class="isOwn(m) ? 'justify-end' : 'justify-start'">
            <div class="max-w-[85%] sm:max-w-[70%]">
                <!-- Thẻ ghim đơn hàng: khách bấm "Hỏi về đơn này" ở /don-hang thì tin nhắn mang
                     theo mã đơn, admin khỏi phải hỏi lại "đơn nào bạn ơi". -->
                <p
                    v-if="m.order_id"
                    class="text-[11px] font-semibold text-[var(--color-muted)] mb-1 px-1"
                    :class="isOwn(m) ? 'text-right' : 'text-left'"
                >🧾 Đơn {{ m.order_id }}</p>

                <!-- Ảnh mở ở tab mới khi bấm: khung chat cao cố định, ảnh chụp màn hình điện
                     thoại thu nhỏ trong đó thì không đọc nổi chữ. -->
                <a v-if="m.image" :href="m.image" target="_blank" rel="noopener noreferrer" class="block mb-1">
                    <img
                        :src="m.image"
                        alt="Ảnh đính kèm"
                        loading="lazy"
                        class="rounded-2xl max-h-64 w-auto border border-[var(--color-line)] object-cover"
                    />
                </a>

                <div
                    v-if="m.body"
                    class="rounded-2xl px-4 py-2.5 text-sm leading-relaxed whitespace-pre-wrap break-words"
                    :class="isOwn(m)
                        ? 'bg-[var(--color-accent)] text-white rounded-tr-sm'
                        : 'bg-[var(--color-peach-soft)] text-[var(--color-ink)] rounded-tl-sm'"
                >{{ m.body }}</div>

                <p class="text-[11px] text-[var(--color-muted)] mt-1" :class="isOwn(m) ? 'text-right' : 'text-left'">
                    {{ isOwn(m) ? ownLabel : peerLabel }} · {{ m.at }}
                    <span v-if="m.id === lastSeenOwnId" class="font-semibold"> · Đã xem</span>
                </p>
            </div>
        </div>

        <div v-if="peerTyping" class="flex justify-start">
            <div class="bg-[var(--color-peach-soft)] rounded-2xl rounded-tl-sm px-4 py-2.5 flex items-center gap-1.5">
                <span class="sr-only">{{ peerLabel }} đang gõ</span>
                <span v-for="i in 3" :key="i" class="w-1.5 h-1.5 rounded-full bg-[var(--color-muted)] animate-bounce" :style="{ animationDelay: `${(i - 1) * 0.15}s` }"></span>
            </div>
        </div>
    </div>
</template>
