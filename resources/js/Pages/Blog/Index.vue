<script setup>
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
    posts: { type: Array, default: () => [] },
})
</script>

<template>
    <Head>
        <title>Blog | tietkiemvi.com</title>
        <meta name="description" content="Hướng dẫn lấy voucher Shopee cho Facebook, YouTube, Instagram và cách sử dụng tietkiemvi.com." />
    </Head>
    <AppLayout>
        <div class="max-w-2xl mx-auto px-4 py-10">
            <h1 class="text-2xl font-extrabold text-[var(--color-ink)] mb-8">Blog</h1>

            <div v-if="posts.length" class="flex flex-col gap-4">
                <Link
                    v-for="post in posts"
                    :key="post.slug"
                    :href="`/blog/${post.slug}`"
                    class="block bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 hover:border-[var(--color-accent)] transition"
                >
                    <p class="text-xs text-[var(--color-muted)] mb-1">{{ new Date(post.published_at).toLocaleDateString('vi-VN') }}</p>
                    <h2 class="font-bold text-[var(--color-ink)] mb-1">{{ post.title }}</h2>
                    <p class="text-sm text-[var(--color-muted)] line-clamp-2">{{ post.excerpt }}</p>
                </Link>
            </div>
            <p v-else class="text-center text-[var(--color-muted)] py-16">Chưa có bài viết nào.</p>
        </div>
    </AppLayout>
</template>
