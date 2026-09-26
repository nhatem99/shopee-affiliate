<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed, watch } from 'vue'
import UserAvatar from '@/Components/UserAvatar.vue'
import { useAuthStore } from '@/Stores/useAuthStore'
import { accountNavItemsFor, isAccountNavActive } from '@/accountNavItems'

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
const navItems = computed(() => accountNavItemsFor(page.props.settings))

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
                    <UserAvatar
                        :src="auth.user?.avatar"
                        :name="auth.user?.name || auth.user?.email"
                        class="flex-none w-11 h-11 text-base"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="font-bold text-sm text-[var(--color-ink)] truncate">{{ auth.user?.name }}</p>
                        <p class="text-xs text-[var(--color-muted)] truncate">{{ auth.user?.email }}</p>
                    </div>
                    <button
                        type="button"
                        @click="emit('close')"
                        aria-label="Đóng menu"
                        class="touch focus-ring flex-none rounded-xl flex items-center justify-center text-[var(--color-muted)] hover:bg-[var(--color-peach-soft)] hover:text-[var(--color-ink)] transition"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="w-5 h-5"><path d="M18 6 6 18M6 6l12 12" /></svg>
                    </button>
                </div>

                <!-- Số dư: cùng một khuôn với trang Tổng quan và Lịch sử số dư ví — cùng nhãn,
                     cùng màu tiền, cùng .num. Bỏ nền gradient cam chữ trắng: cam để dành cho nút
                     hành động, và ba nơi cùng hiện một con số mà vẽ ba kiểu thì khách đa nghi sẽ
                     tưởng là ba con số khác nhau.
                     Cỡ chữ nhỏ hơn hai nơi kia một bậc (2xl chứ không 3xl) vì ngăn kéo chỉ rộng
                     82vw — ở 375px là ~243px lọt lòng, số dư bảy chữ số ở 3xl sẽ chạm mép. -->
                <Link
                    v-if="balance !== null"
                    href="/vi/lich-su"
                    class="card focus-ring m-4 p-4 block bg-[var(--color-money-soft)]"
                >
                    <p class="text-xs font-bold uppercase tracking-wide text-[var(--color-muted)]">Số dư khả dụng</p>
                    <p class="text-2xl font-extrabold num text-[var(--color-money)] mt-0.5">{{ vnd(balance) }}</p>
                    <p class="text-xs font-semibold text-[var(--color-muted)] mt-1">Xem lịch sử số dư →</p>
                </Link>

                <nav class="flex-1 px-3 pb-3 space-y-0.5">
                    <Link
                        v-for="item in navItems"
                        :key="item.href"
                        :href="item.href"
                        class="focus-ring flex items-center gap-3 px-2 min-h-[44px] rounded-xl text-sm font-semibold transition"
                        :class="isActive(item.href)
                            ? 'bg-[var(--color-peach-soft)] text-[var(--color-accent-deep)]'
                            : 'text-[var(--color-ink)] hover:bg-[var(--color-peach-soft)]/60'"
                    >
                        <span
                            class="flex-none w-9 h-9 rounded-full flex items-center justify-center text-base"
                            :class="item.badge"
                        >{{ item.icon }}</span>
                        {{ item.label }}
                    </Link>
                </nav>

                <!-- safe-bottom: ngăn kéo cao hết màn, nút cuối cùng không được nằm dưới vạch
                     home của iPhone. -->
                <div class="p-3 border-t border-[var(--color-line)] safe-bottom">
                    <button
                        type="button"
                        @click="auth.logout()"
                        class="focus-ring w-full flex items-center gap-2.5 px-3 min-h-[44px] rounded-xl text-sm font-semibold text-[var(--color-danger)] hover:bg-[var(--color-danger-soft)] transition"
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
