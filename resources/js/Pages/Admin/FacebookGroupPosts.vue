<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { router, useForm, usePage, Head, Link } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import GroupLinksDirectToggle from '@/Components/GroupLinksDirectToggle.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const page = usePage()

const props = defineProps({
    // [{ id, name, url, last_posted_at, ready, ready_at, queued, no_profile }] — xem groupReadiness() phía server.
    groups: Array,
    cooldownHours: Number,
    deals: Array,
    blocking: Object,
    today: Object,
    groupLinksDirectAffiliate: Boolean,
    maxImages: Number,
    // { version, supports_uploads, uploads_waiting, supports_comments, comments_waiting } — bot cũ
    // không đăng được ảnh tự tải lên, không biết bình luận link.
    runner: Object,
    // Mẫu bài tự soạn: [{ id, name, caption, images: [{ name, url }] }], mới sửa lên đầu.
    templates: Array,
})

const errors = computed(() => page.props.errors || {})

function flashToast() {
    const msg = page.props.flash?.success
    if (msg) toast.success(msg)
}

function fmt(iso) {
    return iso ? new Date(iso).toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' }) : '—'
}

function vnd(n) {
    return n ? Number(n).toLocaleString('vi-VN') + '₫' : ''
}

let timer = null
onMounted(() => {
    timer = setInterval(() => router.reload({ only: ['deals', 'blocking', 'today', 'runner', 'groups'] }), 30000)
})
onBeforeUnmount(() => {
    clearInterval(timer)
    clearPhotos()
})

const blockingText = computed(() => {
    const b = props.blocking
    if (!b) return 'bot được đăng ngay ở lượt hỏi tới'
    switch (b.reason) {
        case 'paused': return 'bot đang tạm dừng'
        case 'outside_window': return `ngoài khung giờ — đăng tiếp lúc ${fmt(b.until)}`
        case 'daily_cap': return `đã đủ bài hôm nay — đăng tiếp lúc ${fmt(b.until)}`
        case 'no_profile': return 'chưa bật page nào để đăng'
        case 'all_blocked': return 'mọi page đang nghỉ vì Facebook chặn'
        case 'gap': return `đang nghỉ giữa hai bài — bài kế tiếp sau ${fmt(b.until)}`
        default: return b.reason
    }
})

// ── Kiểu bài ────────────────────────────────────────────────────────────────
// 'link': dán link Shopee, hệ thống lấy mã và soạn sẵn; 'custom': tự viết, không có link mua.
const mode = ref('link')
const isCustom = computed(() => mode.value === 'custom')

function setMode(next) {
    if (mode.value === next) return
    if (isDirty.value && !confirm('Bỏ bài đang soạn dở?')) return
    mode.value = next
    resetDraft()
}

// ── Soạn bài ────────────────────────────────────────────────────────────────
const url = ref('')
const composing = ref(false)
const composeError = ref('')
const draft = ref(null)
const editing = computed(() => isCustom.value || draft.value !== null)

const form = useForm({
    shopee_url: '',
    canonical_url: null,
    source: null,
    product: null,
    caption: '',
    fallback_buy_url: null,
    fallback_ytb_url: null,
    // Bài không có link, bot đăng xong tự bình luận link mua vào bài (FacebookGroupCommentQueue).
    link_in_comment: true,
    group_ids: [],
})

async function compose() {
    composing.value = true
    composeError.value = ''
    try {
        const { data } = await axios.post('/admin/fb-posts/compose', { url: url.value })
        draft.value = data
        Object.assign(form, {
            shopee_url: data.shopee_url,
            canonical_url: data.canonical_url,
            source: data.source,
            product: data.product,
            caption: data.caption,
            fallback_buy_url: data.fallback_buy_url,
            fallback_ytb_url: data.fallback_ytb_url,
        })
        clearPhotos()
        if (data.product?.product_image) addProductPhoto()
        rollPreview()
    } catch (e) {
        composeError.value = e.response?.data?.message || 'Không soạn được bài, thử lại.'
    } finally {
        composing.value = false
    }
}

function resetDraft() {
    draft.value = null
    url.value = ''
    composeError.value = ''
    form.reset()
    form.clearErrors()
    clearPhotos()
    preview.value = ''
    activeTemplateId.value = null
}

// Giống FacebookDealCaption::render() phía server: thay {a|b} từ trong ra ngoài, rồi {link}.
const SPIN = /\{([^{}]*\|[^{}]*)\}/g
function renderCaption(template, linkBlock) {
    let text = template.replace(/\r\n/g, '\n')
    let prev
    do {
        prev = text
        text = text.replace(SPIN, (_, inner) => {
            const options = inner.split('|')
            return options[Math.floor(Math.random() * options.length)]
        })
    } while (text !== prev)
    return text.replaceAll('{link}', linkBlock).replace(/[ \t]+$/gm, '').trim()
}

const preview = ref('')
const linkInComment = computed(() => !isCustom.value && form.link_in_comment)
function rollPreview() {
    if (!editing.value) {
        preview.value = ''
        return
    }
    const linkBlock = isCustom.value ? '' : linkInComment.value ? renderCaption(draft.value.comment_hint, '') : draft.value.link_block
    preview.value = renderCaption(form.caption, linkBlock)
}
watch(() => form.link_in_comment, rollPreview)

const hasLinkToken = computed(() => form.caption.includes('{link}'))
const isDirty = computed(() => form.caption.trim() !== '' || photos.value.length > 0)

// Facebook chỉ chắc chắn biến chữ thành link bấm được khi viết liền "https://tên-miền". Tên miền
// trần ("dealngon.top") hay có dấu cách sau "https://" thì bài lên nhóm chỉ là chữ thường.
const SPACE_AFTER_SCHEME = /https?:\/\/\s/i
const BARE_DOMAIN = /(?<![\p{L}\p{N}./@:_-])((?:[a-z0-9-]+\.)+[a-z]{2,})(?![\p{L}\p{N}@_-])/giu
// Chỉ xét bài tự soạn: bài có link thì link mua do hệ thống chèn, còn tên sản phẩm kiểu "Size.XL"
// dễ bị nhận nhầm là tên miền.
const linkWarning = computed(() => {
    if (!isCustom.value) return ''
    const text = form.caption
    if (SPACE_AFTER_SCHEME.test(text)) return 'Có dấu cách ngay sau "https://" — Facebook sẽ không biến thành link. Viết liền, ví dụ: https://dealngon.top'
    const withoutUrls = text.replace(/https?:\/\/\S+/gi, ' ')
    const bare = [...new Set([...withoutUrls.matchAll(BARE_DOMAIN)].map(m => m[1]))]
    return bare.length ? `Thêm https:// trước ${bare.join(', ')} — thiếu nó Facebook có thể không biến thành link bấm được.` : ''
})
const linkTokenError = computed(() => {
    if (isCustom.value) return hasLinkToken.value ? 'Bài tự soạn không có link mua — bỏ {link} ra khỏi nội dung.' : ''
    return hasLinkToken.value ? '' : 'Thiếu {link} — bài sẽ không có link mua.'
})

// ── Ảnh ─────────────────────────────────────────────────────────────────────
// Mỗi ảnh: { key, kind: 'product' | 'upload', src, name, uploading }. Ảnh sản phẩm Shopee (nếu
// giữ) luôn đứng đầu — server cũng xếp như vậy (FacebookPostImages::refsFor).
const MAX_SIDE = 2048
const photos = ref([])
const photoError = ref('')
const photoInput = ref(null)
let nextKey = 1

const roomLeft = computed(() => props.maxImages - photos.value.length)
const uploading = computed(() => photos.value.some(p => p.uploading))
const hasUploads = computed(() => photos.value.some(p => p.kind === 'upload'))
const productPhotoRemoved = computed(() =>
    !isCustom.value && !!draft.value?.product?.product_image && !photos.value.some(p => p.kind === 'product'))

function addProductPhoto() {
    photos.value.unshift({ key: nextKey++, kind: 'product', src: draft.value.product.product_image, name: null, uploading: false })
}

function pickPhotos() {
    photoInput.value?.click()
}

function onPhotosPicked(event) {
    const files = Array.from(event.target.files || []).filter(f => f.type.startsWith('image/'))
    event.target.value = '' // chọn lại đúng ảnh vừa bỏ vẫn phải kích hoạt change
    photoError.value = ''

    const room = Math.max(0, roomLeft.value)
    if (files.length > room) photoError.value = `Mỗi bài tối đa ${props.maxImages} ảnh — chỉ lấy ${room} ảnh đầu.`

    for (const file of files.slice(0, room)) {
        const photo = reactive({ key: nextKey++, kind: 'upload', src: URL.createObjectURL(file), name: null, uploading: true })
        photos.value.push(photo)
        uploadPhoto(photo, file)
    }
}

async function uploadPhoto(photo, file) {
    try {
        const body = new FormData()
        body.append('image', await shrink(file), 'anh.jpg')
        const { data } = await axios.post('/admin/fb-posts/images', body)
        photo.name = data.name
    } catch (e) {
        removePhoto(photo)
        photoError.value = e.response?.status === 413
            ? 'Ảnh quá lớn so với giới hạn của server.'
            : e.response?.data?.errors?.image?.[0] || e.response?.data?.message || e.message || 'Tải ảnh lên lỗi, thử lại.'
    } finally {
        photo.uploading = false
    }
}

// Nén ở trình duyệt trước khi tải lên: ảnh điện thoại 5–12 MB còn vài trăm KB (Facebook cũng chỉ
// giữ cạnh dài 2048px), và vẽ lại qua canvas làm rụng EXIF — kể cả toạ độ GPS nơi chụp.
// Giữ dưới ~1 MB: PHP mặc định chỉ nhận file 2 MB, nginx mặc định chỉ nhận request 1 MB.
const MAX_UPLOAD_BYTES = 950 * 1024
const SHRINK_STEPS = [[MAX_SIDE, 0.85], [MAX_SIDE, 0.75], [1600, 0.75], [1280, 0.7]]

async function shrink(file) {
    let bitmap
    try {
        bitmap = await createImageBitmap(file)
    } catch {
        throw new Error(`Không đọc được ảnh "${file.name}" (ảnh HEIC của iPhone?) — đổi sang JPG/PNG rồi thử lại.`)
    }
    try {
        let blob = null
        for (const [side, quality] of SHRINK_STEPS) {
            blob = await toJpeg(bitmap, side, quality)
            if (blob.size <= MAX_UPLOAD_BYTES) break
        }
        return blob
    } finally {
        bitmap.close()
    }
}

async function toJpeg(bitmap, maxSide, quality) {
    const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height))
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(bitmap.width * scale)
    canvas.height = Math.round(bitmap.height * scale)
    const ctx = canvas.getContext('2d')
    // JPEG không có nền trong suốt — lấp trắng trước, không thì vùng trong suốt của PNG ra màu đen.
    ctx.fillStyle = '#fff'
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality))
    if (!blob) throw new Error('Không nén được ảnh, thử ảnh khác.')
    return blob
}

// Ảnh vừa chọn hiện bằng URL blob (phải thu hồi); ảnh nạp từ mẫu/bài cũ hiện bằng URL server.
function revokePreview(photo) {
    if (photo.src.startsWith('blob:')) URL.revokeObjectURL(photo.src)
}

function removePhoto(photo) {
    photos.value = photos.value.filter(p => p.key !== photo.key)
    revokePreview(photo)
}

function clearPhotos() {
    photos.value.forEach(revokePreview)
    photos.value = []
    photoError.value = ''
}

// ── Mẫu bài + "Dùng lại" ────────────────────────────────────────────────────
const activeTemplateId = ref(null)
const activeTemplate = computed(() => props.templates.find(t => t.id === activeTemplateId.value) || null)
const savingTemplate = ref(false)

// Nạp nội dung + ảnh (từ mẫu, hoặc từ bài tự soạn đã xếp) vào ô soạn — giữ nguyên nhóm đã tích.
function loadCustom(source, templateId = null) {
    if (isDirty.value && !confirm('Thay bài đang soạn bằng nội dung này?')) return
    const groupIds = form.group_ids
    mode.value = 'custom'
    resetDraft()
    form.group_ids = groupIds
    form.caption = source.caption
    photos.value = source.images.slice(0, props.maxImages)
        .map(img => ({ key: nextKey++, kind: 'upload', src: img.url, name: img.name, uploading: false }))
    activeTemplateId.value = templateId
    rollPreview()
}

function reuseDeal(deal) {
    loadCustom(deal.reuse)
    window.scrollTo({ top: 0, behavior: 'smooth' })
}

const canSaveTemplate = computed(() => !savingTemplate.value && !uploading.value
    && form.caption.trim() !== '' && !hasLinkToken.value)

function saveTemplate(overwrite) {
    const current = activeTemplate.value
    const name = overwrite ? current.name : window.prompt('Tên mẫu (để lần sau chọn lại):', '')?.trim()
    if (!name) return

    const payload = { name, caption: form.caption, images: photos.value.filter(p => p.kind === 'upload').map(p => p.name) }
    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => { savingTemplate.value = true },
        onFinish: () => { savingTemplate.value = false },
        onSuccess: () => {
            // Mẫu vừa lưu/sửa đứng đầu danh sách (sắp theo lần sửa gần nhất).
            activeTemplateId.value = overwrite ? current.id : props.templates[0]?.id ?? null
            flashToast()
        },
        onError: (errs) => toast.error(Object.values(errs)[0] || 'Không lưu được mẫu.'),
    }
    if (overwrite) router.put(`/admin/fb-posts/templates/${current.id}`, payload, options)
    else router.post('/admin/fb-posts/templates', payload, options)
}

function deleteTemplate(template) {
    if (!confirm(`Xoá mẫu "${template.name}"? Bài đã xếp từ mẫu này không bị ảnh hưởng.`)) return
    router.delete(`/admin/fb-posts/templates/${template.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            if (activeTemplateId.value === template.id) activeTemplateId.value = null
            flashToast()
        },
    })
}

const imageErrors = computed(() => Object.entries(form.errors).filter(([key]) => key === 'images' || key.startsWith('images.')).map(([, msg]) => msg))

// ── Chọn nhóm + xếp hàng ────────────────────────────────────────────────────
// Chỉ cho tích nhóm đăng được ngay. Nhóm còn trong giãn cách "mỗi nhóm 1 bài / N giờ" hoặc đã có
// bài chờ thì để riêng, khoá lại — xếp vào là bài nằm chờ cả buổi. Sớm hết chờ đứng trước.
const readyGroups = computed(() => props.groups.filter(g => g.ready))
const waitingGroups = computed(() => props.groups.filter(g => !g.ready).sort((a, b) =>
    (a.queued - b.queued) || (Date.parse(a.ready_at) || 0) - (Date.parse(b.ready_at) || 0)))
const nextReadyAt = computed(() => waitingGroups.value.find(g => !g.queued && g.ready_at)?.ready_at)
const showWaiting = ref(false)

// Trang tự tải lại mỗi 30 giây: nhóm vừa hết chờ hiện ra để tích, nhóm vừa có bài (tab khác xếp)
// thì tự bỏ tích.
watch(readyGroups, (groups) => {
    const ids = new Set(groups.map(g => g.id))
    if (form.group_ids.some(id => !ids.has(id))) form.group_ids = form.group_ids.filter(id => ids.has(id))
})

function toggleAll() {
    form.group_ids = form.group_ids.length === readyGroups.value.length ? [] : readyGroups.value.map(g => g.id)
}

const canQueue = computed(() => !form.processing && !uploading.value && form.group_ids.length > 0
    && form.caption.trim() !== '' && !linkTokenError.value)

function queue() {
    form.transform(data => ({
        ...data,
        with_product_image: photos.value.some(p => p.kind === 'product'),
        images: photos.value.filter(p => p.kind === 'upload').map(p => p.name),
    })).post('/admin/fb-posts', {
        preserveScroll: true,
        onSuccess: () => {
            flashToast()
            resetDraft()
        },
    })
}

// ── Bài đã xếp ──────────────────────────────────────────────────────────────
const statusLabel = {
    pending: 'Chờ đăng',
    claimed: 'Bot đang đăng',
    posted: 'Đã đăng',
    pending_approval: 'Chờ admin nhóm duyệt',
    failed: 'Lỗi, chưa đăng',
    ambiguous: 'Không rõ — xem nhóm',
    not_allowed: 'Nhóm không cho đăng',
    blocked: 'FB tạm chặn',
    checkpoint: 'FB bắt xác minh',
    cancelled: 'Đã huỷ',
    expired: 'Hết hạn',
}
const statusClass = (s) => ({
    posted: 'bg-green-100 text-green-700',
    pending_approval: 'bg-green-50 text-green-700',
    pending: 'bg-amber-100 text-amber-700',
    claimed: 'bg-amber-100 text-amber-700',
    cancelled: 'bg-gray-100 text-gray-500',
    expired: 'bg-gray-100 text-gray-500',
}[s] || 'bg-red-100 text-red-600')

const RETRYABLE = ['failed', 'ambiguous', 'not_allowed', 'blocked', 'checkpoint', 'expired', 'cancelled']

// Bài "link ở bình luận": bot bình luận link khi bài đã hiện trên nhóm (đăng thẳng, hoặc đã duyệt).
function commentNote(post) {
    switch (post.comment_status) {
        case 'done': return { text: 'đã bình luận link', cls: 'text-green-700' }
        case 'unknown': return { text: 'đã gửi bình luận nhưng chưa thấy hiện — mở bài xem', cls: 'text-amber-600' }
        case 'failed': return { text: 'chưa bình luận được link', cls: 'text-red-600' }
        case 'pending': {
            if (!['posted', 'pending_approval', 'ambiguous'].includes(post.status)) return null
            if (['declined', 'removed', 'missing'].includes(post.review_state)) return null
            const online = post.review_state === 'published' || (post.status === 'posted' && !post.review_state)
            return online
                ? { text: 'chờ bình luận link', cls: 'text-amber-600' }
                : { text: 'bình luận link khi bài được duyệt', cls: 'text-[var(--color-muted)]' }
        }
        default: return null
    }
}

// Bot kiểm tra lại "Nội dung của bạn" trong nhóm sau khi đăng (FacebookGroupReviewChecker) — có
// kết quả thì hiện kết quả đó thay cho điều bot thấy lúc bấm Đăng.
const reviewLabel = {
    published: 'Đã lên nhóm',
    pending: 'Chờ admin nhóm duyệt',
    declined: 'Bị từ chối',
    removed: 'Bị admin gỡ',
    missing: 'Không thấy trên nhóm',
}
const reviewClass = (s) => ({
    published: 'bg-green-100 text-green-700',
    pending: 'bg-amber-100 text-amber-700',
    missing: 'bg-gray-100 text-gray-500',
}[s] || 'bg-red-100 text-red-600')

const badge = (post) => post.review_state
    ? { label: reviewLabel[post.review_state] || post.review_state, cls: reviewClass(post.review_state) }
    : { label: statusLabel[post.status] || post.status, cls: statusClass(post.status) }

function cancel(post) {
    router.post(`/admin/fb-posts/${post.id}/cancel`, {}, { preserveScroll: true, onSuccess: flashToast })
}

function retry(post) {
    if (post.status === 'ambiguous' && !confirm('Bài này có thể ĐÃ lên nhóm. Mở nhóm kiểm tra trước — đăng lại có thể bị trùng. Vẫn đăng lại?')) return
    router.post(`/admin/fb-posts/${post.id}/retry`, {}, { preserveScroll: true, onSuccess: flashToast })
}
</script>

<template>
    <Head title="Admin — Đăng nhóm FB" />
    <AdminLayout>
        <template #title>Đăng bài vào nhóm Facebook</template>

        <p class="text-sm text-[var(--color-muted)] mb-5">
            Hôm nay <span class="font-semibold text-[var(--color-ink)]">{{ today.used }}/{{ today.max }}</span> bài · lúc này {{ blockingText }} ·
            <Link href="/admin/fb-groups" class="font-semibold text-[var(--color-accent)] hover:underline">Nhóm & bot</Link>
        </p>

        <div v-if="runner.uploads_waiting" class="mb-5 rounded-2xl border border-amber-300 bg-[var(--color-peach-soft)] text-sm text-amber-700 p-4">
            <span class="font-semibold">Có bài kèm ảnh tự tải lên đang chờ:</span>
            bot trên điện thoại đang chạy bản {{ runner.version || 'cũ' }}, chưa đăng được ảnh tự tải nên tạm bỏ qua những bài đó.
            Cập nhật bot (<span class="font-mono">git pull</span> trong thư mục repo trên điện thoại rồi chạy lại bot) là bài được đăng tiếp.
        </div>

        <div v-if="runner.comments_waiting" class="mb-5 rounded-2xl border border-amber-300 bg-[var(--color-peach-soft)] text-sm text-amber-700 p-4">
            <span class="font-semibold">Có bài "link ở bình luận" đang chờ:</span>
            bot trên điện thoại đang chạy bản {{ runner.version || 'cũ' }}, chưa biết bình luận nên tạm bỏ qua những bài đó.
            Cập nhật bot (<span class="font-mono">git pull</span> trong thư mục repo trên điện thoại rồi chạy lại bot) là bài được đăng tiếp.
        </div>

        <GroupLinksDirectToggle v-if="!isCustom" :enabled="groupLinksDirectAffiliate" class="mb-5" />

        <!-- 1. Kiểu bài -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <div class="inline-flex rounded-xl border border-[var(--color-line)] p-1 mb-4 text-sm font-semibold">
                <button type="button" @click="setMode('link')" class="px-4 py-1.5 rounded-lg transition"
                    :class="!isCustom ? 'bg-[var(--color-accent)] text-white' : 'text-[var(--color-muted)] hover:text-[var(--color-ink)]'">
                    Có link Shopee
                </button>
                <button type="button" @click="setMode('custom')" class="px-4 py-1.5 rounded-lg transition"
                    :class="isCustom ? 'bg-[var(--color-accent)] text-white' : 'text-[var(--color-muted)] hover:text-[var(--color-ink)]'">
                    Bài tự soạn
                </button>
            </div>

            <template v-if="!isCustom">
                <h2 class="font-extrabold text-[var(--color-ink)] mb-1">1. Dán link sản phẩm Shopee</h2>
                <p class="text-sm text-[var(--color-muted)] mb-3">
                    {{ groupLinksDirectAffiliate
                        ? 'Hệ thống đổi sang link affiliate Shopee (không mã) và soạn sẵn bài — bạn sửa chữ, đổi ảnh rồi chọn nhóm.'
                        : 'Hệ thống lấy mã, tạo link có mã của mình và soạn sẵn bài — bạn sửa chữ, đổi ảnh rồi chọn nhóm.' }}
                </p>

                <form @submit.prevent="compose" class="flex flex-col md:flex-row gap-2">
                    <input v-model="url" type="text" required placeholder="https://s.shopee.vn/... hoặc https://shopee.vn/..."
                        class="flex-1 border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--color-accent)]" />
                    <button type="submit" :disabled="composing"
                        class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition disabled:opacity-60 whitespace-nowrap">
                        {{ composing ? 'Đang lấy mã…' : 'Soạn bài' }}
                    </button>
                </form>
                <p v-if="composing" class="text-xs text-[var(--color-muted)] mt-2">Có thể mất tới 45 giây khi đang bật chế độ mã YTB.</p>
                <p v-if="composeError" class="text-red-500 text-sm mt-2">{{ composeError }}</p>
            </template>

            <template v-else>
                <h2 class="font-extrabold text-[var(--color-ink)] mb-1">1. Bài tự soạn</h2>
                <p class="text-sm text-[var(--color-muted)]">
                    Không cần link Shopee: tự viết nội dung, tự chọn tối đa {{ maxImages }} ảnh — hợp cho bài chia sẻ mẹo, gom mã, giới thiệu nhóm…
                </p>
                <p class="text-xs text-amber-600 mt-1">
                    Link Shopee dán thẳng vào bài tự soạn KHÔNG được đổi sang link của mình (không có hoa hồng). Đăng deal thì dùng "Có link Shopee".
                </p>

                <div class="mt-4">
                    <p class="text-xs font-semibold text-[var(--color-ink)] mb-2">Mẫu có sẵn</p>
                    <p v-if="!templates.length" class="text-xs text-[var(--color-muted)]">
                        Chưa có mẫu nào — soạn bài bên dưới rồi bấm "Lưu thành mẫu", lần sau bấm vào mẫu là có sẵn chữ và ảnh.
                    </p>
                    <div v-else class="flex flex-wrap gap-2">
                        <div v-for="t in templates" :key="t.id" class="relative">
                            <button type="button" @click="loadCustom(t, t.id)"
                                class="flex items-center gap-2 border rounded-xl pl-1.5 pr-8 py-1.5 text-left transition hover:border-[var(--color-accent)]"
                                :class="activeTemplateId === t.id ? 'border-[var(--color-accent)] bg-[var(--color-peach-soft)]' : 'border-[var(--color-line)]'">
                                <img v-if="t.images.length" :src="t.images[0].url" alt="" class="w-9 h-9 rounded-lg object-cover shrink-0" />
                                <span v-else class="w-9 h-9 rounded-lg bg-[var(--color-peach-soft)] flex items-center justify-center text-xs shrink-0">Aa</span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold text-[var(--color-ink)] truncate max-w-[12rem]">{{ t.name }}</span>
                                    <span class="block text-[11px] text-[var(--color-muted)]">{{ t.images.length }} ảnh</span>
                                </span>
                            </button>
                            <button type="button" @click="deleteTemplate(t)" :aria-label="`Xoá mẫu ${t.name}`"
                                class="absolute top-1 right-1.5 w-6 h-6 rounded-full text-[var(--color-muted)] hover:text-red-500 text-base leading-none">×</button>
                        </div>
                    </div>
                </div>
            </template>
        </section>

        <!-- 2. Sửa bài, ảnh + chọn nhóm -->
        <section v-if="editing" class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-3">2. Viết bài, chọn ảnh và nhóm</h2>

            <div v-if="!isCustom" class="text-sm mb-4">
                <p class="font-semibold text-[var(--color-ink)]">{{ draft.product?.product_name || 'Không có tên sản phẩm' }}</p>
                <p class="text-[var(--color-muted)]">
                    {{ vnd(draft.product?.discounted_price) }}
                    <span v-if="draft.product?.discount_percent"> · giảm {{ Math.round(draft.product.discount_percent) }}%</span>
                </p>
            </div>

            <div class="grid md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">{{ isCustom ? 'Nội dung bài' : 'Mẫu bài' }}</label>
                    <textarea v-model="form.caption" @input="rollPreview" rows="10"
                        :placeholder="isCustom ? '{Chào cả nhà|Hello mọi người} 👋\nHôm nay mình chia sẻ…' : ''"
                        class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm font-mono focus:outline-none focus:border-[var(--color-accent)]"></textarea>
                    <label v-if="!isCustom" class="flex items-start gap-2 text-sm mt-2">
                        <input type="checkbox" v-model="form.link_in_comment" class="w-4 h-4 mt-0.5 accent-[var(--color-accent)]" />
                        <span>
                            <span class="font-semibold text-[var(--color-ink)]">Để link ở bình luận</span>
                            <span class="block text-xs text-[var(--color-muted)]">Bài không có link nào (đỡ bị coi là spam). Bài lên nhóm rồi bot tự vào bình luận link mua; bài phải chờ duyệt thì bình luận sau khi được duyệt.</span>
                        </span>
                    </label>
                    <p v-if="linkInComment && !runner.supports_comments" class="text-amber-600 text-xs mt-1">
                        Bot trên điện thoại đang chạy bản {{ runner.version || 'cũ' }} — chưa biết bình luận. Bài này sẽ chờ tới khi bạn cập nhật bot.
                    </p>
                    <p class="text-xs text-[var(--color-muted)] mt-1">
                        <template v-if="linkInComment"><span class="font-mono">{link}</span> là chỗ đặt câu "link ở bình luận" — để riêng một dòng. </template>
                        <template v-else-if="!isCustom"><span class="font-mono">{link}</span> là chỗ đặt link mua — để riêng một dòng. </template>
                        <span class="font-mono">{a|b|c}</span>: mỗi nhóm nhận ngẫu nhiên một lựa chọn, để các nhóm không thấy cùng một câu y hệt.
                    </p>
                    <p v-if="linkTokenError" class="text-red-500 text-xs mt-1">{{ linkTokenError }}</p>
                    <p v-if="linkWarning" class="text-amber-600 text-xs mt-1">{{ linkWarning }}</p>
                    <p v-if="form.errors.caption" class="text-red-500 text-xs mt-1">{{ form.errors.caption }}</p>
                </div>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-xs font-semibold text-[var(--color-ink)]">Một nhóm sẽ thấy, ví dụ:</label>
                        <button type="button" @click="rollPreview" class="text-xs font-semibold text-[var(--color-accent)] hover:underline">Xem câu khác</button>
                    </div>
                    <pre class="whitespace-pre-wrap text-sm bg-[var(--color-peach-soft)] rounded-xl p-3 min-h-[12rem]">{{ preview }}</pre>
                    <template v-if="linkInComment">
                        <p class="text-xs font-semibold text-[var(--color-ink)] mt-3 mb-1">Bot bình luận vào bài:</p>
                        <pre class="whitespace-pre-wrap text-sm border border-[var(--color-line)] rounded-xl p-3">{{ draft.link_block }}</pre>
                    </template>
                    <p v-if="!isCustom" class="text-xs text-[var(--color-muted)] mt-1">Link thật được tạo lại lúc bot đăng, để mã còn lượt.</p>
                </div>
            </div>

            <div class="mb-4">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-xs font-semibold text-[var(--color-ink)]">Ảnh ({{ photos.length }}/{{ maxImages }})</p>
                    <button v-if="productPhotoRemoved && roomLeft > 0" type="button" @click="addProductPhoto" class="text-xs font-semibold text-[var(--color-accent)] hover:underline">
                        Thêm lại ảnh sản phẩm
                    </button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <div v-for="(photo, index) in photos" :key="photo.key" class="relative w-24 h-24 rounded-xl overflow-hidden border border-[var(--color-line)]">
                        <img :src="photo.src" alt="" class="w-full h-full object-cover" :class="{ 'opacity-40': photo.uploading }" />
                        <span v-if="photo.uploading" class="absolute inset-0 flex items-center justify-center text-[11px] font-semibold text-[var(--color-ink)]">Đang tải…</span>
                        <span v-if="photo.kind === 'product'" class="absolute bottom-1 left-1 text-[10px] font-semibold bg-black/60 text-white px-1.5 rounded">Shopee</span>
                        <span v-else-if="index === 0" class="absolute bottom-1 left-1 text-[10px] font-semibold bg-black/60 text-white px-1.5 rounded">Ảnh đầu</span>
                        <button type="button" @click="removePhoto(photo)" aria-label="Bỏ ảnh này"
                            class="absolute top-1 right-1 w-6 h-6 rounded-full bg-black/60 hover:bg-black/80 text-white text-sm leading-none">×</button>
                    </div>
                    <button v-if="roomLeft > 0" type="button" @click="pickPhotos"
                        class="w-24 h-24 rounded-xl border-2 border-dashed border-[var(--color-line)] hover:border-[var(--color-accent)] text-xs font-semibold text-[var(--color-muted)] hover:text-[var(--color-accent)] flex flex-col items-center justify-center transition">
                        <span class="text-2xl leading-none">+</span>
                        Thêm ảnh
                    </button>
                </div>
                <input ref="photoInput" type="file" accept="image/*" multiple class="hidden" @change="onPhotosPicked" />
                <p class="text-xs text-[var(--color-muted)] mt-1">
                    Ảnh đầu tiên hiện to nhất trên Facebook.
                    {{ isCustom ? 'Không chọn ảnh thì bài chỉ có chữ.' : 'Bỏ hết ảnh thì Facebook tự hiện khung xem trước của link.' }}
                </p>
                <p v-if="photoError" class="text-red-500 text-xs mt-1">{{ photoError }}</p>
                <p v-for="msg in imageErrors" :key="msg" class="text-red-500 text-xs mt-1">{{ msg }}</p>
                <p v-if="hasUploads && !runner.supports_uploads" class="text-amber-600 text-xs mt-1">
                    Bot trên điện thoại đang chạy bản {{ runner.version || 'cũ' }} — chưa đăng được ảnh tự tải. Bài này sẽ chờ tới khi bạn cập nhật bot.
                </p>
            </div>

            <div class="mb-4">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-xs font-semibold text-[var(--color-ink)]">
                        Đăng vào nhóm ({{ form.group_ids.length }}/{{ readyGroups.length }} nhóm đăng được lúc này)
                    </p>
                    <button v-if="readyGroups.length" type="button" @click="toggleAll" class="text-xs font-semibold text-[var(--color-accent)] hover:underline">
                        {{ form.group_ids.length === readyGroups.length ? 'Bỏ chọn hết' : 'Chọn hết' }}
                    </button>
                </div>
                <p v-if="!groups.length" class="text-sm text-amber-600">
                    Chưa bật nhóm nào — vào <Link href="/admin/fb-groups" class="font-semibold underline">Nhóm & bot</Link> để lấy nhóm và bật nhóm được đăng.
                </p>
                <p v-else-if="!readyGroups.length" class="text-sm text-amber-600">
                    Lúc này chưa nhóm nào đăng được — mỗi nhóm {{ cooldownHours }} giờ mới nhận 1 bài<template v-if="nextReadyAt">, nhóm sớm nhất đăng được lúc {{ fmt(nextReadyAt) }}</template>.
                </p>
                <div v-else class="grid md:grid-cols-2 gap-1.5 max-h-64 overflow-y-auto">
                    <label v-for="g in readyGroups" :key="g.id" class="flex items-center gap-2 text-sm px-2 py-1.5 rounded-lg hover:bg-[var(--color-peach-soft)]">
                        <input type="checkbox" :value="g.id" v-model="form.group_ids" class="w-4 h-4 accent-[var(--color-accent)]" />
                        <span class="truncate">{{ g.name || g.url }}</span>
                        <span v-if="g.last_posted_at" class="ml-auto text-[10px] text-[var(--color-muted)] whitespace-nowrap">đăng {{ fmt(g.last_posted_at) }}</span>
                    </label>
                </div>

                <template v-if="waitingGroups.length">
                    <button type="button" @click="showWaiting = !showWaiting" class="mt-2 text-xs font-semibold text-[var(--color-muted)] hover:text-[var(--color-ink)]">
                        {{ showWaiting ? '▾' : '▸' }} {{ waitingGroups.length }} nhóm chưa đăng được lúc này (không chọn được)
                    </button>
                    <div v-if="showWaiting" class="grid md:grid-cols-2 gap-1.5 max-h-64 overflow-y-auto mt-1.5">
                        <div v-for="g in waitingGroups" :key="g.id" class="flex items-center gap-2 text-sm px-2 py-1.5 rounded-lg opacity-50 cursor-not-allowed">
                            <input type="checkbox" disabled class="w-4 h-4" />
                            <span class="truncate">{{ g.name || g.url }}</span>
                            <span class="ml-auto text-[10px] text-[var(--color-muted)] whitespace-nowrap">
                                {{ g.no_profile ? 'chưa page nào vào nhóm' : g.queued ? 'đã có bài chờ đăng' : `đăng được lúc ${fmt(g.ready_at)}` }}
                            </span>
                        </div>
                    </div>
                </template>
                <p v-if="form.errors.group_ids" class="text-red-500 text-xs mt-1">{{ form.errors.group_ids }}</p>
                <p v-for="(msg, key) in form.errors" :key="key" v-show="key.startsWith('group_ids.') || key.startsWith('fallback') || key === 'shopee_url'" class="text-red-500 text-xs mt-1">{{ msg }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button @click="queue" :disabled="!canQueue"
                    class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition disabled:opacity-50">
                    {{ uploading ? 'Đang tải ảnh lên…' : `Xếp ${form.group_ids.length} bài vào hàng đợi` }}
                </button>
                <template v-if="isCustom">
                    <button type="button" @click="saveTemplate(false)" :disabled="!canSaveTemplate"
                        class="border border-[var(--color-line)] hover:border-[var(--color-accent)] text-[var(--color-ink)] font-semibold px-4 py-2.5 rounded-xl text-sm transition disabled:opacity-50">
                        Lưu thành mẫu mới
                    </button>
                    <button v-if="activeTemplate" type="button" @click="saveTemplate(true)" :disabled="!canSaveTemplate"
                        class="border border-[var(--color-line)] hover:border-[var(--color-accent)] text-[var(--color-ink)] font-semibold px-4 py-2.5 rounded-xl text-sm transition disabled:opacity-50 max-w-full truncate">
                        Lưu đè mẫu "{{ activeTemplate.name }}"
                    </button>
                </template>
            </div>
        </section>

        <!-- Bài đã xếp -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">Bài đã xếp</h2>
            <p class="text-xs text-[var(--color-muted)] mb-3">
                Khoảng 1 giờ sau khi đăng, lúc rảnh bot mở "Nội dung của bạn" trong nhóm xem bài đã lên, còn chờ duyệt hay bị từ chối/gỡ.
            </p>
            <p v-if="errors.post" class="text-red-500 text-xs mb-3">{{ errors.post }}</p>

            <div v-for="deal in deals" :key="deal.id" class="border border-[var(--color-line)] rounded-xl p-4 mb-3">
                <div class="flex gap-3 items-start mb-3">
                    <div v-if="deal.images.length" class="relative shrink-0">
                        <img :src="deal.images[0]" alt="" class="w-12 h-12 rounded-lg object-cover" />
                        <span v-if="deal.images.length > 1" class="absolute -bottom-1 -right-1 text-[10px] font-semibold bg-black/70 text-white rounded-full px-1.5">+{{ deal.images.length - 1 }}</span>
                    </div>
                    <div class="text-sm min-w-0">
                        <p class="font-semibold text-[var(--color-ink)] truncate">
                            <span v-if="deal.custom" class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-[var(--color-peach-soft)] mr-1">Tự soạn</span>
                            {{ deal.product?.product_name || deal.excerpt || deal.shopee_url }}
                        </p>
                        <p class="text-xs text-[var(--color-muted)]">
                            Xếp lúc {{ fmt(deal.created_at) }}<template v-if="!deal.custom"> · {{ deal.clicks }} lượt bấm link</template>
                        </p>
                    </div>
                    <button v-if="deal.reuse" type="button" @click="reuseDeal(deal)"
                        class="ml-auto shrink-0 text-xs font-semibold text-[var(--color-accent)] hover:underline">Dùng lại</button>
                </div>
                <ul class="space-y-1.5">
                    <li v-for="post in deal.posts" :key="post.id" class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full whitespace-nowrap" :class="badge(post).cls">{{ badge(post).label }}</span>
                        <a :href="post.group_url" target="_blank" rel="noopener" class="hover:underline truncate max-w-[16rem]">{{ post.group_name || post.group_url }}</a>
                        <span v-if="post.profile_name" class="text-[11px] text-[var(--color-muted)]">· {{ post.profile_name }}</span>
                        <span v-if="post.finished_at" class="text-[11px] text-[var(--color-muted)]">{{ fmt(post.finished_at) }}</span>
                        <span v-if="post.reviewed_at" class="text-[11px] text-[var(--color-muted)]">· kiểm tra {{ fmt(post.reviewed_at) }}</span>
                        <a v-if="post.post_url" :href="post.post_url" target="_blank" rel="noopener" class="text-[11px] font-semibold text-[var(--color-accent)] hover:underline">Xem bài</a>
                        <span v-if="post.link_kind === 'fallback'" class="text-[11px] text-amber-600">dùng link lúc soạn</span>
                        <span v-if="commentNote(post)" class="text-[11px] font-semibold" :class="commentNote(post).cls">· {{ commentNote(post).text }}</span>
                        <button v-if="post.status === 'pending'" @click="cancel(post)" class="ml-auto text-xs font-semibold text-red-500 hover:underline">Huỷ</button>
                        <button v-else-if="RETRYABLE.includes(post.status)" @click="retry(post)" class="ml-auto text-xs font-semibold text-[var(--color-accent)] hover:underline">Đăng lại</button>
                        <p v-if="post.error" class="w-full text-xs text-[var(--color-muted)] pl-1">{{ post.error }}</p>
                        <p v-if="post.comment_error && post.comment_status !== 'done'" class="w-full text-xs text-[var(--color-muted)] pl-1">Bình luận: {{ post.comment_error }}</p>
                    </li>
                </ul>
            </div>
            <p v-if="!deals.length" class="text-sm text-center text-[var(--color-muted)] py-8">Chưa có bài nào.</p>
        </section>
    </AdminLayout>
</template>
