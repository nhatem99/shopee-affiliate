<script setup>
import { router, Head } from '@inertiajs/vue3'
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps({
    files: Array,
    levels: Array,
    filters: Object,
    entries: Array,
    counts: Object,
    truncated: Boolean,
    total: Number,
})

const file = ref(props.filters.file ?? '')
const level = ref(props.filters.level ?? 'WARNING')
const q = ref(props.filters.q ?? '')
const reloading = ref(false)

const selectedFile = computed(() => props.files.find(f => f.name === props.filters.file) ?? null)

// Dòng nào đang mở phần chi tiết (context JSON + stack trace).
const expanded = ref(new Set())

function toggle(id) {
    const next = new Set(expanded.value)
    next.has(id) ? next.delete(id) : next.add(id)
    expanded.value = next
}

function fetchLogs() {
    router.get('/admin/logs', { file: file.value, level: level.value, q: q.value || undefined }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => { reloading.value = true },
        onFinish: () => { reloading.value = false },
    })
}

// Gõ tới đâu lọc tới đó, nhưng chờ 400ms cho ngưng tay — mỗi lần gọi là 1 lần đọc file trên server.
let searchTimer = null
watch(q, () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(fetchLogs, 400)
})
onBeforeUnmount(() => clearTimeout(searchTimer))

watch([file, level], fetchLogs)

const LEVEL_STYLES = {
    EMERGENCY: 'bg-red-100 text-red-700 border-red-300',
    ALERT: 'bg-red-100 text-red-700 border-red-300',
    CRITICAL: 'bg-red-100 text-red-700 border-red-300',
    ERROR: 'bg-red-100 text-red-700 border-red-300',
    WARNING: 'bg-amber-100 text-amber-700 border-amber-300',
    NOTICE: 'bg-sky-100 text-sky-700 border-sky-300',
    INFO: 'bg-sky-100 text-sky-700 border-sky-300',
    DEBUG: 'bg-slate-100 text-slate-600 border-slate-300',
}

const LEVEL_LABELS = {
    ALL: 'Tất cả',
    DEBUG: 'DEBUG trở lên',
    INFO: 'INFO trở lên',
    WARNING: 'WARNING trở lên',
    ERROR: 'ERROR trở lên',
}

function kb(bytes) {
    if (!bytes) return '0 KB'
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
</script>

<template>
    <Head title="Admin — Nhật ký lỗi" />
    <AdminLayout>
        <template #title>Nhật ký lỗi</template>

        <p class="text-sm text-[var(--color-muted)] mb-4">
            Đọc trực tiếp <span class="font-mono">storage/logs</span> của server đang chạy — không cần SSH.
            Hiển thị phần mới nhất của file, mới nhất lên đầu.
        </p>

        <!-- Bộ lọc -->
        <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4 mb-4 flex flex-col md:flex-row gap-3 md:items-end">
            <div class="flex-1 min-w-0">
                <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">File log</label>
                <select v-model="file" class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-surface)] focus:outline-none focus:border-[var(--color-accent)]">
                    <option v-for="f in files" :key="f.name" :value="f.name">
                        {{ f.name }} — {{ kb(f.size) }} — {{ f.modified }}{{ f.readable ? '' : ' (không đọc được)' }}
                    </option>
                </select>
            </div>

            <div class="w-full md:w-52">
                <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Mức</label>
                <select v-model="level" class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-surface)] focus:outline-none focus:border-[var(--color-accent)]">
                    <option v-for="l in levels" :key="l" :value="l">{{ LEVEL_LABELS[l] || l }}</option>
                </select>
            </div>

            <div class="flex-1 min-w-0">
                <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Tìm trong nội dung</label>
                <input v-model="q" type="text" placeholder="VD: SalesOc, 403, timeout..."
                    class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--color-accent)]" />
            </div>

            <button @click="fetchLogs" :disabled="reloading"
                class="w-full md:w-auto bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition disabled:opacity-60 whitespace-nowrap">
                {{ reloading ? 'Đang tải...' : '↻ Làm mới' }}
            </button>
        </div>

        <!-- Tổng quan mức độ trong phần log đã nạp -->
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <span
                v-for="(count, lvl) in counts"
                :key="lvl"
                class="text-xs font-bold px-2.5 py-1 rounded-full border"
                :class="LEVEL_STYLES[lvl] || 'bg-slate-100 text-slate-600 border-slate-300'"
            >{{ lvl }}: {{ count }}</span>
            <span class="text-xs text-[var(--color-muted)]">
                Khớp bộ lọc: <strong class="text-[var(--color-ink)]">{{ total }}</strong>
                <template v-if="total > entries.length"> (hiện {{ entries.length }} dòng đầu)</template>
            </span>
        </div>

        <p v-if="selectedFile && !selectedFile.readable" class="text-xs text-red-600 bg-red-50 border border-red-200 rounded-xl px-3 py-2 mb-4">
            ⚠️ Không đọc được <span class="font-mono">{{ selectedFile.name }}</span> — user chạy PHP không có quyền đọc file này.
            Trên server chạy: <span class="font-mono">chmod 644 storage/logs/*.log</span>
        </p>

        <p v-if="truncated" class="text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 mb-4">
            ⚠️ File log lớn hơn 2MB — chỉ phần cuối file được nạp. Log cũ hơn cần xem trực tiếp trên server.
        </p>

        <!-- Danh sách bản ghi -->
        <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] divide-y divide-[var(--color-line)] overflow-hidden">
            <div v-for="entry in entries" :key="entry.id" class="px-4 py-3">
                <button
                    @click="toggle(entry.id)"
                    class="w-full text-left flex items-start gap-3"
                    :class="entry.detail ? 'cursor-pointer' : 'cursor-default'"
                >
                    <span
                        class="flex-none text-[10px] font-bold px-2 py-0.5 rounded border mt-0.5"
                        :class="LEVEL_STYLES[entry.level] || 'bg-slate-100 text-slate-600 border-slate-300'"
                    >{{ entry.level }}</span>
                    <span class="flex-none text-xs font-mono text-[var(--color-muted)] mt-0.5 hidden sm:block">{{ entry.logged_at }}</span>
                    <span class="flex-1 min-w-0 text-sm text-[var(--color-ink)] break-words">
                        {{ entry.message }}
                        <span class="block sm:hidden text-xs font-mono text-[var(--color-muted)] mt-1">{{ entry.logged_at }}</span>
                    </span>
                    <span v-if="entry.detail" class="flex-none text-xs text-[var(--color-muted)] mt-0.5">
                        {{ expanded.has(entry.id) ? '▲' : '▼' }}
                    </span>
                </button>

                <pre v-if="entry.detail && expanded.has(entry.id)"
                    class="mt-3 text-xs font-mono bg-[var(--color-peach-soft)] text-[var(--color-ink)] rounded-xl p-3 overflow-x-auto whitespace-pre-wrap break-words max-h-96 overflow-y-auto">{{ entry.detail }}</pre>
            </div>

            <p v-if="!entries.length" class="px-5 py-10 text-center text-sm text-[var(--color-muted)]">
                Không có bản ghi nào khớp bộ lọc.
            </p>
        </div>
    </AdminLayout>
</template>
