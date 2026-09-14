<script setup>
import { router, Head } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const props = defineProps({
    status: Object,
    tasks: Array,
    runs: Array,
    reelSlots: Array,
})

const running = ref(null)
const refreshing = ref(false)

// Dòng lịch sử nào đang mở output.
const expanded = ref(new Set())
function toggle(id) {
    const next = new Set(expanded.value)
    next.has(id) ? next.delete(id) : next.add(id)
    expanded.value = next
}

function runNow(command) {
    if (running.value) return
    running.value = command
    router.post('/admin/scheduler/run', { command }, {
        preserveScroll: true,
        onSuccess: (page) => {
            const f = page.props.flash || {}
            f.error ? toast.error(f.error) : toast.success(f.success || 'Đã chạy xong.')
            // Mở luôn dòng vừa chạy — đó là thứ admin muốn nhìn ngay.
            const latest = page.props.runs?.[0]
            if (latest) expanded.value = new Set([latest.id, ...expanded.value])
        },
        onError: () => toast.error('Không chạy được, thử lại.'),
        onFinish: () => { running.value = null },
    })
}

function refresh() {
    router.reload({
        onStart: () => { refreshing.value = true },
        onFinish: () => { refreshing.value = false },
    })
}

const STATE = {
    running: { dot: 'bg-emerald-500', box: 'bg-emerald-50 border-emerald-200 text-emerald-800', label: 'Scheduler đang chạy' },
    stale: { dot: 'bg-amber-500', box: 'bg-amber-50 border-amber-200 text-amber-800', label: 'Scheduler đã ngừng' },
    never: { dot: 'bg-red-500', box: 'bg-red-50 border-red-200 text-red-800', label: 'Scheduler chưa từng chạy' },
}
const state = computed(() => STATE[props.status.state] || STATE.never)

const RUN_STYLE = {
    ok: 'bg-emerald-100 text-emerald-700 border-emerald-300',
    failed: 'bg-red-100 text-red-700 border-red-300',
    running: 'bg-sky-100 text-sky-700 border-sky-300',
}
const RUN_LABEL = { ok: 'Thành công', failed: 'Lỗi', running: 'Đang chạy' }

// "x phút trước" do server tính (SchedulerService::ago) — máy admin có thể đặt múi giờ khác app.
function duration(ms) {
    if (ms == null) return ''
    return ms < 1000 ? `${ms}ms` : `${(ms / 1000).toFixed(1)}s`
}

function who(triggeredBy) {
    return triggeredBy === 'schedule' ? 'cron' : `admin #${triggeredBy.split(':')[1]}`
}

const copied = ref(false)
async function copyCron() {
    try {
        await navigator.clipboard.writeText(props.status.cron_line)
        copied.value = true
        setTimeout(() => { copied.value = false }, 2000)
    } catch (e) {
        toast.error('Không copy được, chọn và copy thủ công.')
    }
}
</script>

<template>
    <Head title="Admin — Lịch chạy" />
    <AdminLayout>
        <template #title>Lịch chạy (scheduler)</template>

        <p class="text-sm text-[var(--color-muted)] mb-4">
            Các job tự chạy theo lịch trong <span class="font-mono">routes/console.php</span>. Cần cron
            <span class="font-mono">schedule:run</span> trên server gọi mỗi phút; trang này cho biết cron có chạy thật không.
        </p>

        <!-- Đèn trạng thái -->
        <div class="rounded-2xl border p-4 mb-6 flex flex-col md:flex-row md:items-center gap-3" :class="state.box">
            <div class="flex items-center gap-3 flex-1 min-w-0">
                <span class="w-3 h-3 rounded-full flex-none" :class="[state.dot, status.state === 'running' ? 'animate-pulse' : '']"></span>
                <div class="min-w-0">
                    <p class="font-bold">{{ state.label }}</p>
                    <p class="text-xs opacity-80">
                        <template v-if="status.last_heartbeat">Nhịp cuối: {{ status.last_heartbeat }} ({{ status.last_heartbeat_human }})</template>
                        <template v-else>Chưa nhận được nhịp tim nào — server chưa có cron, hoặc cron trỏ sai thư mục.</template>
                    </p>
                </div>
            </div>
            <button @click="refresh" :disabled="refreshing"
                class="text-sm font-semibold px-4 py-2 rounded-xl border border-current/30 hover:bg-white/50 transition disabled:opacity-60 whitespace-nowrap">
                {{ refreshing ? 'Đang tải...' : '↻ Làm mới' }}
            </button>
        </div>

        <!-- Hướng dẫn thêm cron: chỉ hiện khi có vấn đề -->
        <div v-if="status.state !== 'running'" class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4 mb-6">
            <p class="text-sm font-semibold text-[var(--color-ink)] mb-2">Cách sửa: SSH vào server, chạy <span class="font-mono">crontab -e</span> và thêm dòng:</p>
            <div class="flex flex-col md:flex-row gap-2">
                <code class="flex-1 min-w-0 block bg-slate-900 text-emerald-300 text-xs rounded-xl px-3 py-2.5 overflow-x-auto whitespace-nowrap">{{ status.cron_line }}</code>
                <button @click="copyCron" class="text-sm font-semibold px-4 py-2 rounded-xl bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white transition whitespace-nowrap">
                    {{ copied ? '✓ Đã copy' : 'Copy' }}
                </button>
            </div>
            <p class="text-xs text-[var(--color-muted)] mt-2">Sau khi lưu, đợi 1–2 phút rồi bấm Làm mới — đèn sẽ chuyển xanh.</p>
        </div>

        <!-- Danh sách job -->
        <h2 class="text-base font-bold text-[var(--color-ink)] mb-3">Job theo lịch</h2>
        <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] overflow-hidden mb-6">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-[var(--color-bg)] text-xs text-[var(--color-muted)] uppercase">
                        <tr>
                            <th class="text-left px-4 py-2.5">Job</th>
                            <th class="text-left px-4 py-2.5">Lịch</th>
                            <th class="text-left px-4 py-2.5">Lần cuối</th>
                            <th class="text-left px-4 py-2.5">Lần tới</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in tasks" :key="t.command" class="border-t border-[var(--color-line)]">
                            <td class="px-4 py-3">
                                <p class="font-semibold text-[var(--color-ink)]">{{ t.description }}</p>
                                <p class="font-mono text-xs text-[var(--color-muted)]">{{ t.command }}</p>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs whitespace-nowrap">{{ t.expression }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <template v-if="t.last_run">
                                    <span class="text-xs font-bold px-2 py-0.5 rounded-full border" :class="RUN_STYLE[t.last_run.status]">{{ RUN_LABEL[t.last_run.status] }}</span>
                                    <span class="text-xs text-[var(--color-muted)] ml-1.5">{{ t.last_run.started_human }}</span>
                                </template>
                                <span v-else class="text-xs text-[var(--color-muted)]">Chưa chạy lần nào</span>
                            </td>
                            <td class="px-4 py-3 text-xs whitespace-nowrap">{{ t.next_run }}</td>
                            <td class="px-4 py-3 text-right">
                                <button @click="runNow(t.command)" :disabled="running !== null"
                                    class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white transition disabled:opacity-60 whitespace-nowrap">
                                    {{ running === t.command ? 'Đang chạy...' : '▶ Chạy ngay' }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!tasks.length"><td colspan="5" class="px-4 py-6 text-center text-[var(--color-muted)]">Chưa có job nào trong lịch.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Slot reel -->
        <h2 class="text-base font-bold text-[var(--color-ink)] mb-1">Slot reel Facebook</h2>
        <p class="text-xs text-[var(--color-muted)] mb-3">Reel nào đang hiện link sản phẩm nào — job <span class="font-mono">facebook:sync-reels</span> đọc caption thật 5 phút/lần để đối soát.</p>
        <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] overflow-hidden mb-6">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-[var(--color-bg)] text-xs text-[var(--color-muted)] uppercase">
                        <tr>
                            <th class="text-left px-4 py-2.5">Reel</th>
                            <th class="text-left px-4 py-2.5">Đang hiện</th>
                            <th class="text-left px-4 py-2.5">Thuê</th>
                            <th class="text-left px-4 py-2.5">Đối soát</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in reelSlots" :key="s.reel_id" class="border-t border-[var(--color-line)] align-top">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <a :href="s.url" target="_blank" rel="noopener" class="font-mono text-xs text-[var(--color-accent)] hover:underline">{{ s.reel_id }}</a>
                            </td>
                            <td class="px-4 py-3">
                                <template v-if="s.product_key">
                                    <p class="text-[var(--color-ink)] font-medium">{{ s.product_name || s.product_key }}</p>
                                    <a v-if="s.target_url" :href="s.target_url" target="_blank" rel="noopener" class="font-mono text-xs text-[var(--color-muted)] hover:underline">{{ s.target_url }}</a>
                                </template>
                                <span v-else class="text-xs text-[var(--color-muted)]">(trống)</span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-xs">
                                <span v-if="s.leased" class="font-bold px-2 py-0.5 rounded-full border bg-amber-100 text-amber-700 border-amber-300">Đang thuê</span>
                                <span v-else class="font-bold px-2 py-0.5 rounded-full border bg-slate-100 text-slate-600 border-slate-300">Rảnh</span>
                                <p v-if="s.leased_until" class="text-[var(--color-muted)] mt-1">tới {{ s.leased_until }}</p>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <p v-if="s.synced_at" class="text-[var(--color-muted)]">{{ s.synced_human }}</p>
                                <p v-else class="text-[var(--color-muted)]">Chưa đối soát</p>
                                <p v-if="s.sync_error" class="text-red-600 mt-1 break-all">⚠ {{ s.sync_error.slice(0, 160) }}</p>
                            </td>
                        </tr>
                        <tr v-if="!reelSlots.length"><td colspan="4" class="px-4 py-6 text-center text-[var(--color-muted)]">Chưa có reel nào — cấu hình ở Cấu hình API → Facebook.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Lịch sử chạy -->
        <h2 class="text-base font-bold text-[var(--color-ink)] mb-3">Lịch sử chạy gần đây</h2>
        <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] overflow-hidden">
            <div v-for="r in runs" :key="r.id" class="border-t first:border-t-0 border-[var(--color-line)]">
                <button @click="toggle(r.id)" class="w-full text-left px-4 py-3 flex flex-wrap items-center gap-x-3 gap-y-1 hover:bg-[var(--color-bg)] transition">
                    <span class="text-xs font-bold px-2 py-0.5 rounded-full border" :class="RUN_STYLE[r.status]">{{ RUN_LABEL[r.status] }}</span>
                    <span class="font-mono text-xs text-[var(--color-ink)]">{{ r.command }}</span>
                    <span class="text-xs text-[var(--color-muted)]">{{ r.started_at }} · {{ duration(r.duration_ms) }} · {{ who(r.triggered_by) }}</span>
                    <span class="ml-auto text-xs text-[var(--color-muted)]">{{ expanded.has(r.id) ? '▲' : '▼' }}</span>
                </button>
                <pre v-if="expanded.has(r.id)" class="px-4 pb-4 text-xs font-mono whitespace-pre-wrap break-all text-[var(--color-ink)] bg-[var(--color-bg)] m-0 pt-3 max-h-96 overflow-y-auto">{{ r.output || '(không có output)' }}</pre>
            </div>
            <p v-if="!runs.length" class="px-4 py-6 text-center text-sm text-[var(--color-muted)]">Chưa có lần chạy nào được ghi.</p>
        </div>
    </AdminLayout>
</template>
