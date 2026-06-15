<script setup>
import { useToast } from '@/composables/useToast'

const { toasts, remove } = useToast()

const icons = { success: '✓', error: '✕', info: 'ℹ' }
</script>

<template>
    <Teleport to="body">
        <div class="fixed top-4 right-4 z-[9999] flex flex-col gap-2 pointer-events-none" aria-live="polite">
            <TransitionGroup name="toast">
                <div
                    v-for="toast in toasts"
                    :key="toast.id"
                    class="pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-2xl shadow-xl text-sm font-semibold max-w-xs w-full"
                    :class="{
                        'bg-[--color-brand-green] text-white': toast.type === 'success',
                        'bg-red-500 text-white': toast.type === 'error',
                        'bg-[--color-ink] text-white': toast.type === 'info',
                    }"
                >
                    <span class="text-base leading-none shrink-0">{{ icons[toast.type] }}</span>
                    <span class="flex-1 leading-snug">{{ toast.message }}</span>
                    <button
                        @click="remove(toast.id)"
                        class="shrink-0 opacity-60 hover:opacity-100 transition ml-1 leading-none"
                        aria-label="Đóng"
                    >✕</button>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
