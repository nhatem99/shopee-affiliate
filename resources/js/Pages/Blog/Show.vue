<script setup>
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    post: { type: Object, required: true },
})
</script>

<template>
    <Head>
        <title>{{ post.title }} | tietkiemvi.com</title>
        <meta name="description" :content="post.excerpt" />
    </Head>
    <AppLayout>
        <div class="max-w-2xl mx-auto px-4 py-10">
            <Link href="/blog" class="text-sm text-[var(--color-muted)] hover:text-[var(--color-ink)] mb-6 inline-block">← Blog</Link>
            <p class="text-xs text-[var(--color-muted)] mb-2">{{ new Date(post.published_at).toLocaleDateString('vi-VN') }}</p>
            <h1 class="text-2xl font-extrabold text-[var(--color-ink)] mb-6">{{ post.title }}</h1>
            <p class="text-[var(--color-ink)] leading-relaxed whitespace-pre-line mb-8">{{ post.body }}</p>
        </div>
    </AppLayout>
</template>
