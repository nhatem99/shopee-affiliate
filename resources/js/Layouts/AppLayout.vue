<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useAuthStore } from '@/Stores/useAuthStore'
import BottomNav from '@/Components/BottomNav.vue'
import ToastContainer from '@/Components/ToastContainer.vue'
import ThemeToggle from '@/Components/ThemeToggle.vue'

const auth = useAuthStore()
const page = usePage()
const current = computed(() => page.url)
</script>

<template>
    <div class="min-h-screen bg-[var(--color-bg)]">
        <!-- Header -->
        <header class="sticky top-0 z-50 bg-[var(--color-surface)]/80 backdrop-blur-md border-b border-[var(--color-line)] dark:shadow-[0_4px_24px_rgba(0,0,0,0.45)]">
            <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
                <Link href="/" class="flex items-center gap-2 font-extrabold text-xl text-[var(--color-ink)]">
                    <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-[var(--color-accent)] to-[var(--color-accent-deep)] flex items-center justify-center text-white text-base font-extrabold dark:shadow-[0_4px_14px_rgba(var(--color-accent-rgb),0.4)]">%</span>
                    <span class="text-fire font-mono tracking-wide">Mã Giảm Giá</span>
                </Link>

                <nav class="hidden md:flex items-center gap-1 text-sm font-medium">
                    <Link v-if="!auth.isAdmin" href="/" class="nav-pill rounded-xl px-3.5 py-2" :class="{ 'nav-pill--active': current === '/' }">Trang chủ</Link>
                    <Link v-if="!auth.isAdmin" href="/blog" class="nav-pill rounded-xl px-3.5 py-2" :class="{ 'nav-pill--active': current.startsWith('/blog') }">Blog</Link>
                    <Link v-if="auth.isLoggedIn && !auth.isAdmin" href="/history" class="nav-pill rounded-xl px-3.5 py-2" :class="{ 'nav-pill--active': current.startsWith('/history') }">Lịch sử</Link>
                    <Link v-if="auth.isLoggedIn && !auth.isAdmin" href="/profile" class="nav-pill rounded-xl px-3.5 py-2" :class="{ 'nav-pill--active': current.startsWith('/profile') }">Tài khoản</Link>
                    <Link v-if="auth.isAdmin" href="/admin/dashboard" class="nav-pill rounded-xl px-3.5 py-2" :class="{ 'nav-pill--active': current.startsWith('/admin') }">Admin</Link>
                </nav>

                <div class="flex items-center gap-3">
                    <ThemeToggle />
                    <template v-if="auth.isLoggedIn">
                        <Link v-if="!auth.isAdmin" href="/profile" class="hidden md:block text-sm text-[var(--color-muted)] hover:text-[var(--color-ink)] font-medium transition">{{ auth.user?.name }}</Link>
                        <span v-else class="hidden md:block text-sm text-[var(--color-muted)] font-medium">{{ auth.user?.name }}</span>
                        <button @click="auth.logout()"
                            class="text-sm text-[var(--color-muted)] hover:text-[var(--color-ink)] font-medium transition">
                            Đăng xuất
                        </button>
                    </template>
                    <template v-else>
                        <Link href="/login" class="text-sm text-[var(--color-muted)] hover:text-[var(--color-ink)] font-medium transition">Đăng nhập</Link>
                        <Link href="/register" class="btn-fire text-sm px-4 py-2 rounded-xl">
                            Đăng ký
                        </Link>
                    </template>
                </div>
            </div>
        </header>

        <!-- Page content with transition -->
        <main class="pb-20 md:pb-0">
            <Transition name="fade-up" mode="out-in">
                <div>
                    <slot />
                </div>
            </Transition>
        </main>

        <BottomNav />
        <ToastContainer />
    </div>
</template>
