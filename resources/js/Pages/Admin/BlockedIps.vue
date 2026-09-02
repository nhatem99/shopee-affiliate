<script setup>
import { router, useForm, Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const props = defineProps({
    blockedIps: Array,
    currentIp: String,
})

const form = useForm({
    ip_address: '',
    note: '',
})

function add() {
    form.post('/admin/blocked-ips', {
        onSuccess: () => { form.reset(); toast.success('Đã chặn IP') },
    })
}

function destroy(id) {
    router.delete(`/admin/blocked-ips/${id}`, {
        onSuccess: () => toast.success('Đã bỏ chặn'),
    })
}
</script>

<template>
    <Head title="Admin — Chặn IP" />
    <AdminLayout>
        <template #title>Chặn IP</template>

        <p class="text-sm text-[var(--color-muted)] mb-4">
            IP của bạn đang dùng: <span class="font-mono font-semibold text-[var(--color-ink)]">{{ currentIp }}</span>
            — không thể tự chặn IP này.
        </p>

        <form @submit.prevent="add" class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-6 flex flex-col md:flex-row gap-3 items-start">
            <div class="flex-1 w-full">
                <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Địa chỉ IP *</label>
                <input v-model="form.ip_address" type="text" required placeholder="VD: 1.2.3.4"
                    class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm font-mono focus:outline-none focus:border-[var(--color-accent)]" />
                <p v-if="form.errors.ip_address" class="text-red-500 text-xs mt-1">{{ form.errors.ip_address }}</p>
            </div>
            <div class="flex-1 w-full">
                <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Ghi chú (không bắt buộc)</label>
                <input v-model="form.note" type="text" placeholder="VD: bot spam, khách khiếu nại..."
                    class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--color-accent)]" />
            </div>
            <button type="submit" :disabled="form.processing"
                class="w-full md:w-auto mt-1 md:mt-6 bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition disabled:opacity-60 whitespace-nowrap">
                + Chặn IP
            </button>
        </form>

        <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] overflow-hidden overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-peach-soft)]">
                    <tr class="text-left text-xs text-[var(--color-muted)]">
                        <th class="px-5 py-3 font-semibold">Địa chỉ IP</th>
                        <th class="px-5 py-3 font-semibold">Ghi chú</th>
                        <th class="px-5 py-3 font-semibold">Ngày chặn</th>
                        <th class="px-5 py-3 font-semibold">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-line)]">
                    <tr v-for="b in blockedIps" :key="b.id">
                        <td class="px-5 py-3 font-mono font-bold text-[var(--color-ink)]">{{ b.ip_address }}</td>
                        <td class="px-5 py-3 text-[var(--color-muted)]">{{ b.note || '—' }}</td>
                        <td class="px-5 py-3 text-[var(--color-muted)] text-xs">{{ new Date(b.created_at).toLocaleDateString('vi-VN') }}</td>
                        <td class="px-5 py-3">
                            <button @click="destroy(b.id)" class="text-xs font-semibold text-red-500 hover:underline">Bỏ chặn</button>
                        </td>
                    </tr>
                    <tr v-if="!blockedIps?.length">
                        <td colspan="4" class="px-5 py-10 text-center text-[var(--color-muted)]">Chưa chặn IP nào.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
