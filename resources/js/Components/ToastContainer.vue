<script setup>
import { useToast } from '@/composables/useToast'

const { toasts, remove } = useToast()

const icons = { success: '✓', error: '✕', info: 'ℹ' }
</script>

<template>
    <Teleport to="body">
        <!-- safe-top: toast neo top-4, trên iPhone trong webview Facebook/Zalo thì 16px đó rơi
             đúng vào vùng tai thỏ và dòng chữ bị cắt ngang. -->
        <div class="fixed top-4 right-4 left-4 sm:left-auto z-[9999] flex flex-col items-end gap-2 pointer-events-none safe-top" aria-live="polite">
            <TransitionGroup name="toast">
                <!-- Chữ lấy --color-bg thay vì trắng cứng. Ba dòng cũ đều hỏng ở chế độ tối —
                     mà tối là chế độ MẶC ĐỊNH của trang:
                       success: trắng trên --color-brand-green chỉ 3.4:1 (chữ 14px cần 4.5:1);
                       error:   red-500 gõ thẳng, không có bản tối nào;
                       info:    trắng trên --color-ink — ở chế độ tối ink LÀ #f1f5f9, tức chữ
                                trắng trên nền gần trắng, mất hẳn chữ.
                     Lấy nền trang làm màu chữ thì cả hai chế độ tự đảo đúng chiều. -->
                <div
                    v-for="toast in toasts"
                    :key="toast.id"
                    class="pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-2xl shadow-xl text-sm font-semibold max-w-xs w-full"
                    :class="{
                        'bg-[var(--color-money)] text-[var(--color-bg)]': toast.type === 'success',
                        'bg-[var(--color-danger)] text-[var(--color-bg)]': toast.type === 'error',
                        'bg-[var(--color-ink)] text-[var(--color-bg)]': toast.type === 'info',
                    }"
                >
                    <span class="text-base leading-none shrink-0" aria-hidden="true">{{ icons[toast.type] }}</span>
                    <span class="flex-1 leading-snug">{{ toast.message }}</span>
                    <button
                        @click="remove(toast.id)"
                        class="focus-ring touch shrink-0 -my-2 -mr-2 flex items-center justify-center rounded-xl opacity-70 hover:opacity-100 transition leading-none"
                        aria-label="Đóng"
                    >✕</button>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
