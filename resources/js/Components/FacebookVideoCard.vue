<script setup>
import { computed } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { useToast } from '@/composables/useToast'

// Một video ở /admin/fb-videos. Form sửa giữ trong component riêng để lượt tự tải lại của trang
// (lúc có video đang xử lý) không xoá mất chữ admin đang gõ dở.
const props = defineProps({
    video: Object,
    // { supported, version, min_version } — bot từ min_version mới biết lấy video.
    runner: Object,
    maxUploadMb: Number,
})

const toast = useToast()
const page = usePage()
const v = computed(() => props.video)

const statusText = {
    waiting_bot: ['Chờ bot lấy video', 'bg-amber-100 text-amber-700'],
    fetching: ['Bot đang mở Shopee', 'bg-amber-100 text-amber-700'],
    processing: ['Đang dựng video', 'bg-amber-100 text-amber-700'],
    ready: ['Chờ đăng', 'bg-blue-100 text-blue-700'],
    publishing: ['Đang đăng', 'bg-amber-100 text-amber-700'],
    posted: ['Đã đăng', 'bg-green-100 text-green-700'],
    no_video: ['Không có video', 'bg-gray-100 text-gray-600'],
    failed: ['Hỏng', 'bg-red-100 text-red-600'],
}

const edit = useForm({
    caption: v.value.caption || '',
    overlay_top: v.value.overlay_top || '',
    overlay_bottom: v.value.overlay_bottom || '',
    trim_start: v.value.trim_start,
    trim_end: v.value.trim_end,
})

// Đổi chữ trên video / số giây cắt thì server dựng lại — báo trước trên nút.
const needsRebuild = computed(() => v.value.has_source && (
    edit.overlay_top !== (v.value.overlay_top || '')
    || edit.overlay_bottom !== (v.value.overlay_bottom || '')
    || Number(edit.trim_start) !== Number(v.value.trim_start)
    || Number(edit.trim_end) !== Number(v.value.trim_end)
))
const canEdit = computed(() => ['ready', 'failed', 'no_video'].includes(v.value.status))
const canUpload = computed(() => ['waiting_bot', 'ready', 'failed', 'no_video'].includes(v.value.status))

const upload = useForm({ video: null })

function flash() {
    const msg = page.props.flash?.success
    if (msg) toast.success(msg)
}

function save() {
    edit.patch(`/admin/fb-videos/${v.value.id}`, {
        preserveScroll: true,
        // Bản vừa lưu thành mốc mới — nút Lưu tắt tới khi sửa tiếp.
        onSuccess: () => { edit.defaults(); flash() },
    })
}

function publish() {
    if (edit.isDirty && !confirm('Còn thay đổi chưa lưu — vẫn đăng bản đang có trên server?')) return
    if (!confirm('Đăng Reel này lên page?')) return
    router.post(`/admin/fb-videos/${v.value.id}/publish`, {}, { preserveScroll: true, onSuccess: flash })
}

function refetch() {
    router.post(`/admin/fb-videos/${v.value.id}/refetch`, {}, { preserveScroll: true, onSuccess: flash })
}

function destroy() {
    if (!confirm('Xoá video này (cả file đã dựng)? Reel đã đăng trên Facebook không bị xoá.')) return
    router.delete(`/admin/fb-videos/${v.value.id}`, { preserveScroll: true, onSuccess: flash })
}

function pickFile(event) {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return
    if (file.size > props.maxUploadMb * 1024 * 1024) {
        toast.error(`Video lớn hơn ${props.maxUploadMb} MB.`)
        return
    }
    upload.video = file
    upload.post(`/admin/fb-videos/${v.value.id}/upload`, { forceFormData: true, preserveScroll: true, onSuccess: flash })
}

function fmt(iso) {
    return iso ? new Date(iso).toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' }) : ''
}

function vnd(n) {
    return n ? Number(n).toLocaleString('vi-VN') + '₫' : ''
}
</script>

<template>
    <article class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4">
        <header class="flex gap-3 items-start">
            <img v-if="v.product?.product_image" :src="v.product.product_image" alt="" class="w-14 h-14 rounded-lg object-cover shrink-0" />
            <div class="min-w-0 flex-1">
                <a :href="v.shopee_url" target="_blank" rel="noopener" class="font-semibold text-sm text-[var(--color-ink)] hover:underline line-clamp-2">
                    {{ v.product?.product_name || v.shopee_url }}
                </a>
                <p class="text-xs text-[var(--color-muted)] mt-0.5">
                    #{{ v.id }} · {{ fmt(v.created_at) }}
                    <template v-if="v.product?.discounted_price"> · {{ vnd(v.product.discounted_price) }}</template>
                    <template v-if="v.source_duration"> · video gốc {{ Math.round(v.source_duration) }}s</template>
                    <template v-if="v.output_duration"> → {{ Math.round(v.output_duration) }}s</template>
                </p>
            </div>
            <span :class="['text-xs font-semibold px-2 py-1 rounded-lg shrink-0', statusText[v.status]?.[1]]">{{ statusText[v.status]?.[0] || v.status }}</span>
        </header>

        <p v-if="v.status === 'waiting_bot'" class="text-xs text-[var(--color-muted)] mt-3">
            <template v-if="runner.supported">Bot trên điện thoại hỏi việc khoảng 2 phút một lần — sẽ mở Shopee lấy video.</template>
            <template v-else>Bot đang chạy bản {{ runner.version || 'cũ' }}, cần bản {{ runner.min_version }} trở lên mới lấy được video (git pull trên điện thoại). Hoặc tải video lên tay ở dưới.</template>
        </p>
        <p v-else-if="v.status === 'fetching'" class="text-xs text-[var(--color-muted)] mt-3">Bot đang mở trang sản phẩm trên điện thoại...</p>
        <p v-else-if="v.status === 'processing'" class="text-xs text-[var(--color-muted)] mt-3">Server đang tải video gốc, cắt và chèn chữ — thường dưới 1 phút.</p>
        <p v-else-if="v.status === 'publishing'" class="text-xs text-[var(--color-muted)] mt-3">Đang tải video lên Facebook và chờ Facebook xử lý — tối đa vài phút.</p>
        <p v-else-if="v.status === 'no_video'" class="text-xs text-[var(--color-muted)] mt-3">Bot đọc được trang sản phẩm nhưng không thấy video nào. Có video thật thì tải lên tay.</p>

        <p v-if="v.error" :class="['text-xs mt-3 rounded-lg px-3 py-2', v.status === 'posted' ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-red-600']">{{ v.error }}</p>

        <p v-if="v.status === 'posted'" class="text-sm mt-3">
            ✅ Đăng lúc {{ fmt(v.posted_at) }} —
            <a :href="v.permalink" target="_blank" rel="noopener" class="font-semibold text-[var(--color-accent)] hover:underline">Xem Reel</a>
        </p>

        <div v-if="v.output_url && v.status !== 'posted'" class="mt-4 flex flex-col sm:flex-row gap-4">
            <video :src="v.output_url" controls playsinline preload="metadata" class="w-full sm:w-52 aspect-[9/16] bg-black rounded-xl shrink-0"></video>

            <form v-if="canEdit" @submit.prevent="save" class="flex-1 space-y-3 text-sm">
                <label class="block">
                    <span class="font-semibold text-[var(--color-ink)]">Chữ phía trên video</span>
                    <input v-model="edit.overlay_top" maxlength="200" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                </label>
                <label class="block">
                    <span class="font-semibold text-[var(--color-ink)]">Chữ phía dưới video</span>
                    <input v-model="edit.overlay_bottom" maxlength="200" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="font-semibold text-[var(--color-ink)]">Cắt bỏ đầu (giây)</span>
                        <input v-model.number="edit.trim_start" type="number" min="0" max="10" step="0.5" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                    </label>
                    <label class="block">
                        <span class="font-semibold text-[var(--color-ink)]">Cắt bỏ cuối (giây)</span>
                        <input v-model.number="edit.trim_end" type="number" min="0" max="10" step="0.5" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                    </label>
                </div>
                <label class="block">
                    <span class="font-semibold text-[var(--color-ink)]">Caption Reel</span>
                    <span class="text-xs text-[var(--color-muted)]"> — link để ở 1–2 dòng đầu, phần sau bị Facebook gập vào "Xem thêm"</span>
                    <textarea v-model="edit.caption" rows="7" maxlength="2200"
                        class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-accent)]"></textarea>
                </label>
                <p v-for="(msg, key) in edit.errors" :key="key" class="text-red-500 text-xs">{{ msg }}</p>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" :disabled="edit.processing || !edit.isDirty"
                        class="bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] text-[var(--color-ink)] font-semibold px-5 py-2.5 rounded-xl text-sm transition disabled:opacity-60">
                        {{ needsRebuild ? 'Lưu & dựng lại video' : 'Lưu' }}
                    </button>
                    <button v-if="v.status === 'ready'" type="button" @click="publish"
                        class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-5 py-2.5 rounded-xl text-sm transition">
                        Đăng Reel lên page
                    </button>
                </div>
            </form>
        </div>

        <footer class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs">
            <label v-if="canUpload" class="font-semibold text-[var(--color-accent)] hover:underline cursor-pointer">
                {{ v.has_source ? 'Thay video gốc' : 'Tải video lên tay' }}
                <input type="file" accept="video/mp4,video/quicktime,video/webm" class="hidden" :disabled="upload.processing" @change="pickFile" />
            </label>
            <span v-if="upload.processing" class="text-[var(--color-muted)]">Đang tải lên {{ upload.progress?.percentage ?? 0 }}%</span>
            <span v-for="(msg, key) in upload.errors" :key="key" class="text-red-500">{{ msg }}</span>
            <button v-if="['failed', 'no_video'].includes(v.status)" @click="refetch" class="font-semibold text-[var(--color-accent)] hover:underline">Lấy lại bằng bot</button>
            <button v-if="!['processing', 'publishing'].includes(v.status)" @click="destroy" class="font-semibold text-red-500 hover:underline ml-auto">Xoá</button>
        </footer>
    </article>
</template>
