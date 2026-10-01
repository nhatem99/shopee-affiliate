<script setup>
import { ref, computed } from 'vue'
import { router, useForm, usePage, Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const page = usePage()

const props = defineProps({
    configured: Boolean,
    tokenFromEnv: Boolean,
    adminIdsFromEnv: Boolean,
    bot: Object,
    error: String,
    webhookUrl: String,
    expectedWebhookUrl: String,
    adminChatIds: Array,
    chats: Array,
})

const errors = computed(() => page.props.errors || {})
const webhookOk = computed(() => props.webhookUrl && props.webhookUrl === props.expectedWebhookUrl)

function done() {
    const msg = page.props.flash?.success
    if (msg) toast.success(msg)
}

const tokenForm = useForm({ token: '' })
function saveToken() {
    tokenForm.post('/admin/zalo-bot/token', {
        preserveScroll: true,
        onSuccess: () => { tokenForm.reset(); done() },
    })
}

const selected = ref([...(props.adminChatIds || [])])
const savingAdmins = ref(false)
function saveAdmins() {
    savingAdmins.value = true
    router.post('/admin/zalo-bot/admins', { chat_ids: selected.value }, {
        preserveScroll: true,
        onSuccess: done,
        onFinish: () => { savingAdmins.value = false },
    })
}

const busy = ref(null)
function act(name, method, url) {
    busy.value = name
    router[method](url, {}, {
        preserveScroll: true,
        onSuccess: done,
        onFinish: () => { busy.value = null },
    })
}

function fmt(iso) {
    return iso ? new Date(iso).toLocaleString('vi-VN') : '—'
}
</script>

<template>
    <Head title="Admin — Zalo Bot" />
    <AdminLayout>
        <template #title>Zalo Bot</template>

        <!-- 1. Token -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">1. Token bot</h2>
            <p v-if="bot" class="text-sm text-[var(--color-muted)] mb-3">
                Đang dùng bot <span class="font-semibold text-[var(--color-ink)]">{{ bot.name }}</span>
                <span class="font-mono">({{ bot.account }})</span>.
            </p>
            <p v-else-if="error" class="text-sm text-red-500 mb-3">{{ error }}</p>
            <p v-else class="text-sm text-[var(--color-muted)] mb-3">Chưa có token. Lấy token ở app Zalo Bot Creator.</p>

            <p v-if="tokenFromEnv" class="text-xs text-amber-600">
                Token đang lấy từ file .env trên server — muốn đổi ở đây thì xoá ZALO_BOT_TOKEN khỏi .env trước.
            </p>
            <form v-else @submit.prevent="saveToken" class="flex flex-col md:flex-row gap-3 items-start">
                <div class="flex-1 w-full">
                    <input v-model="tokenForm.token" type="password" autocomplete="off" required
                        :placeholder="configured ? 'Dán token mới nếu muốn thay' : 'Dán token bot vào đây'"
                        class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm font-mono focus:outline-none focus:border-[var(--color-accent)]" />
                    <p v-if="tokenForm.errors.token" class="text-red-500 text-xs mt-1">{{ tokenForm.errors.token }}</p>
                </div>
                <button type="submit" :disabled="tokenForm.processing"
                    class="w-full md:w-auto bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition disabled:opacity-60 whitespace-nowrap">
                    {{ tokenForm.processing ? 'Đang kiểm tra...' : 'Lưu token' }}
                </button>
            </form>
        </section>

        <!-- 2. Webhook -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">2. Webhook</h2>
            <p class="text-sm text-[var(--color-muted)] mb-3">
                Webhook để Zalo đẩy tin nhắn về web. Có webhook thì ai nhắn cho bot sẽ hiện ở bước 3.
            </p>
            <p class="text-sm mb-3">
                Trạng thái:
                <span v-if="webhookOk" class="font-semibold text-green-600">đang chạy</span>
                <span v-else-if="webhookUrl" class="font-semibold text-amber-600">trỏ sai chỗ ({{ webhookUrl }})</span>
                <span v-else class="font-semibold text-[var(--color-muted)]">chưa đặt</span>
                <span class="block text-xs text-[var(--color-muted)] font-mono mt-1">{{ expectedWebhookUrl }}</span>
            </p>
            <p v-if="errors.webhook" class="text-red-500 text-xs mb-3">{{ errors.webhook }}</p>
            <div class="flex flex-wrap gap-3">
                <button @click="act('webhook', 'post', '/admin/zalo-bot/webhook')" :disabled="!configured || busy"
                    class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-5 py-2.5 rounded-xl text-sm transition disabled:opacity-50">
                    {{ busy === 'webhook' ? 'Đang đặt...' : (webhookOk ? 'Đặt lại webhook' : 'Đặt webhook') }}
                </button>
                <button v-if="webhookUrl" @click="act('unhook', 'delete', '/admin/zalo-bot/webhook')" :disabled="busy"
                    class="bg-[var(--color-peach-soft)] text-[var(--color-ink)] font-semibold px-5 py-2.5 rounded-xl text-sm hover:bg-[var(--color-peach)] transition disabled:opacity-50">
                    Gỡ webhook
                </button>
            </div>
        </section>

        <!-- 3. Admin nhận báo -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">3. Admin nhận báo</h2>
            <p class="text-sm text-[var(--color-muted)] mb-3">
                Bot chỉ nhắn được cho người đã nhắn cho bot trước. Dùng Zalo của admin nhắn một tin bất kỳ cho bot,
                tải lại trang này, rồi tick tên mình.
            </p>
            <p v-if="adminIdsFromEnv" class="text-xs text-amber-600 mb-3">
                Danh sách admin đang lấy từ ZALO_BOT_ADMIN_CHAT_IDS trong .env — tick ở đây không có tác dụng.
            </p>

            <div class="border border-[var(--color-line)] rounded-xl overflow-hidden overflow-x-auto mb-4">
                <table class="w-full text-sm">
                    <thead class="bg-[var(--color-peach-soft)]">
                        <tr class="text-left text-xs text-[var(--color-muted)]">
                            <th class="px-4 py-2.5 font-semibold w-12">Admin</th>
                            <th class="px-4 py-2.5 font-semibold">Tên Zalo</th>
                            <th class="px-4 py-2.5 font-semibold">chat_id</th>
                            <th class="px-4 py-2.5 font-semibold">Nhắn lần cuối</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        <tr v-for="c in chats" :key="c.chat_id">
                            <td class="px-4 py-2.5">
                                <input type="checkbox" :value="c.chat_id" v-model="selected" :aria-label="`Chọn ${c.display_name || c.chat_id} làm admin`" />
                            </td>
                            <td class="px-4 py-2.5 font-semibold text-[var(--color-ink)]">{{ c.display_name || '—' }}</td>
                            <td class="px-4 py-2.5 font-mono text-xs text-[var(--color-muted)]">{{ c.chat_id }}</td>
                            <td class="px-4 py-2.5 text-xs text-[var(--color-muted)]">{{ fmt(c.last_message_at) }}</td>
                        </tr>
                        <tr v-if="!chats?.length">
                            <td colspan="4" class="px-4 py-8 text-center text-[var(--color-muted)]">Chưa ai nhắn cho bot.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-if="errors.chat_ids" class="text-red-500 text-xs mb-3">{{ errors.chat_ids }}</p>
            <p v-if="errors.test" class="text-red-500 text-xs mb-3">{{ errors.test }}</p>
            <div class="flex flex-wrap gap-3">
                <button @click="saveAdmins" :disabled="savingAdmins || adminIdsFromEnv"
                    class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-5 py-2.5 rounded-xl text-sm transition disabled:opacity-50">
                    {{ savingAdmins ? 'Đang lưu...' : 'Lưu admin' }}
                </button>
                <button @click="act('test', 'post', '/admin/zalo-bot/test')" :disabled="!configured || !adminChatIds?.length || busy"
                    class="bg-[var(--color-peach-soft)] text-[var(--color-ink)] font-semibold px-5 py-2.5 rounded-xl text-sm hover:bg-[var(--color-peach)] transition disabled:opacity-50">
                    {{ busy === 'test' ? 'Đang gửi...' : 'Gửi tin thử' }}
                </button>
            </div>
        </section>
    </AdminLayout>
</template>
