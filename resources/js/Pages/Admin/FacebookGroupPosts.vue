<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { router, useForm, usePage, Head, Link } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import GroupLinksDirectToggle from '@/Components/GroupLinksDirectToggle.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const page = usePage()

const props = defineProps({
    groups: Array,
    deals: Array,
    blocking: Object,
    today: Object,
    groupLinksDirectAffiliate: Boolean,
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
    timer = setInterval(() => router.reload({ only: ['deals', 'blocking', 'today'] }), 30000)
})
onBeforeUnmount(() => clearInterval(timer))

const blockingText = computed(() => {
    const b = props.blocking
    if (!b) return 'bot được đăng ngay ở lượt hỏi tới'
    switch (b.reason) {
        case 'paused': return 'bot đang tạm dừng'
        case 'outside_window': return `ngoài khung giờ — đăng tiếp lúc ${fmt(b.until)}`
        case 'daily_cap': return `đã đủ bài hôm nay — đăng tiếp lúc ${fmt(b.until)}`
        case 'gap': return `đang nghỉ giữa hai bài — bài kế tiếp sau ${fmt(b.until)}`
        default: return b.reason
    }
})

// ── Soạn bài ────────────────────────────────────────────────────────────────
const url = ref('')
const composing = ref(false)
const composeError = ref('')
const draft = ref(null)

const form = useForm({
    shopee_url: '',
    canonical_url: null,
    source: null,
    product: null,
    caption: '',
    fallback_buy_url: null,
    fallback_ytb_url: null,
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
        rollPreview()
    } catch (e) {
        composeError.value = e.response?.data?.message || 'Không soạn được bài, thử lại.'
    } finally {
        composing.value = false
    }
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
function rollPreview() {
    preview.value = draft.value ? renderCaption(form.caption, draft.value.link_block) : ''
}

const hasLinkToken = computed(() => form.caption.includes('{link}'))

function toggleAll() {
    form.group_ids = form.group_ids.length === props.groups.length ? [] : props.groups.map(g => g.id)
}

function queue() {
    form.post('/admin/fb-posts', {
        preserveScroll: true,
        onSuccess: () => {
            flashToast()
            draft.value = null
            url.value = ''
            form.reset()
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
        <template #title>Đăng deal vào nhóm Facebook</template>

        <p class="text-sm text-[var(--color-muted)] mb-5">
            Hôm nay <span class="font-semibold text-[var(--color-ink)]">{{ today.used }}/{{ today.max }}</span> bài · lúc này {{ blockingText }} ·
            <Link href="/admin/fb-groups" class="font-semibold text-[var(--color-accent)] hover:underline">Nhóm & bot</Link>
        </p>

        <GroupLinksDirectToggle :enabled="groupLinksDirectAffiliate" class="mb-5" />

        <!-- 1. Soạn bài -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">1. Dán link sản phẩm Shopee</h2>
            <p class="text-sm text-[var(--color-muted)] mb-3">
                {{ groupLinksDirectAffiliate
                    ? 'Hệ thống đổi sang link affiliate Shopee (không mã) và soạn sẵn bài — bạn sửa lại rồi chọn nhóm.'
                    : 'Hệ thống lấy mã, tạo link có mã của mình và soạn sẵn bài — bạn sửa lại rồi chọn nhóm.' }}
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
        </section>

        <!-- 2. Sửa bài + chọn nhóm -->
        <section v-if="draft" class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-3">2. Sửa bài và chọn nhóm</h2>

            <div class="flex gap-3 items-start mb-4">
                <img v-if="draft.product?.product_image" :src="draft.product.product_image" alt="" class="w-20 h-20 rounded-xl object-cover border border-[var(--color-line)]" />
                <div class="text-sm">
                    <p class="font-semibold text-[var(--color-ink)]">{{ draft.product?.product_name || 'Không có tên sản phẩm' }}</p>
                    <p class="text-[var(--color-muted)]">
                        {{ vnd(draft.product?.discounted_price) }}
                        <span v-if="draft.product?.discount_percent"> · giảm {{ Math.round(draft.product.discount_percent) }}%</span>
                    </p>
                    <p v-if="!draft.product?.product_image" class="text-xs text-amber-600">Không có ảnh — bài sẽ đăng chữ, Facebook tự hiện khung xem trước của link.</p>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Mẫu bài</label>
                    <textarea v-model="form.caption" @input="rollPreview" rows="10"
                        class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm font-mono focus:outline-none focus:border-[var(--color-accent)]"></textarea>
                    <p class="text-xs text-[var(--color-muted)] mt-1">
                        <span class="font-mono">{link}</span> là chỗ đặt link mua — để riêng một dòng.
                        <span class="font-mono">{a|b|c}</span>: mỗi nhóm nhận ngẫu nhiên một lựa chọn, để các nhóm không thấy cùng một câu y hệt.
                    </p>
                    <p v-if="!hasLinkToken" class="text-red-500 text-xs mt-1">Thiếu {link} — bài sẽ không có link mua.</p>
                    <p v-if="form.errors.caption" class="text-red-500 text-xs mt-1">{{ form.errors.caption }}</p>
                </div>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-xs font-semibold text-[var(--color-ink)]">Một nhóm sẽ thấy, ví dụ:</label>
                        <button type="button" @click="rollPreview" class="text-xs font-semibold text-[var(--color-accent)] hover:underline">Xem câu khác</button>
                    </div>
                    <pre class="whitespace-pre-wrap text-sm bg-[var(--color-peach-soft)] rounded-xl p-3 min-h-[12rem]">{{ preview }}</pre>
                    <p class="text-xs text-[var(--color-muted)] mt-1">Link thật được tạo lại lúc bot đăng, để mã còn lượt.</p>
                </div>
            </div>

            <div class="mb-4">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-xs font-semibold text-[var(--color-ink)]">Đăng vào nhóm ({{ form.group_ids.length }}/{{ groups.length }})</p>
                    <button v-if="groups.length" type="button" @click="toggleAll" class="text-xs font-semibold text-[var(--color-accent)] hover:underline">
                        {{ form.group_ids.length === groups.length ? 'Bỏ chọn hết' : 'Chọn hết' }}
                    </button>
                </div>
                <p v-if="!groups.length" class="text-sm text-amber-600">
                    Chưa bật nhóm nào — vào <Link href="/admin/fb-groups" class="font-semibold underline">Nhóm & bot</Link> để lấy nhóm và bật nhóm được đăng.
                </p>
                <div v-else class="grid md:grid-cols-2 gap-1.5 max-h-64 overflow-y-auto">
                    <label v-for="g in groups" :key="g.id" class="flex items-center gap-2 text-sm px-2 py-1.5 rounded-lg hover:bg-[var(--color-peach-soft)]">
                        <input type="checkbox" :value="g.id" v-model="form.group_ids" class="w-4 h-4 accent-[var(--color-accent)]" />
                        <span class="truncate">{{ g.name || g.url }}</span>
                        <span v-if="g.last_posted_at" class="ml-auto text-[10px] text-[var(--color-muted)] whitespace-nowrap">đăng {{ fmt(g.last_posted_at) }}</span>
                    </label>
                </div>
                <p v-if="form.errors.group_ids" class="text-red-500 text-xs mt-1">{{ form.errors.group_ids }}</p>
                <p v-for="(msg, key) in form.errors" :key="key" v-show="key.startsWith('group_ids.') || key.startsWith('fallback') || key === 'shopee_url'" class="text-red-500 text-xs mt-1">{{ msg }}</p>
            </div>

            <button @click="queue" :disabled="form.processing || !form.group_ids.length || !hasLinkToken"
                class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition disabled:opacity-50">
                Xếp {{ form.group_ids.length }} bài vào hàng đợi
            </button>
        </section>

        <!-- Bài đã xếp -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-3">Bài đã xếp</h2>
            <p v-if="errors.post" class="text-red-500 text-xs mb-3">{{ errors.post }}</p>

            <div v-for="deal in deals" :key="deal.id" class="border border-[var(--color-line)] rounded-xl p-4 mb-3">
                <div class="flex gap-3 items-start mb-3">
                    <img v-if="deal.product?.product_image" :src="deal.product.product_image" alt="" class="w-12 h-12 rounded-lg object-cover" />
                    <div class="text-sm min-w-0">
                        <p class="font-semibold text-[var(--color-ink)] truncate">{{ deal.product?.product_name || deal.shopee_url }}</p>
                        <p class="text-xs text-[var(--color-muted)]">Xếp lúc {{ fmt(deal.created_at) }} · {{ deal.clicks }} lượt bấm link</p>
                    </div>
                </div>
                <ul class="space-y-1.5">
                    <li v-for="post in deal.posts" :key="post.id" class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full whitespace-nowrap" :class="statusClass(post.status)">{{ statusLabel[post.status] || post.status }}</span>
                        <a :href="post.group_url" target="_blank" rel="noopener" class="hover:underline truncate max-w-[16rem]">{{ post.group_name || post.group_url }}</a>
                        <span v-if="post.finished_at" class="text-[11px] text-[var(--color-muted)]">{{ fmt(post.finished_at) }}</span>
                        <span v-if="post.link_kind === 'fallback'" class="text-[11px] text-amber-600">dùng link lúc soạn</span>
                        <button v-if="post.status === 'pending'" @click="cancel(post)" class="ml-auto text-xs font-semibold text-red-500 hover:underline">Huỷ</button>
                        <button v-else-if="RETRYABLE.includes(post.status)" @click="retry(post)" class="ml-auto text-xs font-semibold text-[var(--color-accent)] hover:underline">Đăng lại</button>
                        <p v-if="post.error" class="w-full text-xs text-[var(--color-muted)] pl-1">{{ post.error }}</p>
                    </li>
                </ul>
            </div>
            <p v-if="!deals.length" class="text-sm text-center text-[var(--color-muted)] py-8">Chưa có bài nào.</p>
        </section>
    </AdminLayout>
</template>
