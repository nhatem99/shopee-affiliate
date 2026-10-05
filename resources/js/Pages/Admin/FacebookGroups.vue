<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { router, useForm, usePage, Head } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const page = usePage()

const props = defineProps({
    runner: Object,
    tokenTail: String,
    paused: Boolean,
    pausedReason: String,
    cadence: Object,
    blocking: Object,
    today: Object,
    syncRequested: Boolean,
    lastSyncedAt: String,
    // { supported, min_version, requested_at } — kiểm tra duyệt bài cần bot đủ mới.
    review: Object,
    baseUrl: String,
    groups: Array,
})

const errors = computed(() => page.props.errors || {})

function flashToast() {
    const msg = page.props.flash?.success
    if (msg) toast.success(msg)
}

function fmt(iso) {
    return iso ? new Date(iso).toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' }) : '—'
}

// Trang tự tải lại trạng thái mỗi 30 giây — bot hỏi việc 1-2 phút/lần, nhìn trang là biết nó còn chạy.
let timer = null
onMounted(() => {
    timer = setInterval(() => router.reload({ only: ['runner', 'paused', 'pausedReason', 'blocking', 'today', 'syncRequested', 'lastSyncedAt', 'review', 'groups'] }), 30000)
})
onBeforeUnmount(() => clearInterval(timer))

const blockingText = computed(() => {
    const b = props.blocking
    if (!b) return 'Được đăng — bot nhận bài ở lượt hỏi tới.'
    switch (b.reason) {
        case 'paused': return 'Đang tạm dừng.'
        case 'outside_window': return `Ngoài khung giờ đăng — đăng tiếp lúc ${fmt(b.until)}.`
        case 'daily_cap': return `Đã đủ ${props.cadence.max_per_day} bài hôm nay — đăng tiếp lúc ${fmt(b.until)}.`
        case 'gap': return `Đang nghỉ giữa hai bài — bài kế tiếp sau ${fmt(b.until)}.`
        default: return b.reason
    }
})

const stateText = {
    ok: 'bình thường',
    logged_out: 'nick Facebook đã bị đăng xuất',
    checkpoint: 'Facebook bắt xác minh',
    blocked: 'Facebook tạm chặn đăng bài',
}

function setPaused(paused) {
    router.post('/admin/fb-groups/pause', { paused }, { preserveScroll: true, onSuccess: flashToast })
}

function clearWait() {
    router.post('/admin/fb-groups/clear-wait', {}, { preserveScroll: true, onSuccess: flashToast })
}

// ── Token ───────────────────────────────────────────────────────────────────
const newToken = ref(null)
const generating = ref(false)

const configSnippet = computed(() => newToken.value
    ? JSON.stringify({ base_url: props.baseUrl, token: newToken.value }, null, 2)
    : '')

async function regenerateToken() {
    if (props.tokenTail && !confirm('Tạo token mới thì bot đang chạy sẽ bị từ chối cho tới khi bạn dán token mới vào máy chạy bot. Tiếp tục?')) return

    generating.value = true
    try {
        const { data } = await axios.post('/admin/fb-groups/token')
        newToken.value = data.token
    } catch {
        toast.error('Không tạo được token, thử lại.')
    } finally {
        generating.value = false
    }
}

async function copyConfig() {
    try {
        await navigator.clipboard.writeText(configSnippet.value)
        toast.success('Đã copy')
    } catch {
        // Trình duyệt chặn clipboard — admin tự bôi đen copy.
    }
}

// ── Nhịp đăng ───────────────────────────────────────────────────────────────
const cadenceForm = useForm({ ...props.cadence })

function saveCadence() {
    cadenceForm.post('/admin/fb-groups/settings', { preserveScroll: true, onSuccess: flashToast })
}

// ── Nhóm ────────────────────────────────────────────────────────────────────
function requestSync() {
    router.post('/admin/fb-groups/sync', {}, { preserveScroll: true, onSuccess: flashToast })
}

function requestReview() {
    router.post('/admin/fb-groups/review', {}, { preserveScroll: true, onSuccess: flashToast })
}

// Cột "Duyệt": bot xem "Nội dung của bạn" trong nhóm thấy bài mình ở đâu.
function reviewParts(g) {
    return [
        [g.review_published_count, 'lên nhóm', 'font-semibold text-green-600'],
        [g.review_pending_count, 'chờ duyệt', 'text-amber-600'],
        [g.review_rejected_count, 'từ chối/gỡ', 'font-semibold text-red-500'],
        [g.review_missing_count, 'không thấy', 'text-[var(--color-muted)]'],
    ].filter(([n]) => n > 0)
}

const addForm = useForm({ url: '', name: '' })

function addGroup() {
    addForm.post('/admin/fb-groups', {
        preserveScroll: true,
        onSuccess: () => { addForm.reset(); flashToast() },
    })
}

function toggle(group) {
    router.patch(`/admin/fb-groups/${group.id}`, { enabled: !group.enabled }, { preserveScroll: true })
}

function remove(group) {
    if (!confirm(`Xoá nhóm "${group.name || group.url}"?`)) return
    router.delete(`/admin/fb-groups/${group.id}`, { preserveScroll: true, onSuccess: flashToast })
}

const filter = ref('')
const shownGroups = computed(() => {
    const q = filter.value.trim().toLowerCase()
    return q ? props.groups.filter(g => (g.name || '').toLowerCase().includes(q) || g.url.includes(q)) : props.groups
})
const enabledCount = computed(() => props.groups.filter(g => g.enabled).length)
</script>

<template>
    <Head title="Admin — Nhóm FB & bot" />
    <AdminLayout>
        <template #title>Nhóm Facebook & bot đăng bài</template>

        <!-- 1. Bot -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">1. Bot trên máy nhà</h2>
            <p class="text-sm text-[var(--color-muted)] mb-3">
                Bot là một cửa sổ Chromium đã đăng nhập nick Facebook của bạn, chạy trên Mac (thư mục
                <span class="font-mono text-xs">deploy/fb-group-runner</span>). Nó hỏi trang này 1–2 phút/lần, có bài thì đăng.
                Facebook có thể bắt xác minh hoặc khoá nick nếu đăng quá dày.
            </p>

            <div v-if="paused" class="rounded-xl bg-red-50 border border-red-200 p-3 mb-3 text-sm">
                <p class="font-semibold text-red-600">Đang tạm dừng: {{ pausedReason || 'không rõ lý do' }}</p>
                <p class="text-[var(--color-muted)] mt-1">Nếu bot tự dừng vì Facebook: mở cửa sổ Chromium của bot, xử lý xong rồi mới bấm "Chạy tiếp".</p>
            </div>

            <ul class="text-sm space-y-1.5 mb-4">
                <li>
                    Bot:
                    <span v-if="runner.online" class="font-semibold text-green-600">đang chạy</span>
                    <span v-else class="font-semibold text-red-500">không thấy chạy</span>
                    <span class="text-xs text-[var(--color-muted)] ml-1">(lần cuối {{ fmt(runner.last_seen_at) }})</span>
                </li>
                <li v-if="runner.account">Nick: <span class="font-semibold">{{ runner.account }}</span></li>
                <li v-if="runner.state && runner.state !== 'ok'">
                    Trạng thái bot báo: <span class="font-semibold text-red-500">{{ stateText[runner.state] || runner.state }}</span>
                </li>
                <li>Hôm nay: <span class="font-semibold">{{ today.used }}/{{ cadence.max_per_day }}</span> bài · đang chờ: <span class="font-semibold">{{ today.pending }}</span></li>
                <li>Lúc này: <span class="font-semibold">{{ blockingText }}</span></li>
            </ul>

            <div class="flex flex-wrap gap-2">
                <button v-if="paused" @click="setPaused(false)"
                    class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-5 py-2.5 rounded-xl text-sm transition">
                    Chạy tiếp
                </button>
                <button v-else @click="setPaused(true)"
                    class="bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] text-[var(--color-ink)] font-semibold px-5 py-2.5 rounded-xl text-sm transition">
                    Tạm dừng
                </button>
                <button v-if="blocking?.reason === 'gap'" @click="clearWait"
                    class="bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] text-[var(--color-ink)] font-semibold px-5 py-2.5 rounded-xl text-sm transition">
                    Bỏ chờ, đăng bài kế tiếp luôn
                </button>
            </div>
        </section>

        <!-- 2. Token -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">2. Token cho bot</h2>
            <p class="text-sm text-[var(--color-muted)] mb-3">
                Bot dùng token này để nhận bài. Ai có token là khiến được nick Facebook của bạn đăng bài — chỉ dán vào máy chạy bot.
                <span v-if="tokenTail">Token hiện tại kết thúc bằng <span class="font-mono font-semibold text-[var(--color-ink)]">…{{ tokenTail }}</span>.</span>
                <span v-else class="font-semibold text-amber-600">Chưa có token — bot chưa nhận được bài.</span>
            </p>

            <div v-if="newToken" class="mb-3">
                <p class="text-xs font-semibold text-[var(--color-ink)] mb-1">
                    Dán nội dung này vào <span class="font-mono">~/.config/tietkiemvi-fb-runner/config.json</span> trên máy chạy bot (chỉ hiện một lần):
                </p>
                <pre class="font-mono text-xs bg-[var(--color-peach-soft)] rounded-xl p-3 overflow-x-auto select-all">{{ configSnippet }}</pre>
                <button @click="copyConfig" class="mt-2 text-xs font-semibold text-[var(--color-accent)] hover:underline">Copy</button>
            </div>

            <button @click="regenerateToken" :disabled="generating"
                class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-5 py-2.5 rounded-xl text-sm transition disabled:opacity-50">
                {{ generating ? 'Đang tạo...' : (tokenTail ? 'Tạo token mới' : 'Tạo token') }}
            </button>
        </section>

        <!-- 3. Nhịp đăng -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">3. Nhịp đăng</h2>
            <p class="text-sm text-[var(--color-muted)] mb-4">
                Giờ Việt Nam. Khoảng nghỉ ngắn thì cả ngày bài dồn hết vào đầu buổi sáng — muốn rải đều thì nới khoảng nghỉ (vd 45–90 phút).
            </p>

            <form @submit.prevent="saveCadence" class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <label class="text-xs font-semibold text-[var(--color-ink)]">Tối đa bài/ngày
                    <input v-model.number="cadenceForm.max_per_day" type="number" min="1" max="50" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                </label>
                <label class="text-xs font-semibold text-[var(--color-ink)]">Nghỉ tối thiểu (phút)
                    <input v-model.number="cadenceForm.gap_min" type="number" min="1" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                </label>
                <label class="text-xs font-semibold text-[var(--color-ink)]">Nghỉ tối đa (phút)
                    <input v-model.number="cadenceForm.gap_max" type="number" min="1" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                </label>
                <label class="text-xs font-semibold text-[var(--color-ink)]">Mỗi nhóm 1 bài / (giờ)
                    <input v-model.number="cadenceForm.cooldown_hours" type="number" min="1" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                </label>
                <label class="text-xs font-semibold text-[var(--color-ink)]">Đăng từ
                    <input v-model="cadenceForm.window_start" type="time" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                </label>
                <label class="text-xs font-semibold text-[var(--color-ink)]">Đến
                    <input v-model="cadenceForm.window_end" type="time" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                </label>
                <label class="text-xs font-semibold text-[var(--color-ink)]">Bỏ bài chờ quá (giờ)
                    <input v-model.number="cadenceForm.expire_hours" type="number" min="1" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                </label>
                <div class="flex items-end">
                    <button type="submit" :disabled="cadenceForm.processing"
                        class="w-full bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-5 py-2 rounded-xl text-sm transition disabled:opacity-60">
                        Lưu
                    </button>
                </div>
            </form>
            <p v-for="(msg, key) in cadenceForm.errors" :key="key" class="text-red-500 text-xs mt-2">{{ msg }}</p>
        </section>

        <!-- 4. Nhóm -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">4. Nhóm ({{ enabledCount }} đang bật / {{ groups.length }})</h2>
            <p class="text-sm text-[var(--color-muted)] mb-3">
                Bấm "Lấy nhóm đã tham gia" để bot mở trang "Nhóm của bạn" trên Facebook và gửi danh sách về. Nhóm mới vào đều
                <span class="font-semibold">tắt</span> — tự bật nhóm nào được đăng. Nên đọc nội quy nhóm trước: nhiều nhóm cấm link hoặc cấm thành viên mới đăng bài.
            </p>

            <div class="flex flex-wrap items-center gap-3 mb-4">
                <button @click="requestSync" :disabled="syncRequested"
                    class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-5 py-2.5 rounded-xl text-sm transition disabled:opacity-50">
                    {{ syncRequested ? 'Đang chờ bot lấy nhóm…' : 'Lấy nhóm đã tham gia' }}
                </button>
                <span class="text-xs text-[var(--color-muted)]">Lần lấy gần nhất: {{ fmt(lastSyncedAt) }}</span>
                <button @click="requestReview" :disabled="!review.supported"
                    class="bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] text-[var(--color-ink)] font-semibold px-5 py-2.5 rounded-xl text-sm transition disabled:opacity-50">
                    Kiểm tra duyệt bài ngay
                </button>
                <span v-if="review.requested_at" class="text-xs text-[var(--color-muted)]">Yêu cầu lúc {{ fmt(review.requested_at) }}</span>
            </div>
            <p v-if="!review.supported" class="text-xs text-amber-600 -mt-2 mb-4">
                Bot đang chạy bản {{ runner.version || 'cũ' }} — từ bản {{ review.min_version }} bot mới tự kiểm tra bài có được duyệt không.
                Cập nhật bot (<span class="font-mono">git pull</span> trên điện thoại rồi chạy lại bot).
            </p>

            <form @submit.prevent="addGroup" class="flex flex-col md:flex-row gap-2 mb-4">
                <input v-model="addForm.url" type="text" required placeholder="Link nhóm: https://www.facebook.com/groups/..."
                    class="flex-1 border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-accent)]" />
                <input v-model="addForm.name" type="text" placeholder="Tên nhóm (không bắt buộc)"
                    class="md:w-64 border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-accent)]" />
                <button type="submit" :disabled="addForm.processing"
                    class="bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] text-[var(--color-ink)] font-semibold px-5 py-2 rounded-xl text-sm transition whitespace-nowrap">
                    + Thêm tay
                </button>
            </form>
            <p v-if="addForm.errors.url" class="text-red-500 text-xs -mt-2 mb-3">{{ addForm.errors.url }}</p>
            <p v-if="errors.group" class="text-red-500 text-xs mb-3">{{ errors.group }}</p>

            <input v-if="groups.length > 10" v-model="filter" type="search" placeholder="Lọc theo tên…"
                class="w-full md:w-72 border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm mb-3" />

            <div class="overflow-x-auto rounded-xl border border-[var(--color-line)]">
                <table class="w-full text-sm">
                    <thead class="bg-[var(--color-peach-soft)]">
                        <tr class="text-left text-xs text-[var(--color-muted)]">
                            <th class="px-4 py-2.5 font-semibold">Đăng</th>
                            <th class="px-4 py-2.5 font-semibold">Nhóm</th>
                            <th class="px-4 py-2.5 font-semibold">Đã đăng</th>
                            <th class="px-4 py-2.5 font-semibold">Duyệt</th>
                            <th class="px-4 py-2.5 font-semibold">Lần gần nhất</th>
                            <th class="px-4 py-2.5 font-semibold">Đăng tiếp</th>
                            <th class="px-4 py-2.5 font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        <tr v-for="g in shownGroups" :key="g.id" :class="g.enabled ? '' : 'opacity-70'">
                            <td class="px-4 py-2.5">
                                <input type="checkbox" :checked="g.enabled" @change="toggle(g)" class="w-4 h-4 accent-[var(--color-accent)]" />
                            </td>
                            <td class="px-4 py-2.5">
                                <a :href="g.url" target="_blank" rel="noopener" class="font-semibold text-[var(--color-ink)] hover:underline">{{ g.name || g.fb_group_key }}</a>
                                <span v-if="g.source === 'manual'" class="ml-1 text-[10px] uppercase text-[var(--color-muted)]">thêm tay</span>
                                <p v-if="g.disabled_reason" class="text-xs text-red-500 mt-0.5">Bot tự tắt: {{ g.disabled_reason }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-[var(--color-muted)]">{{ g.posted_count }}</td>
                            <td class="px-4 py-2.5 text-xs whitespace-nowrap" :title="g.last_checked_at ? `Bot kiểm tra lúc ${fmt(g.last_checked_at)}` : ''">
                                <template v-if="reviewParts(g).length">
                                    <span v-for="([n, label, cls], i) in reviewParts(g)" :key="label" :class="cls">{{ i ? ' · ' : '' }}{{ n }} {{ label }}</span>
                                </template>
                                <span v-else class="text-[var(--color-muted)]">chưa kiểm tra</span>
                            </td>
                            <td class="px-4 py-2.5 text-xs text-[var(--color-muted)]">{{ fmt(g.last_posted_at) }}</td>
                            <td class="px-4 py-2.5 text-xs whitespace-nowrap">
                                <span v-if="!g.enabled" class="text-[var(--color-muted)]">—</span>
                                <span v-else-if="g.queued" class="text-amber-600">có bài đang chờ</span>
                                <span v-else-if="g.ready_at" class="text-[var(--color-muted)]">{{ fmt(g.ready_at) }}</span>
                                <span v-else class="font-semibold text-green-600">được ngay</span>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <button v-if="g.source === 'manual'" @click="remove(g)" class="text-xs font-semibold text-red-500 hover:underline">Xoá</button>
                            </td>
                        </tr>
                        <tr v-if="!shownGroups.length">
                            <td colspan="7" class="px-4 py-8 text-center text-[var(--color-muted)]">Chưa có nhóm nào.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
