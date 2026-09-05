<script setup>
import { Head, router, usePage } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps({
    configs: Array,
})

const page = usePage()
const toast = useToast()

// Mirrors SOURCE_STYLES from Home.vue for consistent brand colours in the admin UI.
const SOURCE_STYLES = {
    facebook:  'bg-gradient-to-r from-blue-600 to-blue-500 text-white',
    instagram: 'bg-gradient-to-r from-[#e1306c] via-[#fd1d1d] to-[#fcb045] text-white',
    zalo:      'bg-gradient-to-r from-[#0068ff] to-[#0052cc] text-white',
    youtube:   'bg-gradient-to-r from-[#f97316] to-[#ea580c] text-white',
}

// Human-readable default label for each source (shown as input placeholder when no override set).
// Matches the SOURCE_LABELS const in Home.vue and the channel table in ChannelVoucherMinter.
const SOURCE_DEFAULT_HINT = {
    facebook:  '(dùng label thật từ API, vd: Mã FB 22%)',
    instagram: 'Mã IG',
    zalo:      'Zalo',
    youtube:   'Mã YTB',
}

const SOURCE_NAMES = {
    facebook:  'Facebook',
    instagram: 'Instagram',
    zalo:      'Zalo',
    youtube:   'YouTube',
}

// Local reactive copy of each config row for two-way binding.
const forms = ref(
    props.configs.map(c => ({
        id: c.id,
        source: c.source,
        label: c.label ?? '',
        sort_order: c.sort_order,
        is_featured: c.is_featured,
        saving: false,
    }))
)

// Flash success message from Inertia back()->with('success', ...).
const flash = computed(() => page.props.flash?.success ?? null)

function save(form) {
    form.saving = true
    router.patch(`/admin/voucher-buttons/${form.id}`, {
        label: form.label.trim() || null,
        sort_order: form.sort_order,
        is_featured: form.is_featured,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(`Đã lưu cấu hình nút ${SOURCE_NAMES[form.source] ?? form.source}.`)
            // Reflect radio-group side-effect: if this row is now featured, clear others locally.
            if (form.is_featured) {
                forms.value.forEach(f => {
                    if (f.id !== form.id) f.is_featured = false
                })
            }
        },
        onError: () => {
            toast.error('Lưu thất bại, vui lòng kiểm tra lại.')
        },
        onFinish: () => {
            form.saving = false
        },
    })
}
</script>

<template>
    <Head title="Admin — Nút Voucher" />
    <AdminLayout>
        <template #title>Cấu hình Nút Voucher</template>

        <div class="space-y-4 max-w-2xl">
            <p class="text-sm text-[var(--color-muted)]">
                Tuỳ chỉnh nhãn, thứ tự hiển thị và nút "Đề xuất" trên trang chọn mã giảm giá.
                Nhãn tuỳ chỉnh sẽ <strong>ghi đè toàn bộ</strong> nhãn của nguồn đó — kể cả nhãn thật từ API (vd: "Mã FB 22%").
                Để trống = giữ nhãn mặc định / nhãn thật từ API.
            </p>

            <!-- Empty state — the table has no rows (defaults never seeded). Without this
                 the page renders as a blank panel with no hint of what went wrong. -->
            <div
                v-if="!forms.length"
                class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 text-sm text-[var(--color-muted)]"
            >
                Chưa có cấu hình nút nào. Chạy
                <code class="font-mono text-[var(--color-ink)]">php artisan db:seed --class=VoucherButtonConfigSeeder</code>
                để tạo 4 nguồn mặc định (Facebook, Instagram, Zalo, YouTube).
            </div>

            <div
                v-for="form in forms"
                :key="form.id"
                class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5"
            >
                <!-- Source header -->
                <div class="flex items-center gap-3 mb-4">
                    <span
                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-xs font-black"
                        :class="SOURCE_STYLES[form.source] ?? 'bg-[var(--color-peach-soft)] text-[var(--color-ink)]'"
                    >
                        {{ SOURCE_NAMES[form.source]?.slice(0, 2) ?? form.source.slice(0, 2) }}
                    </span>
                    <h3 class="font-extrabold text-[var(--color-ink)]">{{ SOURCE_NAMES[form.source] ?? form.source }}</h3>
                    <span
                        v-if="form.is_featured"
                        class="text-[10px] font-extrabold bg-[#facc15] text-[#1c0a00] px-2 py-0.5 rounded-full"
                    >Đề xuất</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Label override -->
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">
                            Nhãn tuỳ chỉnh
                        </label>
                        <input
                            v-model="form.label"
                            type="text"
                            :placeholder="SOURCE_DEFAULT_HINT[form.source] ?? ''"
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm text-[var(--color-ink)] bg-[var(--color-bg)] focus:outline-none focus:border-[var(--color-accent)] transition"
                        />
                        <p class="text-[11px] text-[var(--color-muted)] mt-1">Để trống = dùng nhãn mặc định / nhãn thật từ API</p>
                    </div>

                    <!-- Sort order -->
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">
                            Thứ tự hiển thị (số nhỏ = trước)
                        </label>
                        <input
                            v-model.number="form.sort_order"
                            type="number"
                            min="0"
                            max="99"
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm text-[var(--color-ink)] bg-[var(--color-bg)] focus:outline-none focus:border-[var(--color-accent)] transition"
                        />
                    </div>
                </div>

                <!-- Featured toggle -->
                <label class="flex items-center gap-2.5 mt-4 cursor-pointer select-none">
                    <input
                        v-model="form.is_featured"
                        type="checkbox"
                        class="w-4 h-4 accent-[var(--color-accent)]"
                    />
                    <span class="text-sm font-semibold text-[var(--color-ink)]">Nổi bật — hiện huy hiệu "Đề xuất" + nhấp nháy</span>
                </label>
                <p class="text-[11px] text-[var(--color-muted)] mt-1 ml-6.5">
                    Chỉ một nguồn được nổi bật. Bật nguồn này sẽ tự tắt nguồn đang nổi bật trước đó.
                </p>

                <!-- Save button -->
                <div class="mt-4 flex justify-end">
                    <button
                        @click="save(form)"
                        :disabled="form.saving"
                        class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-6 py-2 rounded-xl text-sm transition disabled:opacity-60"
                    >
                        {{ form.saving ? 'Đang lưu...' : 'Lưu' }}
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
