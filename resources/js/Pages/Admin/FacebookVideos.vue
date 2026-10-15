<script setup>
import { computed, onMounted, onBeforeUnmount } from 'vue'
import { router, useForm, usePage, Head, Link } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import FacebookVideoCard from '@/Components/FacebookVideoCard.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const page = usePage()

const props = defineProps({
    videos: Array,
    // { version, supported, min_version, last_seen_at }
    runner: Object,
    // Máy chủ thiếu ffmpeg/font — chưa dựng được video.
    editorProblem: String,
    facebookReady: Boolean,
    maxUploadMb: Number,
})

const BUSY = ['waiting_bot', 'fetching', 'processing', 'publishing']
const anyBusy = computed(() => props.videos.some(v => BUSY.includes(v.status)))

// Có video đang chờ bot / dựng / đăng thì tải lại 5 giây một lần cho thấy tiến độ.
let timer = null
onMounted(() => {
    timer = setInterval(() => {
        if (anyBusy.value) router.reload({ only: ['videos', 'runner'] })
    }, 5000)
})
onBeforeUnmount(() => clearInterval(timer))

const form = useForm({ url: '' })

function submit() {
    form.post('/admin/fb-videos', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            const msg = page.props.flash?.success
            if (msg) toast.success(msg)
        },
    })
}
</script>

<template>
    <Head title="Admin — Video Reel" />
    <AdminLayout>
        <template #title>Đăng Reel từ video sản phẩm</template>

        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">Dán link sản phẩm Shopee</h2>
            <p class="text-sm text-[var(--color-muted)] mb-3">
                Hệ thống tạo link mua có mã (như bài nhóm), bot trên điện thoại (xem <Link href="/admin/fb-groups" class="underline">Nhóm & bot đăng bài</Link>)
                mở Shopee lấy video sản phẩm, server cắt bỏ vài giây đầu/cuối, đặt vào khung dọc 9:16 kèm chữ của mình.
                Xem lại rồi bấm "Đăng Reel" — link mua nằm trong caption Reel (bấm được).
            </p>

            <div v-if="editorProblem" class="rounded-xl bg-red-50 border border-red-200 p-3 mb-3 text-sm text-red-600">
                Chưa dựng được video: {{ editorProblem }}
            </div>
            <div v-if="!facebookReady" class="rounded-xl bg-amber-50 border border-amber-200 p-3 mb-3 text-sm text-amber-700">
                Chưa có cấu hình Facebook đang bật — nhập Page ID và Page Access Token (cần quyền <b>pages_manage_posts</b>) ở
                <Link href="/admin/api-config" class="underline">Cấu hình API</Link> mới đăng được.
            </div>
            <p v-if="!runner.supported" class="text-xs text-amber-600 mb-3">
                Bot đang chạy bản {{ runner.version || 'cũ' }} — từ bản {{ runner.min_version }} bot mới lấy được video Shopee
                (git pull trên điện thoại, đăng nhập shopee.vn trên Chromium một lần). Trong lúc đó tải video lên tay được.
            </p>

            <form @submit.prevent="submit" class="flex flex-col sm:flex-row gap-2">
                <input v-model="form.url" type="url" required placeholder="https://shopee.vn/... hoặc https://s.shopee.vn/..."
                    class="flex-1 border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--color-accent)]" />
                <button type="submit" :disabled="form.processing"
                    class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition disabled:opacity-60">
                    {{ form.processing ? 'Đang tạo link...' : 'Lấy video' }}
                </button>
            </form>
            <p v-if="form.errors.url" class="text-red-500 text-xs mt-2">{{ form.errors.url }}</p>
        </section>

        <p v-if="!videos.length" class="text-sm text-[var(--color-muted)]">Chưa có video nào.</p>
        <div class="space-y-4">
            <FacebookVideoCard v-for="video in videos" :key="video.id" :video="video" :runner="runner" :max-upload-mb="maxUploadMb" />
        </div>
    </AdminLayout>
</template>
