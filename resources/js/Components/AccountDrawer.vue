<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed, watch } from 'vue'
import { useAuthStore } from '@/Stores/useAuthStore'
import { accountNavItems, isAccountNavActive } from '@/accountNavItems'

// Menu tài khoản trượt từ TRÁI qua, mở bằng icon hamburger ở header (xem AppLayout.vue) — khác
// AccountLayout.vue (sidebar tĩnh, chỉ nằm trong các trang /profile*): cái này nổi trên MỌI
// trang, để khách không phải rời trang đang xem chỉ để nhìn số dư hay đổi mục.
const props = defineProps({ open: { type: Boolean, required: true } })
const emit = defineEmits(['close'])

const page = usePage()
const auth = useAuthStore()
const current = computed(() => page.url)
// Đóng trong HandleInertiaRequests::wallet() — null khi khách vãng lai/admin, không hiện banner.
const balance = computed(() => page.props.wallet?.available ?? null)

function isActive(href) {
    return isAccountNavActive(current.value, href)
}

function vnd(n) {
    return '₫' + Number(n || 0).toLocaleString('vi-VN')
}

// Đổi trang (bấm một mục, hoặc back/forward) thì đóng luôn — mở lại drawer trên trang mới là
// hành vi gây lú hơn là hữu ích, và tránh kẹt overlay che mất trang vừa điều hướng tới.
watch(current, () => emit('close'))
</script>

<template>
    <Teleport to="body">
        <Transition name="drawer-fade">
            <div
                v-if="open"
                class="fixed inset-0 bg-black/60 z-[70]"
                @click="emit('close')"
            ></div>
        </Transition>
        <Transition name="drawer-slide">
            <aside
                v-if="open"
                class="fixed inset-y-0 left-0 z-[80] w-[82vw] max-w-[320px] bg-[var(--color-surface)] shadow-2xl flex flex-col overflow-y-auto"
            >
                <div class="p-4 flex items-center gap-3 border-b border-[var(--color-line)]">
                    <div class="flex-none w-11 h-11 rounded-full bg-gradient-to-br from-[var(--color-accent)] to-[var(--color-accent-deep)] flex items-center justify-center text-white text-base font-extrabold">
                        {{ (auth.user?.name || auth.user?.email || '?').charAt(0).toUpperCase() }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="font-bold text-sm text-[var(--color-ink)] truncate">{{ auth.user?.name }}</p>
                        <p class="text-xs text-[var(--color-muted)] truncate">{{ auth.user?.email }}</p>
                    </div>
                    <button
                        type="button"
                        @click="emit('close')"
                        aria-label="Đóng menu"
                        class="flex-none w-8 h-8 rounded-lg flex items-center justify-center text-[var(--color-muted)] hover:bg-[var(--color-peach-soft)] hover:text-[var(--color-ink)] transition"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="w-5 h-5"><path d="M18 6 6 18M6 6l12 12" /></svg>
                    </button>
                </div>

                <Link
                    v-if="balance !== null"
                    href="/vi/lich-su"
                    class="m-4 rounded-2xl p-4 bg-gradient-to-r from-[var(--color-accent)] to-[var(--color-accent-deep)] text-white shadow-lg"
                >
                    <p class="text-[11px] font-bold uppercase tracking-wide opacity-90">Số dư khả dụng</p>
                    <p class="text-2xl font-extrabold mt-0.5">{{ vnd(balance) }}</p>
                </Link>

                <nav class="flex-1 px-3 pb-3 space-y-0.5">
                    <Link
                        v-for="item in accountNavItems"
                        :key="item.href"
                        :href="item.href"
                        class="flex items-center gap-3 px-2 py-2.5 rounded-xl text-sm font-semibold transition"
                        :class="isActive(item.href)
                            ? 'bg-[var(--color-peach-soft)] text-[var(--color-accent)]'
                            : 'text-[var(--color-ink)] hover:bg-[var(--color-peach-soft)]/60'"
                    >
                        <span
                            class="flex-none w-9 h-9 rounded-full flex items-center justify-center text-base"
                            :class="item.badge"
                        >{{ item.icon }}</span>
                        {{ item.label }}
                    </Link>
                </nav>

                <div class="p-3 border-t border-[var(--color-line)]">
                    <button
                        type="button"
                        @click="auth.logout()"
                        class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-semibold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30 transition"
                    >
                        <span>↩</span> Đăng xuất
                    </button>
                </div>
            </aside>
        </Transition>
    </Teleport>
</template>

<style scoped>
.drawer-fade-enter-active, .drawer-fade-leave-active { transition: opacity 0.2s ease; }
.drawer-fade-enter-from, .drawer-fade-leave-to { opacity: 0; }

.drawer-slide-enter-active, .drawer-slide-leave-active { transition: transform 0.25s ease; }
.drawer-slide-enter-from, .drawer-slide-leave-to { transform: translateX(-100%); }
</style>
