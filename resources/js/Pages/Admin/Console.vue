<script setup>
import { Head, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const props = defineProps({
    commands: Array,
    runs: Array,
})

const form = useForm({ command: '' })

// Dòng lịch sử nào đang mở output.
const expanded = ref(new Set())
function toggle(id) {
    const next = new Set(expanded.value)
    next.has(id) ? next.delete(id) : next.add(id)
    expanded.value = next
}

function submit() {
    if (form.processing || !form.command.trim()) return
    form.post('/admin/console/run', {
        preserveScroll: true,
        onSuccess: (page) => {
            const f = page.props.flash || {}
            f.error ? toast.error(f.error) : toast.success(f.success || 'Đã chạy xong.')
            // Mở luôn dòng vừa chạy — đó là thứ admin muốn nhìn ngay.
            const latest = page.props.runs?.[0]
            if (latest) expanded.value = new Set([latest.id, ...expanded.value])
        },
        onError: (errors) => toast.error(errors.command || 'Không chạy được.'),
    })
}

// Bấm một lệnh trong danh sách → điền vào ô, admin sửa tham số rồi chạy.
function pick(c) {
    form.command = c.usage
    form.clearErrors()
    document.getElementById('command-input')?.focus()
}

const RUN_STYLE = {
    ok: 'bg-emerald-100 text-emerald-700 border-emerald-300',
    failed: 'bg-red-100 text-red-700 border-red-300',
    running: 'bg-sky-100 text-sky-700 border-sky-300',
}
const RUN_LABEL = { ok: 'Thành công', failed: 'Lỗi', running: 'Đang chạy' }

function duration(ms) {
    if (ms == null) return ''
    return ms < 1000 ? `${ms}ms` : `${(ms / 1000).toFixed(1)}s`
}

function who(triggeredBy) {
    return `admin #${triggeredBy.split(':')[1]}`
}
</script>

<template>
    <Head title="Admin — Lệnh artisan" />
    <AdminLayout>
        <template #title>Lệnh artisan</template>

        <p class="text-sm text-[var(--color-muted)] mb-4">
            Dán lệnh <span class="font-mono">php artisan ...</span> vào đây và chạy ngay trên server, không cần SSH.
            Chỉ chạy được các lệnh trong danh sách bên dưới — không phải shell, không chạy được
            <span class="font-mono">git pull</span>, <span class="font-mono">npm run build</span> hay <span class="font-mono">tinker</span>.
        </p>

        <!-- Ô nhập lệnh -->
        <form @submit.prevent="submit" class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-4 mb-6">
            <label for="command-input" class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Lệnh</label>
            <div class="flex flex-col md:flex-row gap-2">
                <div class="flex-1 min-w-0 flex items-center bg-slate-900 rounded-xl px-3 focus-within:ring-2 focus-within:ring-[var(--color-accent)]">
                    <span class="font-mono text-xs text-slate-500 flex-none select-none">$ php artisan</span>
                    <input id="command-input" v-model="form.command" type="text" autocomplete="off" spellcheck="false"
                        placeholder="orders:notify-backfill --send"
                        @keydown.enter.prevent="submit"
                        class="flex-1 min-w-0 bg-transparent font-mono text-sm text-emerald-300 placeholder:text-slate-600 px-2 py-2.5 focus:outline-none" />
                </div>
                <button type="submit" :disabled="form.processing || !form.command.trim()"
                    class="flex-none px-5 py-2.5 rounded-xl text-sm font-semibold bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white transition disabled:opacity-60 whitespace-nowrap">
                    {{ form.processing ? 'Đang chạy...' : '▶ Chạy' }}
                </button>
            </div>
            <p v-if="form.errors.command" class="text-red-500 text-xs mt-2">{{ form.errors.command }}</p>
            <p v-else class="text-xs text-[var(--color-muted)] mt-2">
                Có dán kèm <span class="font-mono">php artisan</span> ở đầu cũng được. Lệnh chạy tối đa 5 phút; output hiện ở lịch sử bên dưới.
            </p>
        </form>

        <!-- Danh sách lệnh được phép -->
        <h2 class="text-base font-bold text-[var(--color-ink)] mb-1">Lệnh được phép</h2>
        <p class="text-xs text-[var(--color-muted)] mb-3">Bấm vào một lệnh để điền sẵn vào ô trên rồi sửa tham số nếu cần.</p>
        <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] overflow-hidden mb-6">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-[var(--color-bg)] text-xs text-[var(--color-muted)] uppercase">
                        <tr>
                            <th class="text-left px-4 py-2.5">Lệnh</th>
                            <th class="text-left px-4 py-2.5">Mô tả</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in commands" :key="c.name" class="border-t border-[var(--color-line)] align-top">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <button type="button" @click="pick(c)" class="font-mono text-xs text-[var(--color-accent)] hover:underline text-left">{{ c.usage }}</button>
                                <span v-if="c.app" class="ml-2 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-[var(--color-peach-soft)] text-[var(--color-accent)]">app</span>
                            </td>
                            <td class="px-4 py-3 text-[var(--color-ink)]/80">{{ c.description }}</td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" @click="pick(c)" class="text-xs font-semibold text-[var(--color-muted)] hover:text-[var(--color-accent)] transition whitespace-nowrap">Điền ↑</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Lịch sử chạy -->
        <h2 class="text-base font-bold text-[var(--color-ink)] mb-3">Lịch sử chạy tay</h2>
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
            <p v-if="!runs.length" class="px-4 py-6 text-center text-sm text-[var(--color-muted)]">Chưa chạy lệnh nào.</p>
        </div>
    </AdminLayout>
</template>
