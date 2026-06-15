<script setup>
import { Link } from '@inertiajs/vue3'
import { useAuthStore } from '@/Stores/useAuthStore'
import BottomNav from '@/Components/BottomNav.vue'
import ToastContainer from '@/Components/ToastContainer.vue'

const auth = useAuthStore()
</script>

<template>
    <div class="min-h-screen bg-[--color-bg]">
        <!-- Header -->
        <header class="sticky top-0 z-50 bg-white/80 backdrop-blur-md border-b border-[--color-line]">
            <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
                <Link href="/" class="flex items-center gap-2 font-extrabold text-xl text-[--color-ink]">
                    <span class="w-8 h-8 rounded-lg bg-[--color-accent] flex items-center justify-center text-white text-sm font-black">M</span>
                    Mã Giảm Giá
                </Link>

                <nav class="hidden md:flex items-center gap-6 text-sm font-medium">
                    <Link href="/" class="text-[--color-muted] hover:text-[--color-ink] transition">Trang chủ</Link>
                    <Link v-if="auth.isLoggedIn" href="/history" class="text-[--color-muted] hover:text-[--color-ink] transition">Lịch sử</Link>
                    <Link v-if="auth.isAdmin" href="/admin/dashboard" class="text-[--color-muted] hover:text-[--color-ink] transition">Admin</Link>
                </nav>

                <div class="flex items-center gap-3">
                    <template v-if="auth.isLoggedIn">
                        <span class="hidden md:block text-sm text-[--color-muted] font-medium">{{ auth.user?.name }}</span>
                        <button @click="auth.logout()"
                            class="text-sm text-[--color-muted] hover:text-[--color-ink] font-medium transition">
                            Đăng xuất
                        </button>
                    </template>
                    <template v-else>
                        <Link href="/login" class="text-sm text-[--color-muted] hover:text-[--color-ink] font-medium transition">Đăng nhập</Link>
                        <Link href="/register"
                            class="bg-[--color-accent] hover:bg-[--color-accent-deep] text-white text-sm font-semibold px-4 py-2 rounded-xl transition">
                            Đăng ký
                        </Link>
                    </template>
                </div>
            </div>
        </header>

        <!-- Page content with transition -->
        <main class="pb-20 md:pb-0">
            <Transition name="fade-up" mode="out-in">
                <slot />
            </Transition>
        </main>

        <BottomNav />
        <ToastContainer />
    </div>
</template>
