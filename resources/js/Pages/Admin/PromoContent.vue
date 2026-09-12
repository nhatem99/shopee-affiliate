<script setup>
import { ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const props = defineProps({
    templates: { type: Array, default: () => [] },
    cashbackOn: { type: Boolean, default: false },
    cashbackRate: { type: String, default: '0' },
    topPercent: { type: Number, default: 0 },
    secondPercent: { type: Number, default: 0 },
    siteUrl: { type: String, default: '' },
})

// --- Hai mức giảm dùng trong bài đăng ---
const topPercent = ref(props.topPercent)
const secondPercent = ref(props.secondPercent)
const savingPercents = ref(false)

watch(() => props.topPercent, (v) => { topPercent.value = v })
watch(() => props.secondPercent, (v) => { secondPercent.value = v })

function savePercents() {
    savingPercents.value = true

    router.post('/admin/promo', {
        top_percent: Number(topPercent.value) || 0,
        second_percent: Number(secondPercent.value) || 0,
    }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Đã lưu — các mẫu bên dưới đã cập nhật số mới.'),
        onError: (errors) => toast.error(errors.top_percent || errors.second_percent || 'Không lưu được, thử lại nhé.'),
        onFinish: () => { savingPercents.value = false },
    })
}

// --- Nội dung từng mẫu ---
// Bản nháp admin đang sửa, tách khỏi props: mỗi nhóm Facebook một kiểu nên admin hay chỉnh vài
// chữ trước khi đăng. Cố ý KHÔNG lưu xuống server — sửa ở đây chỉ dùng cho lượt copy này, bấm
// "Khôi phục" là về lại bản gốc đã bơm số.
const drafts = ref({})

function draftFor(t) {
    if (drafts.value[t.id] === undefined) drafts.value[t.id] = t.body

    return drafts.value[t.id]
}

// Bơm lại bản gốc khi server trả mẫu mới (admin vừa đổi mức giảm hoặc tỉ lệ hoàn tiền) — nếu
// không thì ô soạn vẫn giữ số cũ và admin copy đi một bài đăng sai số.
watch(() => props.templates, (list) => {
    const next = {}
    for (const t of list) next[t.id] = t.body
    drafts.value = next
}, { deep: true })

const copyingId = ref(null)

async function copyTemplate(t) {
    if (copyingId.value) return

    copyingId.value = t.id
    try {
        await navigator.clipboard.writeText(drafts.value[t.id] ?? t.body)
        toast.success('Đã sao chép — dán thẳng vào bài đăng được rồi.')
    } catch (e) {
        toast.error('Không sao chép được. Bôi đen nội dung rồi Ctrl+C thủ công nhé.')
    } finally {
        copyingId.value = null
    }
}

function resetTemplate(t) {
    drafts.value[t.id] = t.body
    toast.info('Đã khôi phục nội dung gốc.')
}

const channelLabels = {
    'group-fb': 'Nhóm Facebook',
    zalo: 'Zalo / nhắn riêng',
    comment: 'Bình luận ngắn',
    'tiktok-reel': 'TikTok / Reels',
}
</script>

<template>
    <Head title="Bài giới thiệu" />
    <AdminLayout>
        <div class="max-w-4xl space-y-6">
            <div>
                <h1 class="text-2xl font-extrabold text-[var(--color-ink)]">Bài giới thiệu</h1>
                <p class="text-sm text-[var(--color-muted)] mt-1">
                    Mẫu sẵn để copy đi đăng nhóm Facebook, Zalo, TikTok. Số trong bài được bơm tự động
                    từ cấu hình thật nên không sợ đăng sai.
                </p>
            </div>

            <!-- Mức giảm dùng trong bài -->
            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6">
                <h2 class="font-bold text-[var(--color-ink)] mb-1">Mức giảm ghi trong bài</h2>
                <p class="text-sm text-[var(--color-muted)] leading-relaxed mb-4">
                    Hai con số này là mức giảm của <strong class="text-[var(--color-ink)]">nguồn cấp mã</strong>,
                    hệ thống không tự biết được nên bạn tự đặt và tự giữ cho đúng. Nguồn đổi mức giảm thì sửa ở đây —
                    mọi mẫu bên dưới đổi theo ngay, khỏi phải sửa tay từng bài.
                </p>
                <div class="flex flex-col sm:flex-row gap-3">
                    <div class="flex-1 min-w-0">
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Mức cao nhất</label>
                        <div class="relative">
                            <input
                                v-model="topPercent"
                                type="number" min="0" max="100" step="1"
                                @keydown.enter="savePercents"
                                class="w-full px-4 py-2.5 pr-10 rounded-xl border border-[var(--color-line)] bg-[var(--color-bg)] text-sm text-[var(--color-ink)] tabular-nums focus:outline-none focus:border-[var(--color-accent)]"
                            />
                            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-[var(--color-muted)] pointer-events-none">%</span>
                        </div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Mức thứ hai</label>
                        <div class="relative">
                            <input
                                v-model="secondPercent"
                                type="number" min="0" max="100" step="1"
                                @keydown.enter="savePercents"
                                class="w-full px-4 py-2.5 pr-10 rounded-xl border border-[var(--color-line)] bg-[var(--color-bg)] text-sm text-[var(--color-ink)] tabular-nums focus:outline-none focus:border-[var(--color-accent)]"
                            />
                            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-[var(--color-muted)] pointer-events-none">%</span>
                        </div>
                    </div>
                    <div class="flex items-end">
                        <button
                            type="button"
                            @click="savePercents"
                            :disabled="savingPercents"
                            class="btn-fire px-6 py-2.5 rounded-xl text-sm whitespace-nowrap disabled:opacity-60"
                        >{{ savingPercents ? 'Đang lưu...' : 'Lưu' }}</button>
                    </div>
                </div>

                <div class="mt-4 pt-4 border-t border-[var(--color-line)] flex items-center gap-2 text-sm">
                    <span class="w-2 h-2 rounded-full flex-none" :class="cashbackOn ? 'bg-[var(--color-brand-green)]' : 'bg-[var(--color-muted)]'"></span>
                    <span class="text-[var(--color-ink)] font-medium">
                        {{ cashbackOn
                            ? `Bài đăng đang ghi hoàn ${cashbackRate}% hoa hồng — đúng bằng tỉ lệ ở Cài đặt.`
                            : 'Hoàn tiền đang tắt — các mẫu nhắc hoàn tiền đã được ẩn đi.' }}
                    </span>
                </div>
            </div>

            <!-- Danh sách mẫu -->
            <div v-for="t in templates" :key="t.id" class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6">
                <div class="flex items-start justify-between gap-4 mb-1 flex-wrap">
                    <h2 class="font-bold text-[var(--color-ink)]">{{ t.name }}</h2>
                    <span class="flex-none text-xs font-semibold px-2.5 py-1 rounded-full bg-[var(--color-peach-soft)] text-[var(--color-accent-deep)]">
                        {{ channelLabels[t.channel] || t.channel }}
                    </span>
                </div>
                <p class="text-xs text-[var(--color-muted)] leading-relaxed mb-4">{{ t.when_to_use }}</p>

                <textarea
                    v-model="drafts[t.id]"
                    rows="10"
                    :placeholder="t.body"
                    @focus="draftFor(t)"
                    class="w-full px-4 py-3 rounded-xl border border-[var(--color-line)] bg-[var(--color-bg)] text-sm text-[var(--color-ink)] leading-relaxed font-sans resize-y focus:outline-none focus:border-[var(--color-accent)]"
                ></textarea>

                <div class="flex items-center gap-2 mt-3 flex-wrap">
                    <button
                        type="button"
                        @click="copyTemplate(t)"
                        :disabled="copyingId === t.id"
                        class="btn-fire px-5 py-2.5 rounded-xl text-sm disabled:opacity-60"
                    >📋 Copy nội dung</button>
                    <button
                        v-if="drafts[t.id] !== t.body"
                        type="button"
                        @click="resetTemplate(t)"
                        class="px-4 py-2.5 rounded-xl text-sm font-semibold bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] text-[var(--color-ink)] transition"
                    >Khôi phục bản gốc</button>
                    <span v-if="drafts[t.id] !== t.body" class="text-xs text-[var(--color-muted)]">
                        Đang sửa — bản sửa không được lưu lại, chỉ dùng cho lượt copy này.
                    </span>
                </div>
            </div>

            <div v-if="!templates.length" class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-10 text-center">
                <p class="text-[var(--color-muted)] text-sm">Chưa có mẫu nào hiển thị được.</p>
            </div>
        </div>
    </AdminLayout>
</template>
