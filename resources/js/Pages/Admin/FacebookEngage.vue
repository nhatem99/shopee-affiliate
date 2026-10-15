<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { router, useForm, usePage, Head, Link } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const page = usePage()

const props = defineProps({
    config: Object,
    templates: String,
    stoppedReason: String,
    blocking: Object,
    nextAt: String,
    today: Number,
    // { version, supported, min_version } — bot từ min_version mới biết bình luận dạo.
    runner: Object,
    profiles: Array,
    groups: Array,
    history: Array,
})

function flashToast() {
    const msg = page.props.flash?.success
    if (msg) toast.success(msg)
}

function fmt(iso) {
    return iso ? new Date(iso).toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' }) : '—'
}

// Tự tải lại trạng thái và lịch sử mỗi 30 giây, như /admin/fb-groups.
let timer = null
onMounted(() => {
    timer = setInterval(() => router.reload({ only: ['config', 'stoppedReason', 'blocking', 'nextAt', 'today', 'runner', 'groups', 'history'] }), 30000)
})
onBeforeUnmount(() => clearInterval(timer))

const blockingText = computed(() => {
    const b = props.blocking
    if (!b) return 'Tới lượt — bot bình luận ở lượt hỏi việc tới.'
    switch (b.reason) {
        case 'disabled': return 'Đang tắt.'
        case 'old_runner': return `Bot đang chạy bản ${props.runner.version || 'cũ'} — cần bản ${props.runner.min_version} trở lên.`
        case 'no_profile': return 'Chưa chọn page để bình luận.'
        case 'profile_blocked': return 'Page bình luận đang nghỉ vì Facebook chặn — mở lại ở /admin/fb-groups.'
        case 'profile_missing': return 'Page bình luận không còn trong danh sách page của nick trên Facebook — chọn page khác.'
        case 'no_templates': return 'Chưa có câu mẫu nào.'
        case 'outside_window': return `Ngoài khung giờ — bình luận tiếp lúc ${fmt(b.until)}.`
        case 'daily_cap': return `Đủ bình luận hôm nay — tiếp lúc ${fmt(b.until)}.`
        case 'gap': return `Đang nghỉ giữa hai bình luận — lượt tới sau ${fmt(b.until)}.`
        case 'no_group': return 'Chưa bật nhóm nào mà page bình luận đã vào.'
        case 'group_gap': return `Các nhóm vừa được ghé — nhóm kế tiếp tới lượt lúc ${fmt(b.until)}.`
        default: return b.reason
    }
})

function toggle(enabled) {
    router.post('/admin/fb-engage/toggle', { enabled }, { preserveScroll: true, onSuccess: flashToast })
}

function clearWait() {
    router.post('/admin/fb-engage/clear-wait', {}, { preserveScroll: true, onSuccess: flashToast })
}

// ── Cài đặt ─────────────────────────────────────────────────────────────────
const form = useForm({
    profile_id: props.config.profile_id,
    max_per_day: props.config.max_per_day,
    gap_min: props.config.gap_min,
    gap_max: props.config.gap_max,
    group_gap_minutes: props.config.group_gap_minutes,
    window_start: props.config.window_start,
    window_end: props.config.window_end,
    templates: props.templates,
})

const templateCount = computed(() => (form.templates || '').split('\n').filter(line => line.trim()).length)

function save() {
    form.post('/admin/fb-engage/settings', { preserveScroll: true, onSuccess: flashToast })
}

// ── Nhóm ────────────────────────────────────────────────────────────────────
const filter = ref('')
const onlyInProfile = ref(true)
const shownGroups = computed(() => {
    const q = filter.value.trim().toLowerCase()
    return props.groups.filter(g => (!onlyInProfile.value || g.in_profile || g.engage_enabled)
        && (!q || g.name.toLowerCase().includes(q) || g.url.includes(q)))
})
const enabledCount = computed(() => props.groups.filter(g => g.engage_enabled).length)

function setGroups(ids, engageEnabled) {
    if (!ids.length) return
    router.post('/admin/fb-engage/groups', { ids, engage_enabled: engageEnabled }, { preserveScroll: true })
}

function setShown(engageEnabled) {
    const ids = shownGroups.value.filter(g => g.engage_enabled !== engageEnabled).map(g => g.id)
    if (!ids.length) return
    if (!confirm(`${engageEnabled ? 'Bật' : 'Tắt'} bình luận dạo cho ${ids.length} nhóm đang hiện?`)) return
    setGroups(ids, engageEnabled)
}

// ── Lịch sử ─────────────────────────────────────────────────────────────────
const statusText = {
    claimed: ['Đang làm', 'text-amber-600'],
    commented: ['Đã bình luận', 'font-semibold text-green-600'],
    ambiguous: ['Không rõ', 'text-amber-600'],
    unknown: ['Bot không báo', 'text-amber-600'],
    no_post: ['Không có bài mới', 'text-[var(--color-muted)]'],
    not_allowed: ['Nhóm không cho', 'text-red-500'],
    failed: ['Hỏng', 'text-red-500'],
    blocked: ['Facebook chặn', 'font-semibold text-red-500'],
    checkpoint: ['Bắt xác minh', 'font-semibold text-red-500'],
}
</script>

<template>
    <Head title="Admin — Bình luận dạo" />
    <AdminLayout>
        <template #title>Bình luận dạo nhóm follow chéo</template>

        <!-- 1. Trạng thái -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">1. Trạng thái</h2>
            <p class="text-sm text-[var(--color-muted)] mb-3">
                Bot đăng nhóm (xem <Link href="/admin/fb-groups" class="underline">Nhóm & bot đăng bài</Link>) lúc rảnh sẽ chuyển sang page đã chọn, mở bảng tin
                một nhóm, chọn bài mới nhất chưa bình luận (bỏ bài của chính mình) và bình luận một câu mẫu. Đăng deal vẫn được ưu tiên trước.
                Facebook dễ chặn bình luận giống nhau, đều tay — nên để nhiều câu mẫu khác nhau, nghỉ đủ lâu và ít bình luận mỗi ngày.
            </p>

            <div v-if="stoppedReason" class="rounded-xl bg-red-50 border border-red-200 p-3 mb-3 text-sm">
                <p class="font-semibold text-red-600">Bot tự tắt: {{ stoppedReason }}</p>
                <p class="text-[var(--color-muted)] mt-1">Để page nghỉ 1–2 ngày, giảm số bình luận/ngày rồi mới bật lại.</p>
            </div>
            <p v-if="!runner.supported" class="text-xs text-amber-600 mb-3">
                Bot đang chạy bản {{ runner.version || 'cũ' }} — từ bản {{ runner.min_version }} bot mới biết bình luận dạo.
                Cập nhật bot (<span class="font-mono">git pull</span> trên điện thoại rồi chạy lại bot).
            </p>

            <ul class="text-sm space-y-1.5 mb-4">
                <li>
                    Bình luận dạo:
                    <span v-if="config.enabled" class="font-semibold text-green-600">đang bật</span>
                    <span v-else class="font-semibold text-[var(--color-muted)]">đang tắt</span>
                </li>
                <li>Hôm nay: <span class="font-semibold">{{ today }}/{{ config.max_per_day }}</span> bình luận</li>
                <li>Lúc này: <span class="font-semibold">{{ blockingText }}</span></li>
            </ul>

            <div class="flex flex-wrap gap-2">
                <button v-if="!config.enabled" @click="toggle(true)"
                    class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-5 py-2.5 rounded-xl text-sm transition">
                    Bật bình luận dạo
                </button>
                <button v-else @click="toggle(false)"
                    class="bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] text-[var(--color-ink)] font-semibold px-5 py-2.5 rounded-xl text-sm transition">
                    Tắt
                </button>
                <button v-if="blocking?.reason === 'gap'" @click="clearWait"
                    class="bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] text-[var(--color-ink)] font-semibold px-5 py-2.5 rounded-xl text-sm transition">
                    Bỏ chờ, bình luận luôn
                </button>
            </div>
        </section>

        <!-- 2. Cài đặt -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">2. Page, câu mẫu và thời gian</h2>
            <p class="text-sm text-[var(--color-muted)] mb-4">Giờ Việt Nam. Mỗi lượt bot bình luận 1 bài rồi nghỉ ngẫu nhiên trong khoảng tối thiểu–tối đa.</p>

            <form @submit.prevent="save" class="space-y-4">
                <label class="block text-xs font-semibold text-[var(--color-ink)]">Bình luận bằng page
                    <select v-model="form.profile_id" class="mt-1 w-full md:w-80 border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm">
                        <option :value="null">— Chọn page —</option>
                        <option v-for="p in profiles" :key="p.id" :value="p.id">{{ p.label }}{{ p.is_primary ? ' (nick chính)' : '' }}{{ p.blocked_reason ? ' — đang nghỉ' : '' }}</option>
                    </select>
                    <span class="block font-normal text-[var(--color-muted)] mt-1">
                        Page phải đã vào nhóm. Thêm page và lấy nhóm của page ở <Link href="/admin/fb-groups" class="underline">Nhóm & bot đăng bài</Link>.
                    </span>
                </label>

                <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                    <label class="text-xs font-semibold text-[var(--color-ink)]">Bình luận/ngày
                        <input v-model.number="form.max_per_day" type="number" min="1" max="300" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                    </label>
                    <label class="text-xs font-semibold text-[var(--color-ink)]">Nghỉ tối thiểu (phút)
                        <input v-model.number="form.gap_min" type="number" min="1" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                    </label>
                    <label class="text-xs font-semibold text-[var(--color-ink)]">Nghỉ tối đa (phút)
                        <input v-model.number="form.gap_max" type="number" min="1" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                    </label>
                    <label class="text-xs font-semibold text-[var(--color-ink)]">Mỗi nhóm cách (phút)
                        <input v-model.number="form.group_gap_minutes" type="number" min="0" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                    </label>
                    <label class="text-xs font-semibold text-[var(--color-ink)]">Từ
                        <input v-model="form.window_start" type="time" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                    </label>
                    <label class="text-xs font-semibold text-[var(--color-ink)]">Đến
                        <input v-model="form.window_end" type="time" class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                    </label>
                </div>

                <label class="block text-xs font-semibold text-[var(--color-ink)]">Câu mẫu ({{ templateCount }} câu)
                    <textarea v-model="form.templates" rows="8"
                        placeholder="Follow chéo nha {ten} {😊|🥰|🤝}&#10;{Đã ghé|Ghé} {thăm|qua} rồi nè, {ủng hộ|tương tác} lại mình {nhé|nha|với} {🙏|❤️|}&#10;{Hay quá|Tuyệt vời|Đỉnh} {bạn ơi|}, {kết bạn|giao lưu} {nhé|nha}"
                        class="mt-1 w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm font-mono focus:outline-none focus:border-[var(--color-accent)]"></textarea>
                    <span class="block font-normal text-[var(--color-muted)] mt-1">
                        Mỗi dòng một câu, bot bốc ngẫu nhiên. <span class="font-mono">{a|b|c}</span> — mỗi lần lấy một lựa chọn (để trống như <span class="font-mono">{🙏|}</span> là có khi không có).
                        <span class="font-mono">{ten}</span> — tên người đăng bài (bot không đọc được tên thì bỏ). Càng nhiều câu khác nhau càng ít bị coi là spam.
                    </span>
                </label>

                <button type="submit" :disabled="form.processing"
                    class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition disabled:opacity-60">
                    Lưu
                </button>
                <p v-for="(msg, key) in form.errors" :key="key" class="text-red-500 text-xs">{{ msg }}</p>
            </form>
        </section>

        <!-- 3. Nhóm -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">3. Nhóm ({{ enabledCount }} đang bật / {{ groups.length }})</h2>
            <p class="text-sm text-[var(--color-muted)] mb-3">
                Tích nhóm follow chéo cần bình luận. Riêng với việc đăng deal: nhóm tích ở đây không bị đăng deal, và ngược lại.
                Bot ghé lần lượt nhóm lâu chưa ghé nhất. Nhóm không cho page bình luận thì bot tự bỏ tích và ghi lý do.
            </p>

            <div class="flex flex-wrap items-center gap-3 mb-3">
                <input v-model="filter" type="search" placeholder="Lọc theo tên… (vd: follow)"
                    class="w-full md:w-72 border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm" />
                <label class="flex items-center gap-2 text-xs text-[var(--color-ink)]">
                    <input v-model="onlyInProfile" type="checkbox" class="w-4 h-4 accent-[var(--color-accent)]" />
                    Chỉ nhóm page bình luận đã vào
                </label>
                <button @click="setShown(true)" class="text-xs font-semibold text-[var(--color-accent)] hover:underline">Bật hết nhóm đang hiện</button>
                <button @click="setShown(false)" class="text-xs font-semibold text-red-500 hover:underline">Tắt hết nhóm đang hiện</button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-[var(--color-line)]">
                <table class="w-full text-sm">
                    <thead class="bg-[var(--color-peach-soft)]">
                        <tr class="text-left text-xs text-[var(--color-muted)]">
                            <th class="px-4 py-2.5 font-semibold">Bình luận</th>
                            <th class="px-4 py-2.5 font-semibold">Nhóm</th>
                            <th class="px-4 py-2.5 font-semibold">Đã bình luận</th>
                            <th class="px-4 py-2.5 font-semibold">Ghé lần cuối</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        <tr v-for="g in shownGroups" :key="g.id" :class="g.engage_enabled ? '' : 'opacity-70'">
                            <td class="px-4 py-2.5">
                                <input type="checkbox" :checked="g.engage_enabled" @change="setGroups([g.id], !g.engage_enabled)" class="w-4 h-4 accent-[var(--color-accent)]" />
                            </td>
                            <td class="px-4 py-2.5">
                                <a :href="g.url" target="_blank" rel="noopener" class="font-semibold text-[var(--color-ink)] hover:underline">{{ g.name }}</a>
                                <span v-if="g.deal_enabled" class="ml-1 text-[10px] uppercase text-[var(--color-muted)]">đang đăng deal</span>
                                <p v-if="!g.in_profile" class="text-xs text-amber-600 mt-0.5">Page bình luận chưa vào nhóm này — bot bỏ qua.</p>
                                <p v-if="g.engage_disabled_reason" class="text-xs text-red-500 mt-0.5">Bot tự tắt: {{ g.engage_disabled_reason }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-[var(--color-muted)]">{{ g.commented_count }}</td>
                            <td class="px-4 py-2.5 text-xs text-[var(--color-muted)]">{{ fmt(g.last_engaged_at) }}</td>
                        </tr>
                        <tr v-if="!shownGroups.length">
                            <td colspan="4" class="px-4 py-8 text-center text-[var(--color-muted)]">
                                Không có nhóm nào. Bấm "Lấy nhóm đã tham gia" ở <Link href="/admin/fb-groups" class="underline">Nhóm & bot đăng bài</Link> để bot lấy nhóm của page.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- 4. Lịch sử -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-3">4. Bình luận gần đây</h2>
            <div class="overflow-x-auto rounded-xl border border-[var(--color-line)]">
                <table class="w-full text-sm">
                    <thead class="bg-[var(--color-peach-soft)]">
                        <tr class="text-left text-xs text-[var(--color-muted)]">
                            <th class="px-4 py-2.5 font-semibold">Lúc</th>
                            <th class="px-4 py-2.5 font-semibold">Nhóm</th>
                            <th class="px-4 py-2.5 font-semibold">Bình luận</th>
                            <th class="px-4 py-2.5 font-semibold">Kết quả</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        <tr v-for="c in history" :key="c.id">
                            <td class="px-4 py-2.5 text-xs text-[var(--color-muted)] whitespace-nowrap">{{ fmt(c.claimed_at) }}</td>
                            <td class="px-4 py-2.5 text-xs">
                                <span class="font-semibold text-[var(--color-ink)]">{{ c.group || '—' }}</span>
                                <p v-if="c.profile" class="text-[var(--color-muted)]">bằng {{ c.profile }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-xs max-w-md">
                                <p class="whitespace-pre-line">{{ c.comment }}</p>
                                <p v-if="c.author || c.post_url" class="text-[var(--color-muted)] mt-0.5">
                                    <span v-if="c.author">bài của {{ c.author }}</span>
                                    <a v-if="c.post_url" :href="c.post_url" target="_blank" rel="noopener" class="ml-1 underline">xem bài</a>
                                </p>
                            </td>
                            <td class="px-4 py-2.5 text-xs">
                                <span :class="(statusText[c.status] || [])[1]">{{ (statusText[c.status] || [c.status])[0] }}</span>
                                <p v-if="c.error" class="text-[var(--color-muted)] mt-0.5 max-w-xs">{{ c.error }}</p>
                            </td>
                        </tr>
                        <tr v-if="!history.length">
                            <td colspan="4" class="px-4 py-8 text-center text-[var(--color-muted)]">Chưa có lượt bình luận nào.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
