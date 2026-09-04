<script setup>
import { Head, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import axios from 'axios'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const props = defineProps({
    configs: Array,
})

const editing = ref(null)
const testResult = ref({})
const facebookPosts = ref([])
const loadingPosts = ref(false)
const postsError = ref(null)

function editConfig(config) {
    editing.value = useForm({
        name: config.name,
        endpoint: config.endpoint,
        app_id: config.app_id || '',
        app_secret: '',
        is_active: config.is_active,
        platform: config.platform,
        meta: {
            target_post_id: config.meta?.target_post_id || '',
            comment_redirect_enabled: config.meta?.comment_redirect_enabled || false,
        },
    })

    facebookPosts.value = []
    postsError.value = null
    if (config.platform === 'facebook') {
        loadFacebookPosts(config.id)
    }
}

async function loadFacebookPosts(configId) {
    loadingPosts.value = true
    postsError.value = null
    try {
        const { data } = await axios.get(`/admin/api-config/${configId}/facebook-posts`)
        facebookPosts.value = data.posts
    } catch (e) {
        postsError.value = e.response?.data?.message || 'Không tải được danh sách bài viết.'
    } finally {
        loadingPosts.value = false
    }
}

function formatPostDate(iso) {
    return new Date(iso).toLocaleString('vi-VN')
}

function saveConfig() {
    editing.value.post('/admin/api-config', {
        onSuccess: () => { toast.success('Đã lưu cấu hình.'); editing.value = null },
        onError: (errors) => toast.error(Object.values(errors)[0] || 'Lưu cấu hình thất bại.'),
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
                    <p><span class="font-medium text-[var(--color-ink)]">{{ config.platform === 'facebook' ? 'Page ID' : 'App ID' }}:</span> {{ config.app_id || '—' }}</p>
                    <p><span class="font-medium text-[var(--color-ink)]">{{ config.platform === 'facebook' ? 'Page Access Token' : 'Secret' }}:</span> ••••••••</p>
                    <template v-if="config.platform === 'facebook'">
                        <p><span class="font-medium text-[var(--color-ink)]">Target Post ID:</span> {{ config.meta?.target_post_id || '—' }}</p>
                        <p>
                            <span class="font-medium text-[var(--color-ink)]">Chuyển hướng qua comment FB:</span>
                            {{ config.meta?.comment_redirect_enabled ? 'Đang bật' : 'Đang tắt (khách bấm mã đi thẳng Shopee)' }}
                        </p>
                    </template>
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
            <div class="bg-[var(--color-surface)] rounded-2xl p-6 w-full max-w-md max-h-[90vh] overflow-y-auto">
                <h2 class="font-extrabold text-[var(--color-ink)] mb-4">Chỉnh sửa cấu hình</h2>
                <form @submit.prevent="saveConfig" class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Endpoint URL</label>
                        <input v-model="editing.endpoint" type="url" class="w-full border border-[var(--color-line)] rounded-xl px-4 py-2.5 text-sm text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)] transition" />
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">{{ editing.platform === 'facebook' ? 'Page ID' : 'App ID / Publisher ID' }}</label>
                        <input v-model="editing.app_id" type="text" class="w-full border border-[var(--color-line)] rounded-xl px-4 py-2.5 text-sm text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)] transition" />
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">{{ editing.platform === 'facebook' ? 'Page Access Token' : 'App Secret / API Key' }}</label>
                        <input v-model="editing.app_secret" type="text" placeholder="Nhập key mới (để trống = giữ nguyên)"
                            class="w-full border border-[var(--color-line)] rounded-xl px-4 py-2.5 text-sm text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)] transition" />
                    </div>
                    <template v-if="editing.platform === 'facebook'">
                        <label class="flex items-center gap-2 text-sm font-semibold text-[var(--color-ink)] cursor-pointer">
                            <input v-model="editing.meta.comment_redirect_enabled" type="checkbox" class="w-4 h-4 accent-[var(--color-accent)]" />
                            Bật chuyển hướng qua comment Facebook
                        </label>
                        <p class="text-xs text-[var(--color-muted)] -mt-2">
                            Khi bật: mọi lượt bấm mã sẽ đăng comment lên bài viết chọn bên dưới rồi đưa khách tới đúng comment đó thay vì Shopee.
                            Khi tắt: khách bấm mã đi thẳng Shopee như bình thường, không đụng gì tới Facebook.
                        </p>

                        <div>
                            <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Bài viết sẽ nhận comment</label>

                            <div v-if="loadingPosts" class="text-sm text-[var(--color-muted)]">Đang tải danh sách bài viết...</div>
                            <div v-else-if="postsError" class="text-sm text-red-600">{{ postsError }}</div>
                            <div v-else-if="!facebookPosts.length" class="text-sm text-[var(--color-muted)]">Không có bài viết nào (kiểm tra Page ID/Token).</div>
                            <div v-else class="max-h-56 overflow-y-auto space-y-1.5 border border-[var(--color-line)] rounded-xl p-2">
                                <label v-for="post in facebookPosts" :key="post.id"
                                    :class="editing.meta.target_post_id === post.id ? 'border-[var(--color-accent)] bg-[var(--color-peach-soft)]' : 'border-transparent'"
                                    class="flex items-start gap-2 p-2 rounded-lg border cursor-pointer hover:bg-[var(--color-peach-soft)] transition">
                                    <input v-model="editing.meta.target_post_id" :value="post.id" type="radio" class="mt-1 w-4 h-4 accent-[var(--color-accent)] shrink-0" />
                                    <div class="min-w-0">
                                        <p class="text-sm text-[var(--color-ink)] line-clamp-2">{{ post.message || '(Bài viết không có nội dung text)' }}</p>
                                        <p class="text-xs text-[var(--color-muted)] mt-0.5">{{ formatPostDate(post.created_time) }}</p>
                                    </div>
                                </label>
                            </div>

                            <input v-model="editing.meta.target_post_id" type="text" placeholder="Hoặc nhập Post ID thủ công, vd: 1266570819867675_123456789"
                                class="w-full mt-2 border border-[var(--color-line)] rounded-xl px-4 py-2.5 text-sm text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)] transition" />
                        </div>
                    </template>
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
