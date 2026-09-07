<script setup>
import { Head, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
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
            // Nhóm bài nhận comment. Cấu hình đời đầu chỉ có target_post_id (số ít) — nạp nó
            // làm phần tử đầu để admin mở form ra không thấy mất bài đang chạy.
            target_post_ids: config.meta?.target_post_ids?.length
                ? [...config.meta.target_post_ids]
                : (config.meta?.target_post_id ? [config.meta.target_post_id] : []),
            comment_redirect_enabled: config.meta?.comment_redirect_enabled || false,
            reel_caption_enabled: config.meta?.reel_caption_enabled || false,
            target_reel_ids: config.meta?.target_reel_ids ? [...config.meta.target_reel_ids] : [],
            reel_lease_minutes: config.meta?.reel_lease_minutes ?? 10,
            // auto_source là tên cũ thời salesoc (chọn 1 trong 4 loại mã). Giờ chỉ còn 1 link
            // nên nó là công tắc — đọc giá trị cũ để cấu hình đang chạy không mất tác dụng.
            auto_redirect_enabled: config.meta?.auto_redirect_enabled ?? !!config.meta?.auto_source,
            // Tham số gọi kieushopee — next_action đổi mỗi lần site nguồn deploy lại.
            next_action: config.meta?.next_action || '',
            tool_id: config.meta?.tool_id || '',
            action_payload: config.meta?.action_payload || '',
            test_url: config.meta?.test_url || '',
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

/**
 * Ô nhập tay giữ những bài KHÔNG có trong danh sách bài gần đây (bài cũ, hoặc reel — API
 * /posts không trả reels). Tách riêng khỏi các ô tick để hai bên không ghi đè nhau: ô tick
 * quản phần bài có trong danh sách, ô này quản phần còn lại, ghép lại thành target_post_ids.
 */
const manualPostIds = computed({
    get() {
        const listed = facebookPosts.value.map(p => p.id)

        return (editing.value?.meta.target_post_ids ?? [])
            .filter(id => !listed.includes(id))
            .join('\n')
    },
    set(value) {
        const listed = facebookPosts.value.map(p => p.id)
        const picked = editing.value.meta.target_post_ids.filter(id => listed.includes(id))
        const typed = value.split(/\r?\n/).map(id => id.trim()).filter(Boolean)

        editing.value.meta.target_post_ids = [...new Set([...picked, ...typed])]
    },
})

// Nhóm reel nhập tay hoàn toàn: Graph API không trả reels ở edge /posts nên không có danh
// sách để tick, admin dán link reel copy từ app (mỗi dòng một cái).
const reelIdsText = computed({
    get() {
        return (editing.value?.meta.target_reel_ids ?? []).join('\n')
    },
    set(value) {
        editing.value.meta.target_reel_ids = [...new Set(
            value.split(/\r?\n/).map(id => id.trim()).filter(Boolean),
        )]
    },
})

// Thẻ tóm tắt: đọc cả cấu hình đời đầu (target_post_id số ít) để không hiện "—" nhầm.
function summarisePostPool(config) {
    const pool = config.meta?.target_post_ids?.length
        ? config.meta.target_post_ids
        : (config.meta?.target_post_id ? [config.meta.target_post_id] : [])

    if (!pool.length) return '—'

    return pool.length === 1 ? pool[0] : `${pool.length} bài (xoay vòng)`
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
                    <template v-if="config.platform !== 'kieushopee'">
                        <p><span class="font-medium text-[var(--color-ink)]">{{ config.platform === 'facebook' ? 'Page ID' : 'App ID' }}:</span> {{ config.app_id || '—' }}</p>
                        <p><span class="font-medium text-[var(--color-ink)]">{{ config.platform === 'facebook' ? 'Page Access Token' : 'Secret' }}:</span> ••••••••</p>
                    </template>

                    <template v-if="config.platform === 'kieushopee'">
                        <p class="font-mono text-xs break-all"><span class="font-sans font-medium text-[var(--color-ink)]">next-action:</span> {{ config.meta?.next_action || '—' }}</p>
                        <p class="font-mono text-xs break-all"><span class="font-sans font-medium text-[var(--color-ink)]">1_toolId:</span> {{ config.meta?.tool_id || '—' }}</p>
                        <p class="font-mono text-xs break-all"><span class="font-sans font-medium text-[var(--color-ink)]">field "0":</span> {{ config.meta?.action_payload || '—' }}</p>
                    </template>

                    <template v-if="config.platform === 'facebook'">
                        <p>
                            <span class="font-medium text-[var(--color-ink)]">Bài nhận comment:</span>
                            {{ summarisePostPool(config) }}
                        </p>
                        <p>
                            <span class="font-medium text-[var(--color-ink)]">Chế độ đổi caption reel:</span>
                            {{ config.meta?.reel_caption_enabled
                                ? `Đang bật — ${config.meta?.target_reel_ids?.length || 0} reel, giữ ${config.meta?.reel_lease_minutes ?? 10} phút/sản phẩm`
                                : 'Đang tắt (dùng comment)' }}
                        </p>
                        <p>
                            <span class="font-medium text-[var(--color-ink)]">Chuyển hướng qua comment FB:</span>
                            {{ config.meta?.comment_redirect_enabled ? 'Đang bật' : 'Đang tắt (khách bấm mã đi thẳng Shopee)' }}
                        </p>
                        <p>
                            <span class="font-medium text-[var(--color-ink)]">Tự chuyển hướng:</span>
                            {{ (config.meta?.auto_redirect_enabled ?? !!config.meta?.auto_source)
                                ? 'Đang bật (dán link xong đi thẳng, không hiện nút)'
                                : 'Đang tắt (khách tự bấm "Mua ngay")' }}
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
                    <!-- kieushopee không dùng App ID/Secret — ẩn đi để khỏi tưởng phải điền. -->
                    <template v-if="editing.platform !== 'kieushopee'">
                        <div>
                            <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">{{ editing.platform === 'facebook' ? 'Page ID' : 'App ID / Publisher ID' }}</label>
                            <input v-model="editing.app_id" type="text" class="w-full border border-[var(--color-line)] rounded-xl px-4 py-2.5 text-sm text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)] transition" />
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">{{ editing.platform === 'facebook' ? 'Page Access Token' : 'App Secret / API Key' }}</label>
                            <input v-model="editing.app_secret" type="text" placeholder="Nhập key mới (để trống = giữ nguyên)"
                                class="w-full border border-[var(--color-line)] rounded-xl px-4 py-2.5 text-sm text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)] transition" />
                        </div>
                    </template>

                    <template v-if="editing.platform === 'kieushopee'">
                        <div class="rounded-xl bg-[var(--color-peach-soft)] border border-[var(--color-accent)]/25 px-3 py-2.5">
                            <p class="text-xs text-[var(--color-accent-deep)] leading-relaxed">
                                Site nguồn deploy lại là <b>next-action đổi</b> và tính năng lấy mã chết ngay.
                                Lấy giá trị mới: mở tool trên sansale.kieushopee.com bằng trình duyệt máy tính →
                                <b>F12 → tab Network</b> → bấm nút chuyển link → chọn request <span class="font-mono">POST</span> →
                                copy header <span class="font-mono">next-action</span> và field <span class="font-mono">1_toolId</span> trong phần Payload.
                                Lưu xong bấm <b>Kiểm tra kết nối</b> là biết ngay còn chạy không.
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Header <span class="font-mono">next-action</span></label>
                            <input v-model="editing.meta.next_action" type="text" spellcheck="false"
                                class="w-full border border-[var(--color-line)] rounded-xl px-4 py-2.5 text-sm font-mono text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)] transition" />
                            <p class="text-xs text-[var(--color-muted)] mt-1">Thứ hay đổi nhất. Để trống = dùng giá trị mặc định trong code.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Field <span class="font-mono">1_toolId</span></label>
                            <input v-model="editing.meta.tool_id" type="text" spellcheck="false"
                                class="w-full border border-[var(--color-line)] rounded-xl px-4 py-2.5 text-sm font-mono text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)] transition" />
                            <p class="text-xs text-[var(--color-muted)] mt-1">ID của tool đang dùng trên site nguồn.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Field <span class="font-mono">"0"</span></label>
                            <input v-model="editing.meta.action_payload" type="text" spellcheck="false"
                                class="w-full border border-[var(--color-line)] rounded-xl px-4 py-2.5 text-sm font-mono text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)] transition" />
                            <p class="text-xs text-[var(--color-muted)] mt-1">Hiếm khi đổi. Chỉ đổi khi họ thêm/bớt tham số cho tool.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Link Shopee để kiểm tra</label>
                            <input v-model="editing.meta.test_url" type="url" spellcheck="false"
                                class="w-full border border-[var(--color-line)] rounded-xl px-4 py-2.5 text-sm text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)] transition" />
                            <p class="text-xs text-[var(--color-muted)] mt-1">Nút "Kiểm tra kết nối" gọi thử bằng link này. Đổi nếu sản phẩm cũ đã bị gỡ khỏi Shopee.</p>
                        </div>
                    </template>
                    <template v-if="editing.platform === 'facebook'">
                        <label class="flex items-center gap-2 text-sm font-semibold text-[var(--color-ink)] cursor-pointer">
                            <input v-model="editing.meta.comment_redirect_enabled" type="checkbox" class="w-4 h-4 accent-[var(--color-accent)]" />
                            Bật chuyển hướng qua comment Facebook
                        </label>
                        <p class="text-xs text-[var(--color-muted)] -mt-2">
                            Khi bật: mọi lượt bấm mã sẽ đăng comment lên bài viết chọn bên dưới rồi đưa khách tới đúng comment đó thay vì Shopee.
                            Khi tắt: khách bấm mã đi thẳng Shopee như bình thường, không đụng gì tới Facebook.
                        </p>

                        <label class="flex items-center gap-2 text-sm font-semibold text-[var(--color-ink)] cursor-pointer">
                            <input v-model="editing.meta.auto_redirect_enabled" type="checkbox" class="w-4 h-4 accent-[var(--color-accent)]" />
                            Tự chuyển hướng ngay sau khi dán link
                        </label>
                        <p class="text-xs text-[var(--color-muted)] -mt-2">
                            Khi bật: khách dán link xong đi thẳng tới comment luôn, không thấy nút "Mua ngay" nữa —
                            mỗi sản phẩm vì thế chỉ sinh đúng 1 comment thay vì mỗi lượt bấm lại thêm 1 cái.
                            Chỉ có tác dụng khi đã bật chuyển hướng qua comment ở trên.
                        </p>

                        <label class="flex items-center gap-2 text-sm font-semibold text-[var(--color-ink)] cursor-pointer">
                            <input v-model="editing.meta.reel_caption_enabled" type="checkbox" class="w-4 h-4 accent-[var(--color-accent)]" />
                            Đổi caption reel thay vì đăng comment
                        </label>
                        <p class="text-xs text-[var(--color-muted)] -mt-2">
                            Cách này tốt hơn hẳn comment, đã đo trên máy thật: link <code>/reel/</code> mở thẳng
                            <b>ứng dụng Facebook</b>, và link trong <b>phần mô tả reel bấm được</b> (link trong bình luận
                            reel thì không, Facebook hiện thành chuỗi text). Khi bật, hệ thống đổi caption của một reel
                            thành link sản phẩm rồi đưa khách tới đúng reel đó.
                        </p>

                        <div v-if="editing.meta.reel_caption_enabled">
                            <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Nhóm reel dùng để đổi caption</label>
                            <p class="text-xs text-[var(--color-muted)] mb-2">
                                Mỗi dòng một link reel. <b>Mỗi reel chỉ hiện được MỘT link tại một thời điểm</b>, nên số
                                reel ở đây chính là số sản phẩm khác nhau phục vụ được cùng lúc — nên có 5–10 cái.
                                Hết reel trống thì khách được đưa thẳng tới Shopee (vẫn đúng sản phẩm, chỉ là không đi
                                qua Facebook) chứ không bao giờ bị đưa tới reel đang hiện link sản phẩm khác.
                                Lấy danh sách reel bằng lệnh <code>php artisan facebook:reel-caption</code>.
                            </p>
                            <textarea v-model="reelIdsText" rows="5"
                                placeholder="https://www.facebook.com/reel/123456&#10;https://www.facebook.com/reel/789012"
                                class="w-full border border-[var(--color-line)] rounded-xl px-4 py-2.5 text-sm text-[var(--color-ink)] font-mono focus:outline-none focus:border-[var(--color-accent)] transition"></textarea>

                            <label class="block text-sm font-semibold text-[var(--color-ink)] mt-3 mb-1">Giữ mỗi reel bao lâu (phút)</label>
                            <p class="text-xs text-[var(--color-muted)] mb-2">
                                Dài quá thì đông khách là hết reel trống; ngắn quá thì khách còn đang xem đã bị đổi
                                caption sang sản phẩm khác. Mặc định 10 phút.
                            </p>
                            <input v-model.number="editing.meta.reel_lease_minutes" type="number" min="1" max="120"
                                class="w-32 border border-[var(--color-line)] rounded-xl px-4 py-2.5 text-sm text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)] transition" />
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-[var(--color-ink)] mb-1">Bài viết sẽ nhận comment</label>
                            <p class="text-xs text-[var(--color-muted)] mb-2">
                                <b>Dùng bài viết thường</b>, đừng dùng reel. Đã thử trên máy thật: link
                                <code>/reel/...</code> mở được ứng dụng Facebook, nhưng link nằm trong bình luận của
                                reel lại <b>không bấm được</b> (Facebook hiện thành chuỗi text) nên khách sang tới nơi
                                cũng không làm gì được. Bài viết thường thì ngược lại: hay mở bằng trình duyệt, nhưng
                                link trong bình luận bấm được — đó mới là thứ quyết định.
                            </p>
                            <p class="text-xs text-[var(--color-muted)] mb-2">
                                Chọn <b>nhiều bài/reel</b> (nên 5–10). Mỗi sản phẩm được đăng comment vào một bài
                                khác nhau, xoay vòng lần lượt — nhiều khách bấm cùng lúc thì comment rải đều thay vì
                                dồn hết vào một chỗ, nên bình luận cần tìm không bị đẩy xuống dưới nút "Xem thêm
                                bình luận" và page cũng đỡ bị Facebook đánh dấu spam.
                                Đang chọn: <b>{{ editing.meta.target_post_ids.length }}</b> mục.
                            </p>

                            <div v-if="loadingPosts" class="text-sm text-[var(--color-muted)]">Đang tải danh sách bài viết...</div>
                            <div v-else-if="postsError" class="text-sm text-red-600">{{ postsError }}</div>
                            <div v-else-if="!facebookPosts.length" class="text-sm text-[var(--color-muted)]">Không có bài viết nào (kiểm tra Page ID/Token).</div>
                            <div v-else class="max-h-56 overflow-y-auto space-y-1.5 border border-[var(--color-line)] rounded-xl p-2">
                                <label v-for="post in facebookPosts" :key="post.id"
                                    :class="editing.meta.target_post_ids.includes(post.id) ? 'border-[var(--color-accent)] bg-[var(--color-peach-soft)]' : 'border-transparent'"
                                    class="flex items-start gap-2 p-2 rounded-lg border cursor-pointer hover:bg-[var(--color-peach-soft)] transition">
                                    <input v-model="editing.meta.target_post_ids" :value="post.id" type="checkbox" class="mt-1 w-4 h-4 accent-[var(--color-accent)] shrink-0" />
                                    <div class="min-w-0">
                                        <p class="text-sm text-[var(--color-ink)] line-clamp-2">{{ post.message || '(Bài viết không có nội dung text)' }}</p>
                                        <p class="text-xs text-[var(--color-muted)] mt-0.5">{{ formatPostDate(post.created_time) }}</p>
                                    </div>
                                </label>
                            </div>

                            <!-- Bài không nằm trong danh sách gần đây (hoặc reel) thì nhập tay, mỗi dòng một ID. -->
                            <textarea v-model="manualPostIds" rows="3"
                                placeholder="Mỗi dòng một mục — link reel (https://www.facebook.com/reel/123456) hoặc Post ID (1266570819867675_123456789)"
                                class="w-full mt-2 border border-[var(--color-line)] rounded-xl px-4 py-2.5 text-sm text-[var(--color-ink)] font-mono focus:outline-none focus:border-[var(--color-accent)] transition"></textarea>
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
