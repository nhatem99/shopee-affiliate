<script setup>
import { Head, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import axios from 'axios'

const props = defineProps({
    configs: Array,
})

const editing = ref(null)
const testResult = ref({})

function editConfig(config) {
    editing.value = useForm({
        name: config.name,
        endpoint: config.endpoint,
        app_id: config.app_id || '',
        app_secret: '',
        is_active: config.is_active,
        platform: config.platform,
    })
}

function saveConfig() {
    editing.value.post('/admin/api-config', {
        onSuccess: () => { editing.value = null },
    })
}

async function testConfig(config) {
    testResult.value[config.id] = { loading: true }
    try {
        const res = await axios.post(`/admin/api-config/${config.id}/test`)
        testResult.value[config.id] = { ok: res.data.ok, message: res.data.message }
    } catch (e) {
        testResult.value[config.id] = { ok: false, message: e.response?.data?.message || 'Lỗi kết nối.' }
    }
}
</script>

<template>
    <Head title="Admin — Cấu hình API" />
    <AdminLayout>
        <template #title>Cấu hình API</template>

        <div class="space-y-4">
            <div
                v-for="config in configs"
                :key="config.id"
                class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6"
            >
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <h3 class="font-extrabold text-[var(--color-ink)]">{{ config.name }}</h3>
                        <span :class="config.is_active ? 'bg-[var(--color-green-soft)] text-[var(--color-brand-green)]' : 'bg-[var(--color-peach-soft)] text-[var(--color-muted)]'"
                            class="text-xs font-semibold px-2 py-0.5 rounded-full">
                            {{ config.is_active ? 'Đang hoạt động' : 'Tắt' }}
                        </span>
                    </div>
                    <div class="flex gap-2">
                        <button @click="testConfig(config)"
                            class="text-sm font-semibold text-[var(--color-accent)] hover:underline">Kiểm tra kết nối</button>
                        <button @click="editConfig(config)"
                            class="text-sm font-semibold text-[var(--color-ink)] hover:text-[var(--color-accent)] transition">Chỉnh sửa</button>
                    </div>
                </div>

                <div class="text-sm text-[var(--color-muted)] space-y-1">
                    <p><span class="font-medium text-[var(--color-ink)]">Endpoint:</span> {{ config.endpoint }}</p>
                    <p><span class="font-medium text-[var(--color-ink)]">App ID:</span> {{ config.app_id || '—' }}</p>
                    <p><span class="font-medium text-[var(--color-ink)]">Secret:</span> ••••••••</p>
                </div>

                <!-- Test result -->
                <div v-if="testResult[config.id]" class="mt-3">
                    <div v-if="testResult[config.id].loading" class="text-sm text-[var(--color-muted)]">Đang kiểm tra...</div>
                    <div v-else :class="testResult[config.id].ok ? 'text-[var(--color-brand-green)]' : 'text-red-600'" class="text-sm font-semibold">
                        {{ testResult[config.id].ok ? '✓' : '✗' }} {{ testResult[config.id].message }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit form modal -->
        <div v-if="editing" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="bg-[var(--color-surface)] rounded-2xl p-6 w-full max-w-md">
                <h2 class="font-extrabold text-[var(--color-ink)] mb-4">Chỉnh sửa cấu hình</h2>
                <form @submit.prevent="saveConfig" class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Endpoint URL</label>
                        <input v-model="editing.endpoint" type="url" class="w-full border border-[var(--color-line)] rounded-xl px-4 py-2.5 text-sm text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)] transition" />
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">App ID / Publisher ID</label>
                        <input v-model="editing.app_id" type="text" class="w-full border border-[var(--color-line)] rounded-xl px-4 py-2.5 text-sm text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)] transition" />
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">App Secret / API Key</label>
                        <input v-model="editing.app_secret" type="text" placeholder="Nhập key mới (để trống = giữ nguyên)"
                            class="w-full border border-[var(--color-line)] rounded-xl px-4 py-2.5 text-sm text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)] transition" />
                    </div>
                    <label class="flex items-center gap-2 text-sm font-semibold text-[var(--color-ink)] cursor-pointer">
                        <input v-model="editing.is_active" type="checkbox" class="w-4 h-4 accent-[var(--color-accent)]" />
                        Kích hoạt
                    </label>
                    <div class="flex gap-3 pt-2">
                        <button type="submit" :disabled="editing.processing"
                            class="flex-1 bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold py-2.5 rounded-xl text-sm transition disabled:opacity-60">
                            Lưu cấu hình
                        </button>
                        <button type="button" @click="editing = null"
                            class="px-6 bg-[var(--color-peach-soft)] text-[var(--color-ink)] font-semibold py-2.5 rounded-xl text-sm hover:bg-[var(--color-peach)] transition">
                            Hủy
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
