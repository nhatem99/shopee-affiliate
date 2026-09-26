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
        <div class="max-w-2xl mx-auto px-4 py-8">
            <!-- Đường lùi về danh sách là hàng bấm được duy nhất của trang này nên nó phải đủ
                 44px: trước là một dòng chữ 20px cao, trên điện thoại gần như bấm trượt. -->
            <Link href="/blog" class="focus-ring inline-flex items-center min-h-[44px] -ml-2 px-2 rounded-lg mb-2 text-sm font-semibold text-[var(--color-muted)] hover:text-[var(--color-ink)]">← Tất cả bài viết</Link>
            <p class="text-xs text-[var(--color-muted)] mb-2 num">{{ new Date(post.published_at).toLocaleDateString('vi-VN') }}</p>
            <h1 class="text-2xl font-extrabold text-[var(--color-ink)] mb-6">{{ post.title }}</h1>
            <p class="text-base text-[var(--color-ink)] leading-relaxed whitespace-pre-line mb-8">{{ post.body }}</p>
        </div>
    </AppLayout>
</template>
