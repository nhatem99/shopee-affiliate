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
    // Page đăng bài theo thứ tự — xem FacebookGroupController::index.
    profiles: Array,
    profilesSupported: Boolean,
    profilesMinVersion: String,
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
    timer = setInterval(() => router.reload({ only: ['runner', 'paused', 'pausedReason', 'blocking', 'today', 'syncRequested', 'lastSyncedAt', 'review', 'profiles', 'profilesSupported', 'groups'] }), 30000)
})
onBeforeUnmount(() => clearInterval(timer))

const blockingText = computed(() => {
    const b = props.blocking
    if (!b) return 'Được đăng — bot nhận bài ở lượt hỏi tới.'
    switch (b.reason) {
        case 'paused': return 'Đang tạm dừng.'
        case 'outside_window': return `Ngoài khung giờ đăng — đăng tiếp lúc ${fmt(b.until)}.`
        case 'daily_cap': return `Các page đã đủ bài hôm nay — đăng tiếp lúc ${fmt(b.until)}.`
        case 'no_profile': return 'Chưa bật page nào để đăng.'
        case 'all_blocked': return 'Mọi page đang nghỉ vì Facebook chặn — bấm "Mở lại" khi hết chặn.'
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

// ── Page đăng bài ───────────────────────────────────────────────────────────
const actingProfile = computed(() => props.profiles.find(p => p.acting))
const profileLabels = computed(() => Object.fromEntries(props.profiles.map(p => [p.id, p.label])))

function profileState(p) {
    if (!p.enabled) return ['Tắt', 'text-[var(--color-muted)]']
    if (p.blocked_at) return [`Đang nghỉ từ ${fmt(p.blocked_at)}`, 'font-semibold text-red-500']
    if (p.next) return ['Tới lượt đăng', 'font-semibold text-green-600']
    if (p.used_today >= p.max_per_day) return ['Đủ bài hôm nay', 'text-[var(--color-muted)]']
    return ['Chờ tới lượt', 'text-[var(--color-muted)]']
}

function updateProfile(p, data) {
    router.patch(`/admin/fb-groups/profiles/${p.id}`, data, { preserveScroll: true })
}

function setProfileCap(p, event) {
    const value = Number(event.target.value)
    if (value >= 1 && value !== p.max_per_day) updateProfile(p, { max_per_day: value })
}

function moveProfile(p, direction) {
    router.post(`/admin/fb-groups/profiles/${p.id}/move`, { direction }, { preserveScroll: true })
}

function unblockProfile(p) {
    if (!confirm(`Facebook chặn "${p.label}" lúc ${fmt(p.blocked_at)}. Đã để page nghỉ đủ (1–2 ngày) chưa? Mở lại thì bot đăng bằng page này ngay khi tới lượt.`)) return
    router.post(`/admin/fb-groups/profiles/${p.id}/unblock`, {}, { preserveScroll: true, onSuccess: flashToast })
}

function removeProfile(p) {
    if (!confirm(`Xoá "${p.label}" khỏi danh sách page đăng bài?`)) return
    router.delete(`/admin/fb-groups/profiles/${p.id}`, { preserveScroll: true, onSuccess: flashToast })
}

const profileForm = useForm({ url: '', name: '', max_per_day: 20 })

function addProfile() {
    profileForm.post('/admin/fb-groups/profiles', {
        preserveScroll: true,
        onSuccess: () => { profileForm.reset(); flashToast() },
    })
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
                <li v-if="actingProfile">Đang mở: <span class="font-semibold">{{ actingProfile.label }}</span></li>
                <li v-if="runner.state && runner.state !== 'ok'">
                    Trạng thái bot báo: <span class="font-semibold text-red-500">{{ stateText[runner.state] || runner.state }}</span>
                </li>
                <li>Hôm nay: <span class="font-semibold">{{ today.used }}/{{ cadence.max_per_day }}</span> bài (mọi page) · đang chờ: <span class="font-semibold">{{ today.pending }}</span></li>
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

        <!-- 2. Page đăng bài -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">2. Page đăng bài</h2>
            <p class="text-sm text-[var(--color-muted)] mb-3">
                Bot đăng lần lượt: page trên cùng đăng đủ số bài/ngày của nó rồi mới tới page dưới. Page bị Facebook chặn thì chỉ page đó nghỉ,
                page sau đăng tiếp. Thêm Trang mà nick chính đang quản trị — bot tự bấm "Chuyển ngay" để đăng bằng Trang. Trang phải tự
                tham gia từng nhóm (nhóm phải cho Trang tham gia); thêm page xong bot tự lấy danh sách nhóm của page.
            </p>
            <p v-if="!profilesSupported" class="text-xs text-amber-600 mb-3">
                Bot đang chạy bản {{ runner.version || 'cũ' }} — từ bản {{ profilesMinVersion }} bot mới chuyển page được, bản cũ chỉ đăng bằng nick chính.
                Cập nhật bot (<span class="font-mono">git pull</span> trên điện thoại rồi chạy lại bot).
            </p>

            <div class="overflow-x-auto rounded-xl border border-[var(--color-line)] mb-4">
                <table class="w-full text-sm">
                    <thead class="bg-[var(--color-peach-soft)]">
                        <tr class="text-left text-xs text-[var(--color-muted)]">
                            <th class="px-4 py-2.5 font-semibold">Thứ tự</th>
                            <th class="px-4 py-2.5 font-semibold">Page</th>
                            <th class="px-4 py-2.5 font-semibold">Hôm nay / tối đa</th>
                            <th class="px-4 py-2.5 font-semibold">Nhóm</th>
                            <th class="px-4 py-2.5 font-semibold">Trạng thái</th>
                            <th class="px-4 py-2.5 font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        <tr v-for="(p, i) in profiles" :key="p.id" :class="p.enabled ? '' : 'opacity-70'">
                            <td class="px-4 py-2.5 whitespace-nowrap">
                                <span class="font-semibold mr-1">{{ i + 1 }}</span>
                                <button @click="moveProfile(p, 'up')" :disabled="i === 0" class="px-1.5 text-[var(--color-accent)] disabled:opacity-30" title="Lên trên">↑</button>
                                <button @click="moveProfile(p, 'down')" :disabled="i === profiles.length - 1" class="px-1.5 text-[var(--color-accent)] disabled:opacity-30" title="Xuống dưới">↓</button>
                            </td>
                            <td class="px-4 py-2.5">
                                <a v-if="p.url" :href="p.url" target="_blank" rel="noopener" class="font-semibold text-[var(--color-ink)] hover:underline">{{ p.label }}</a>
                                <span v-else class="font-semibold text-[var(--color-ink)]">{{ p.label }}</span>
                                <span v-if="p.is_primary" class="ml-1 text-[10px] uppercase text-[var(--color-muted)]">nick chính</span>
                                <span v-if="p.acting" class="ml-1 text-[10px] font-semibold px-1.5 py-0.5 rounded bg-green-100 text-green-700">đang mở</span>
                                <p v-if="p.fb_id" class="text-[11px] text-[var(--color-muted)] font-mono">uid {{ p.fb_id }}</p>
                            </td>
                            <td class="px-4 py-2.5 whitespace-nowrap">
                                <span class="font-semibold">{{ p.used_today }}</span> /
                                <input type="number" min="1" max="200" :value="p.max_per_day" @change="setProfileCap(p, $event)"
                                    class="w-16 border border-[var(--color-line)] rounded-lg px-2 py-1 text-sm" />
                            </td>
                            <td class="px-4 py-2.5 text-xs whitespace-nowrap">
                                <span class="font-semibold text-sm">{{ p.groups_count }}</span>
                                <span class="text-[var(--color-muted)]"> · lấy lúc {{ fmt(p.last_synced_at) }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-xs">
                                <span :class="profileState(p)[1]">{{ profileState(p)[0] }}</span>
                                <p v-if="p.blocked_reason" class="text-red-500 mt-0.5 max-w-xs">{{ p.blocked_reason }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap space-x-3">
                                <button v-if="p.blocked_at" @click="unblockProfile(p)" class="text-xs font-semibold text-[var(--color-accent)] hover:underline">Mở lại</button>
                                <button @click="updateProfile(p, { enabled: !p.enabled })" class="text-xs font-semibold text-[var(--color-accent)] hover:underline">{{ p.enabled ? 'Tắt' : 'Bật' }}</button>
                                <button v-if="!p.is_primary" @click="removeProfile(p)" class="text-xs font-semibold text-red-500 hover:underline">Xoá</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <form @submit.prevent="addProfile" class="flex flex-col md:flex-row gap-2">
                <input v-model="profileForm.url" type="text" required placeholder="Link Trang: https://www.facebook.com/profile.php?id=..."
                    class="flex-1 border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-accent)]" />
                <input v-model="profileForm.name" type="text" placeholder="Tên (không bắt buộc)"
                    class="md:w-48 border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-accent)]" />
                <label class="flex items-center gap-2 text-xs font-semibold text-[var(--color-ink)] whitespace-nowrap">Bài/ngày
                    <input v-model.number="profileForm.max_per_day" type="number" min="1" max="200" required
                        class="w-16 border border-[var(--color-line)] rounded-xl px-2 py-2 text-sm" />
                </label>
                <button type="submit" :disabled="profileForm.processing"
                    class="bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] text-[var(--color-ink)] font-semibold px-5 py-2 rounded-xl text-sm transition whitespace-nowrap">
                    + Thêm page
                </button>
            </form>
            <p v-for="(msg, key) in profileForm.errors" :key="key" class="text-red-500 text-xs mt-2">{{ msg }}</p>
            <p v-if="errors.profile_url" class="text-red-500 text-xs mt-2">{{ errors.profile_url }}</p>
        </section>

        <!-- 3. Token -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">3. Token cho bot</h2>
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

        <!-- 4. Nhịp đăng -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">4. Nhịp đăng</h2>
            <p class="text-sm text-[var(--color-muted)] mb-4">
                Giờ Việt Nam. Khoảng nghỉ ngắn thì cả ngày bài dồn hết vào đầu buổi sáng — muốn rải đều thì nới khoảng nghỉ (vd 45–90 phút).
            </p>

            <form @submit.prevent="saveCadence" class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <label class="text-xs font-semibold text-[var(--color-ink)]">Tổng bài/ngày (mọi page)
                    <input v-model.number="cadenceForm.max_per_day" type="number" min="1" max="200" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
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

        <!-- 5. Nhóm -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">5. Nhóm ({{ enabledCount }} đang bật / {{ groups.length }})</h2>
            <p class="text-sm text-[var(--color-muted)] mb-3">
                Bấm "Lấy nhóm đã tham gia" để bot lần lượt chuyển sang từng page, mở trang "Nhóm của bạn" trên Facebook và gửi danh sách về. Nhóm mới vào đều
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
                            <th class="px-4 py-2.5 font-semibold">Page trong nhóm</th>
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
                            <td class="px-4 py-2.5 text-xs">
                                <span v-if="g.profile_ids.length" class="text-[var(--color-muted)]">{{ g.profile_ids.map(id => profileLabels[id]).join(', ') }}</span>
                                <span v-else class="text-red-500">chưa page nào</span>
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
                                <span v-else-if="g.no_profile" class="text-red-500">chưa page nào (đang bật) vào nhóm</span>
                                <span v-else-if="g.queued" class="text-amber-600">có bài đang chờ</span>
                                <span v-else-if="g.ready_at" class="text-[var(--color-muted)]">{{ fmt(g.ready_at) }}</span>
                                <span v-else class="font-semibold text-green-600">được ngay</span>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <button v-if="g.source === 'manual'" @click="remove(g)" class="text-xs font-semibold text-red-500 hover:underline">Xoá</button>
                            </td>
                        </tr>
                        <tr v-if="!shownGroups.length">
                            <td colspan="8" class="px-4 py-8 text-center text-[var(--color-muted)]">Chưa có nhóm nào.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
