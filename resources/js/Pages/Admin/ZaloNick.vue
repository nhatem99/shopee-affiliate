<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { router, useForm, usePage, Head } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const page = usePage()

const props = defineProps({
    status: Object,
    bridgeUrl: String,
    groupIds: Array,
    repliesPer10Minutes: Number,
    mirror: Object,
    recentActivity: Array,
})

const errors = computed(() => page.props.errors || {})
const state = ref(props.status)

// Đang trong lượt đăng nhập QR thì hỏi lại 2 giây/lần để thay mã mới (Zalo đổi mã khoảng mỗi
// phút) và biết ngay lúc quét xong; còn lại 15 giây/lần, đủ để thấy phiên bị đá.
const QR_PENDING = ['generating', 'waiting_scan', 'scanned', 'expired', 'declined']
const pending = computed(() => state.value?.reachable && !state.value.loggedIn && QR_PENDING.includes(state.value.qr?.status))

let timer = null
async function poll() {
    try {
        const { data } = await axios.get('/admin/zalo-nick/status')
        if (data?.loggedIn && state.value?.reachable && !state.value.loggedIn) {
            toast.success('Đã đăng nhập Zalo — bot nhóm chạy lại.')
            // Tự tải danh sách nhóm ngay khi đăng nhập xong để admin chọn nhóm mirror ngay.
            if (!groups.value.length) loadGroups()
        }
        state.value = data
    } catch {
        // Mất mạng/hết phiên admin — giữ nguyên trạng thái cũ, lần sau hỏi lại.
    }
    schedule()
}
function schedule() {
    clearTimeout(timer)
    timer = setTimeout(poll, pending.value ? 2000 : 15000)
}
onMounted(() => {
    schedule()
    // Tải danh sách nhóm ngay khi mở trang nếu bridge đang online và đã đăng nhập.
    if (props.status?.reachable && props.status?.loggedIn) loadGroups()
})
onBeforeUnmount(() => clearTimeout(timer))

const starting = ref(false)
function relogin() {
    if (state.value?.loggedIn && !confirm('Nick đang đăng nhập. Lấy mã QR mới sẽ NGẮT bot nhóm cho tới khi quét xong. Tiếp tục?')) return

    starting.value = true
    router.post('/admin/zalo-nick/relogin', {}, {
        preserveScroll: true,
        onSuccess: () => {
            const msg = page.props.flash?.success
            if (msg) toast.success(msg)
            // Cầu nối cần một hai giây để có mã đầu tiên.
            state.value = { ...state.value, loggedIn: false, qr: { status: 'generating', image: null } }
            clearTimeout(timer)
            timer = setTimeout(poll, 1500)
        },
        onFinish: () => { starting.value = false },
    })
}

// ── Mirror ────────────────────────────────────────────────────────────────────

const mirrorForm = useForm({
    enabled: props.mirror?.enabled ?? false,
    sourceGroupIds: [...(props.mirror?.sourceGroupIds ?? [])],
    targetGroupId: props.mirror?.targetGroupId ?? '',
    postsPerHour: props.mirror?.postsPerHour ?? 10,
    adminsOnly: props.mirror?.adminsOnly ?? true,
})

// Không watch props.mirror: Inertia reload lại page cả khi validation error (back()),
// watcher sẽ overwrite form bằng giá trị cũ đã lưu và admin mất hết chỉnh sửa chưa save.
// Thay vào đó chỉ sync form bên trong onSuccess của saveMirror (xem bên dưới).

// Danh sách nhóm do cầu nối trả về (tên + id) để admin chọn thay vì nhập tay id.
const groups = ref([])
const groupsLoading = ref(false)
const groupsError = ref(null)
const groupSearch = ref('')
const targetSearch = ref('')

async function loadGroups() {
    groupsLoading.value = true
    groupsError.value = null
    try {
        const { data } = await axios.get('/admin/zalo-nick/groups')
        if (data.success) {
            groups.value = data.groups ?? []
            if (!data.groups?.length) {
                groupsError.value = 'Cầu nối trả về danh sách nhóm trống — nick chưa vào nhóm nào.'
            }
        } else {
            groupsError.value = data.error ?? 'Không lấy được danh sách nhóm.'
        }
    } catch {
        groupsError.value = 'Không kết nối được tới cầu nối.'
    } finally {
        groupsLoading.value = false
    }
}

const filteredSourceGroups = computed(() => {
    const q = groupSearch.value.toLowerCase().trim()
    if (!q) return groups.value
    return groups.value.filter(g => g.name.toLowerCase().includes(q) || g.id.includes(q))
})

const filteredTargetGroups = computed(() => {
    const q = targetSearch.value.toLowerCase().trim()
    if (!q) return groups.value
    return groups.value.filter(g => g.name.toLowerCase().includes(q) || g.id.includes(q))
})

// Tên nhóm theo id (dùng để hiện nhãn khi đã có danh sách).
function groupName(id) {
    return groups.value.find(g => g.id === id)?.name ?? null
}

function toggleSource(id) {
    // Gán lại mảng mới để Inertia useForm nhận reactivity đúng.
    const cur = [...mirrorForm.sourceGroupIds]
    const idx = cur.indexOf(id)
    if (idx >= 0) {
        cur.splice(idx, 1)
    } else {
        cur.push(id)
    }
    mirrorForm.sourceGroupIds = cur
}

// Nhập id nhóm nguồn thủ công (khi bridge không tải được danh sách).
const manualSourceId = ref('')
function addManualSource() {
    const id = manualSourceId.value.trim()
    if (!id || !/^\d+$/.test(id)) return
    if (!mirrorForm.sourceGroupIds.includes(id)) {
        mirrorForm.sourceGroupIds = [...mirrorForm.sourceGroupIds, id]
    }
    manualSourceId.value = ''
}
function removeSource(id) {
    mirrorForm.sourceGroupIds = mirrorForm.sourceGroupIds.filter(x => x !== id)
}

function saveMirror() {
    // Đổi '' sang null để Laravel nullable validation không báo lỗi regex.
    mirrorForm
        .transform(d => ({ ...d, targetGroupId: d.targetGroupId || null }))
        .post('/admin/zalo-nick/mirror', {
            preserveScroll: true,
            onSuccess: () => {
                const msg = page.props.flash?.success
                if (msg) toast.success(msg)
                // Đồng bộ form với giá trị đã lưu chỉ khi save thành công. Nếu để watcher trên
                // props.mirror thì mỗi lần validation error trả back() Inertia reload page,
                // watcher fire và overwrite form về giá trị cũ, mất hết chỉnh sửa chưa lưu.
                const v = page.props.mirror
                if (v) {
                    mirrorForm.enabled = v.enabled
                    mirrorForm.sourceGroupIds = [...(v.sourceGroupIds ?? [])]
                    mirrorForm.targetGroupId = v.targetGroupId ?? ''
                    mirrorForm.postsPerHour = v.postsPerHour
                    mirrorForm.adminsOnly = v.adminsOnly
                }
            },
        })
}

// ── Hoạt động gần đây ─────────────────────────────────────────────────────────

const STATUS_LABELS = {
    posted: 'Đã đăng',
    skipped_duplicate: 'Trùng bài',
    skipped_not_admin: 'Không phải admin',
    skipped_unconvertible: 'Link không đổi được',
    skipped_rate_limit: 'Vượt giới hạn',
    skipped_no_shopee: 'Không có link Shopee',
    failed: 'Lỗi',
}

const STATUS_CLASSES = {
    posted: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    skipped_duplicate: 'bg-[var(--color-line)] text-[var(--color-muted)]',
    skipped_not_admin: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
    skipped_unconvertible: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
    skipped_rate_limit: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    skipped_no_shopee: 'bg-[var(--color-line)] text-[var(--color-muted)]',
    failed: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
}

function fmtTime(iso) {
    if (!iso) return '—'
    return new Date(iso).toLocaleString('vi-VN', { dateStyle: 'short', timeStyle: 'short' })
}
</script>

<template>
    <Head title="Admin — Zalo nick nhóm" />
    <AdminLayout>
        <template #title>Zalo nick nhóm</template>

        <!-- 1. Trạng thái -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">1. Trạng thái</h2>
            <p class="text-sm text-[var(--color-muted)] mb-3">
                Nick Zalo cá nhân (nick phụ) tự trả link có mã khi khách dán link Shopee trong nhóm. Nick chạy qua
                cầu nối hermes-zalo-plugin trên server — không phải API chính thức, Zalo có thể khoá nick.
            </p>

            <div v-if="!state?.reachable" class="text-sm">
                <p class="font-semibold text-red-500">Không nối được cầu nối ({{ bridgeUrl }}).</p>
                <p class="text-[var(--color-muted)] mt-1">Cầu nối chưa chạy trên server — cần khởi động nó trước khi quét QR.</p>
                <p v-if="state?.error" class="font-mono text-xs text-[var(--color-muted)] mt-2 break-all">{{ state.error }}</p>
            </div>
            <ul v-else class="text-sm space-y-1.5">
                <li>
                    Đăng nhập:
                    <span v-if="state.loggedIn" class="font-semibold text-green-600">đang đăng nhập</span>
                    <span v-else-if="state.sessionDead" class="font-semibold text-red-500">phiên đã bị đăng xuất{{ state.sessionDeadReason ? ` (${state.sessionDeadReason})` : '' }}</span>
                    <span v-else class="font-semibold text-amber-600">chưa đăng nhập</span>
                    <span v-if="state.ownId" class="font-mono text-xs text-[var(--color-muted)] ml-1">uid {{ state.ownId }}</span>
                </li>
                <li>
                    Lệnh nghe tin <span class="font-mono text-xs">zalo:group-listen</span>:
                    <span v-if="state.listening" class="font-semibold text-green-600">đang chạy</span>
                    <span v-else class="font-semibold text-red-500">chưa chạy — bot nhận tin nhưng không trả lời</span>
                </li>
            </ul>
        </section>

        <!-- 2. Mã QR -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">2. Đăng nhập bằng mã QR</h2>
            <p class="text-sm text-[var(--color-muted)] mb-4">
                Mở app Zalo của <span class="font-semibold text-[var(--color-ink)]">nick phụ</span> → biểu tượng quét QR → quét mã
                bên dưới → xác nhận trên điện thoại. Mã tự đổi khoảng mỗi phút, trang tự cập nhật.
            </p>

            <div v-if="state?.reachable && !state.loggedIn" class="mb-4">
                <img v-if="state.qr?.image" :src="state.qr.image" alt="Mã QR đăng nhập Zalo"
                    class="w-64 h-64 border border-[var(--color-line)] rounded-xl bg-white p-2" />
                <template v-else-if="state.qr?.status === 'scanned'">
                    <p class="text-sm font-semibold text-green-600">
                        ✓ {{ state.qr.scannedBy || 'Đã quét' }} — bấm xác nhận đăng nhập trên điện thoại…
                    </p>
                    <!-- Zalo từ chối ở bước xác nhận (vd lỗi -1005) thì cầu nối KHÔNG đổi trạng thái
                         và không tự tạo mã mới — chữ trên đứng im mãi, phải cho bấm lấy mã lại. -->
                    <p class="text-xs text-[var(--color-muted)] mt-1">
                        Xác nhận rồi mà quá một phút vẫn chưa đăng nhập thì Zalo đã từ chối — bấm "Lấy mã QR mới" để thử lại.
                    </p>
                </template>
                <p v-else-if="pending" class="text-sm text-[var(--color-muted)]">Đang tạo mã QR…</p>
                <p v-else class="text-sm text-[var(--color-muted)]">Chưa có mã — bấm "Lấy mã QR mới".</p>
            </div>

            <p v-if="errors.relogin" class="text-red-500 text-xs mb-3 break-all">{{ errors.relogin }}</p>
            <!-- Không khoá nút khi đang chờ quét: lượt QR có thể đã hỏng mà cầu nối không báo. -->
            <button @click="relogin" :disabled="!state?.reachable || starting"
                class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-5 py-2.5 rounded-xl text-sm transition disabled:opacity-50">
                {{ starting ? 'Đang gửi...' : (state?.loggedIn ? 'Đăng nhập lại / đổi nick' : 'Lấy mã QR mới') }}
            </button>
        </section>

        <!-- 3. Nhóm -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">3. Nhóm được trả lời</h2>
            <p v-if="groupIds?.length" class="text-sm mb-2">
                <span v-for="id in groupIds" :key="id" class="inline-block font-mono text-xs bg-[var(--color-peach-soft)] rounded-lg px-2 py-1 mr-2 mb-1">{{ id }}</span>
            </p>
            <p v-else class="text-sm font-semibold text-amber-600 mb-2">Mọi nhóm nick phụ đang ở.</p>
            <p class="text-xs text-[var(--color-muted)]">
                Tối đa {{ repliesPer10Minutes }} lần trả lời mỗi nhóm trong 10 phút. Đổi danh sách nhóm ở
                <span class="font-mono">ZALO_PERSONAL_GROUP_IDS</span> rồi khởi động lại lệnh nghe tin.
            </p>
        </section>

        <!-- 4. Chuyển tin vào nhóm của tôi (mirror) -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <div class="flex items-start justify-between gap-4 mb-3">
                <div class="min-w-0">
                    <h2 class="font-extrabold text-[var(--color-ink)]">4. Chuyển tin vào nhóm của tôi</h2>
                    <p class="text-sm text-[var(--color-muted)] mt-1">
                        Nick phụ đọc bài từ nhóm nguồn, đổi link Shopee sang link affiliate của mình rồi gửi vào
                        khung chat nhóm đích (tin nhắn thường, không phải bài viết). Tin nào không đổi được toàn bộ
                        link Shopee sẽ bị bỏ qua — không gửi link mang mã của người khác.
                    </p>
                </div>
                <!-- Toggle bật/tắt mirror -->
                <button
                    type="button"
                    role="switch"
                    :aria-checked="mirrorForm.enabled"
                    @click="mirrorForm.enabled = !mirrorForm.enabled"
                    class="relative flex-none w-14 h-8 rounded-full transition-colors duration-200"
                    :class="mirrorForm.enabled ? 'bg-[var(--color-brand-green)]' : 'bg-[var(--color-line)]'"
                >
                    <span
                        class="absolute top-1 left-1 w-6 h-6 rounded-full bg-white shadow-md transition-transform duration-200"
                        :class="mirrorForm.enabled ? 'translate-x-6' : 'translate-x-0'"
                    ></span>
                </button>
            </div>

            <!-- Trạng thái mirror (isActive từ server) -->
            <div class="flex items-center gap-2 text-sm mb-4 pb-4 border-b border-[var(--color-line)]">
                <span class="w-2 h-2 rounded-full flex-none"
                    :class="mirror?.isActive ? 'bg-[var(--color-brand-green)]' : 'bg-[var(--color-muted)]'"></span>
                <span class="text-[var(--color-ink)] font-medium" v-if="mirror?.isActive">
                    Đang hoạt động — đã lưu và đủ điều kiện.
                </span>
                <span class="text-[var(--color-muted)]" v-else-if="!mirror?.enabled">
                    Đang tắt.
                </span>
                <span class="text-amber-600 font-medium" v-else-if="!mirror?.targetGroupId">
                    Chưa chọn nhóm đích.
                </span>
                <span class="text-amber-600 font-medium" v-else-if="!mirror?.sourceGroupIds?.length">
                    Chưa chọn nhóm nguồn.
                </span>
                <span class="text-amber-600 font-medium" v-else>
                    Nhóm đích trùng với một nhóm nguồn — tránh spam vòng lặp.
                </span>
            </div>

            <!-- Nút tải danh sách nhóm -->
            <div class="mb-4">
                <button
                    type="button"
                    @click="loadGroups"
                    :disabled="groupsLoading || !state?.reachable"
                    class="text-sm font-semibold px-4 py-2 rounded-xl border border-[var(--color-line)] bg-[var(--color-surface)] hover:bg-[var(--color-peach-soft)] transition disabled:opacity-50"
                >
                    {{ groupsLoading ? 'Đang tải...' : 'Tải danh sách nhóm' }}
                </button>
                <span v-if="!state?.reachable" class="text-xs text-[var(--color-muted)] ml-2">
                    (cầu nối chưa kết nối — nhập id thủ công bên dưới)
                </span>
                <p v-if="groupsError" class="text-xs text-red-500 mt-1">{{ groupsError }}</p>
                <p v-else-if="groups.length" class="text-xs text-[var(--color-muted)] mt-1">
                    {{ groups.length }} nhóm.
                </p>
            </div>

            <!-- Nhóm đích -->
            <div class="mb-4">
                <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Nhóm đích</label>
                <p class="text-xs text-[var(--color-muted)] mb-2">
                    Nick phụ phải là trưởng/phó nhóm đích nếu nhóm đó chỉ cho admin đăng bài.
                </p>

                <!-- Chọn từ danh sách (nếu đã tải) -->
                <div v-if="groups.length" class="mb-2">
                    <input
                        v-model="targetSearch"
                        type="text"
                        placeholder="Tìm nhóm đích…"
                        class="w-full text-sm px-3 py-2 rounded-xl border border-[var(--color-line)] bg-[var(--color-surface)] text-[var(--color-ink)] placeholder-[var(--color-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] mb-2"
                    />
                    <div class="max-h-36 overflow-y-auto rounded-xl border border-[var(--color-line)] divide-y divide-[var(--color-line)]">
                        <button
                            v-for="g in filteredTargetGroups"
                            :key="g.id"
                            type="button"
                            @click="mirrorForm.targetGroupId = g.id"
                            class="w-full text-left px-3 py-2 text-sm hover:bg-[var(--color-peach-soft)] transition flex items-center gap-2"
                            :class="mirrorForm.targetGroupId === g.id ? 'bg-[var(--color-peach-soft)] font-semibold text-[var(--color-accent)]' : 'text-[var(--color-ink)]'"
                        >
                            <span class="w-3.5 h-3.5 rounded-full flex-none border-2 flex items-center justify-center"
                                :class="mirrorForm.targetGroupId === g.id ? 'border-[var(--color-accent)] bg-[var(--color-accent)]' : 'border-[var(--color-line)]'">
                                <span v-if="mirrorForm.targetGroupId === g.id" class="w-1.5 h-1.5 rounded-full bg-white"></span>
                            </span>
                            <span class="truncate">{{ g.name }}</span>
                            <span class="font-mono text-xs text-[var(--color-muted)] flex-none ml-auto">{{ g.id }}</span>
                        </button>
                        <p v-if="!filteredTargetGroups.length" class="px-3 py-2 text-sm text-[var(--color-muted)]">Không tìm thấy nhóm.</p>
                    </div>
                </div>

                <!-- Ô nhập id thủ công (luôn hiện — bridge không tải được thì vẫn nhập được) -->
                <div class="flex items-center gap-2">
                    <input
                        v-model="mirrorForm.targetGroupId"
                        type="text"
                        inputmode="numeric"
                        pattern="\d*"
                        placeholder="Id nhóm đích (chỉ chữ số)"
                        class="flex-1 text-sm px-3 py-2 rounded-xl border border-[var(--color-line)] bg-[var(--color-surface)] text-[var(--color-ink)] placeholder-[var(--color-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] font-mono"
                    />
                    <button
                        v-if="mirrorForm.targetGroupId"
                        type="button"
                        @click="mirrorForm.targetGroupId = ''"
                        class="text-xs text-[var(--color-muted)] hover:text-red-500 transition px-2"
                    >Xoá</button>
                </div>

                <!-- Hiện tên nhóm nếu đã tải danh sách -->
                <p v-if="mirrorForm.targetGroupId && groupName(mirrorForm.targetGroupId)" class="text-xs text-green-600 mt-1">
                    {{ groupName(mirrorForm.targetGroupId) }}
                </p>
                <p v-if="errors.targetGroupId" class="text-red-500 text-xs mt-1">{{ errors.targetGroupId }}</p>
            </div>

            <!-- Nhóm nguồn -->
            <div class="mb-4">
                <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Nhóm nguồn</label>
                <p class="text-xs text-[var(--color-muted)] mb-2">
                    Nick phụ sẽ theo dõi các nhóm này và chuyển tin sang nhóm đích.
                </p>

                <!-- Tag các id đã chọn -->
                <div v-if="mirrorForm.sourceGroupIds.length" class="flex flex-wrap gap-1.5 mb-2">
                    <span
                        v-for="id in mirrorForm.sourceGroupIds"
                        :key="id"
                        class="inline-flex items-center gap-1 font-mono text-xs bg-[var(--color-peach-soft)] rounded-lg px-2 py-1"
                    >
                        <span>{{ groupName(id) ?? id }}</span>
                        <button type="button" @click="removeSource(id)" class="text-[var(--color-muted)] hover:text-red-500 transition ml-0.5 leading-none">&times;</button>
                    </span>
                </div>
                <p v-else class="text-xs text-[var(--color-muted)] italic mb-2">Chưa chọn nhóm nguồn nào.</p>

                <!-- Danh sách nhóm (nếu đã tải) -->
                <div v-if="groups.length" class="mb-2">
                    <input
                        v-model="groupSearch"
                        type="text"
                        placeholder="Tìm nhóm nguồn…"
                        class="w-full text-sm px-3 py-2 rounded-xl border border-[var(--color-line)] bg-[var(--color-surface)] text-[var(--color-ink)] placeholder-[var(--color-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] mb-2"
                    />
                    <div class="max-h-48 overflow-y-auto rounded-xl border border-[var(--color-line)] divide-y divide-[var(--color-line)]">
                        <label
                            v-for="g in filteredSourceGroups"
                            :key="g.id"
                            class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-[var(--color-peach-soft)] cursor-pointer transition"
                            :class="{ 'opacity-40': mirrorForm.targetGroupId === g.id }"
                        >
                            <input
                                type="checkbox"
                                :value="g.id"
                                :checked="mirrorForm.sourceGroupIds.includes(g.id)"
                                :disabled="mirrorForm.targetGroupId === g.id"
                                @change="toggleSource(g.id)"
                                class="rounded text-[var(--color-accent)] flex-none"
                            />
                            <span class="truncate text-[var(--color-ink)]">{{ g.name }}</span>
                            <span class="font-mono text-xs text-[var(--color-muted)] flex-none ml-auto">{{ g.id }}</span>
                        </label>
                        <p v-if="!filteredSourceGroups.length" class="px-3 py-2 text-sm text-[var(--color-muted)]">Không tìm thấy nhóm.</p>
                    </div>
                </div>

                <!-- Nhập id thủ công -->
                <div class="flex items-center gap-2">
                    <input
                        v-model="manualSourceId"
                        type="text"
                        inputmode="numeric"
                        pattern="\d*"
                        placeholder="Nhập id nhóm nguồn (chỉ chữ số)"
                        @keydown.enter.prevent="addManualSource"
                        class="flex-1 text-sm px-3 py-2 rounded-xl border border-[var(--color-line)] bg-[var(--color-surface)] text-[var(--color-ink)] placeholder-[var(--color-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] font-mono"
                    />
                    <button
                        type="button"
                        @click="addManualSource"
                        :disabled="!manualSourceId.trim() || !/^\d+$/.test(manualSourceId.trim())"
                        class="text-sm font-semibold px-4 py-2 rounded-xl bg-[var(--color-accent)] text-white hover:bg-[var(--color-accent-deep)] transition disabled:opacity-50"
                    >Thêm</button>
                </div>
                <p v-if="errors.sourceGroupIds" class="text-red-500 text-xs mt-1">{{ errors.sourceGroupIds }}</p>
            </div>

            <!-- Tốc độ đăng -->
            <div class="mb-4">
                <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">
                    Tối đa bao nhiêu bài mỗi giờ?
                </label>
                <div class="flex items-center gap-3">
                    <input
                        v-model.number="mirrorForm.postsPerHour"
                        type="number"
                        min="1"
                        max="60"
                        class="w-24 text-sm px-3 py-2 rounded-xl border border-[var(--color-line)] bg-[var(--color-surface)] text-[var(--color-ink)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]"
                    />
                    <span class="text-sm text-[var(--color-muted)]">bài / giờ (1–60)</span>
                </div>
                <p v-if="errors.postsPerHour" class="text-red-500 text-xs mt-1">{{ errors.postsPerHour }}</p>
            </div>

            <!-- Chỉ lấy bài của admin nhóm nguồn -->
            <div class="flex items-start justify-between gap-4 mb-4 pb-4 border-b border-[var(--color-line)]">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-[var(--color-ink)]">Chỉ lấy bài của trưởng/phó nhóm</p>
                    <p class="text-xs text-[var(--color-muted)] mt-0.5">
                        Lọc bỏ tin nhắn thành viên thường (hỏi mã, spam) — chỉ chuyển tin của trưởng/phó nhóm nguồn.
                        Khi bật, bot hỏi cầu nối danh sách admin; nếu cầu nối không trả được thì bài đó bị bỏ qua.
                    </p>
                </div>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="mirrorForm.adminsOnly"
                    @click="mirrorForm.adminsOnly = !mirrorForm.adminsOnly"
                    class="relative flex-none w-14 h-8 rounded-full transition-colors duration-200"
                    :class="mirrorForm.adminsOnly ? 'bg-[var(--color-brand-green)]' : 'bg-[var(--color-line)]'"
                >
                    <span
                        class="absolute top-1 left-1 w-6 h-6 rounded-full bg-white shadow-md transition-transform duration-200"
                        :class="mirrorForm.adminsOnly ? 'translate-x-6' : 'translate-x-0'"
                    ></span>
                </button>
            </div>

            <!-- Ghi chú -->
            <div class="text-xs text-[var(--color-muted)] space-y-1 mb-4">
                <p>
                    Để đăng ảnh kèm bài, cầu nối cần áp patch
                    <span class="font-mono">deploy/zalo-bridge/send-attachment-urls.patch</span> —
                    chưa áp thì bot chỉ gửi được văn bản.
                </p>
                <p>
                    Nick phụ phải là trưởng hoặc phó nhóm đích nếu nhóm đó cài "chỉ admin đăng bài".
                </p>
            </div>

            <!-- Nút Lưu -->
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    @click="saveMirror"
                    :disabled="mirrorForm.processing"
                    class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-5 py-2.5 rounded-xl text-sm transition disabled:opacity-50"
                >
                    {{ mirrorForm.processing ? 'Đang lưu...' : 'Lưu cài đặt mirror' }}
                </button>
                <p v-if="mirrorForm.hasErrors" class="text-xs text-red-500">Có lỗi — kiểm tra lại các trường trên.</p>
            </div>
        </section>

        <!-- 5. Hoạt động gần đây -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-3">5. Hoạt động gần đây</h2>

            <div v-if="!recentActivity?.length" class="text-sm text-[var(--color-muted)] italic">
                Chưa có hoạt động nào được ghi lại.
            </div>

            <div v-else class="space-y-2">
                <div
                    v-for="(item, i) in recentActivity"
                    :key="i"
                    class="rounded-xl border border-[var(--color-line)] p-3 text-sm"
                >
                    <div class="flex flex-wrap items-center gap-2 mb-1">
                        <!-- Trạng thái -->
                        <span
                            class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold"
                            :class="STATUS_CLASSES[item.status] ?? 'bg-[var(--color-line)] text-[var(--color-muted)]'"
                        >
                            {{ STATUS_LABELS[item.status] ?? item.status }}
                        </span>
                        <!-- Thời gian -->
                        <span class="text-xs text-[var(--color-muted)]">{{ fmtTime(item.time) }}</span>
                        <!-- Số link đã đổi (chỉ khi đăng được) -->
                        <span v-if="item.targetLinksCount" class="text-xs text-green-600 font-mono">
                            {{ item.targetLinksCount }} link đổi
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-[var(--color-muted)]">
                        <span><span class="font-medium text-[var(--color-ink)]">Người gửi:</span> {{ item.senderName || '—' }}</span>
                        <span class="font-mono"><span class="font-medium text-[var(--color-ink)] not-italic">Nhóm:</span> {{ item.sourceGroupId }}</span>
                    </div>
                    <p v-if="item.note" class="text-xs text-[var(--color-muted)] mt-0.5 italic">{{ item.note }}</p>
                    <p v-if="item.textPreview" class="text-xs text-[var(--color-ink)] mt-1 line-clamp-2 break-words">{{ item.textPreview }}</p>
                </div>
            </div>
        </section>
    </AdminLayout>
</template>
