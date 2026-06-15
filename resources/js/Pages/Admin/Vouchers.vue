<script setup>
import { ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const props = defineProps({
    vouchers: Array,
})

const showForm = ref(false)
const editing = ref(null)

function emptyForm() {
    return useForm({
        platform: 'shopee',
        source: 'manual',
        code: '',
        title: '',
        discount_type: 'flat',
        discount_value: 0,
        minimum_order: 0,
        expires_at: '',
        is_active: true,
    })
}

const form = ref(emptyForm())

function openAdd() {
    editing.value = null
    form.value = emptyForm()
    showForm.value = true
}

function openEdit(v) {
    editing.value = v.id
    form.value = useForm({
        platform: v.platform,
        source: v.source,
        code: v.code,
        title: v.title || '',
        discount_type: v.discount_type,
        discount_value: v.discount_value,
        minimum_order: v.minimum_order,
        expires_at: v.expires_at ? v.expires_at.substring(0, 10) : '',
        is_active: v.is_active,
    })
    showForm.value = true
}

function save() {
    if (editing.value) {
        form.value.patch(`/admin/vouchers/${editing.value}`, {
            onSuccess: () => { showForm.value = false; toast.success('Đã cập nhật voucher') },
        })
    } else {
        form.value.post('/admin/vouchers', {
            onSuccess: () => { showForm.value = false; toast.success('Đã thêm voucher') },
        })
    }
}

function destroy(id) {
    router.delete(`/admin/vouchers/${id}`, {
        onSuccess: () => toast.success('Đã xóa voucher'),
    })
}

const sourceLabels = { facebook: 'Facebook', youtube: 'YouTube', manual: 'Thủ công' }
const sourceColors = {
    facebook: 'bg-blue-100 text-blue-700',
    youtube: 'bg-red-100 text-red-700',
    manual: 'bg-gray-100 text-gray-600',
}
const platformLabels = { shopee: 'Shopee', lazada: 'Lazada', tiki: 'Tiki', tiktok: 'TikTok', all: 'Tất cả' }

function discountText(v) {
    if (v.discount_type === 'percent') return `-${v.discount_value}%`
    if (v.discount_type === 'freeship') return 'Miễn ship'
    return `-₫${Number(v.discount_value).toLocaleString('vi-VN')}`
}
</script>

<template>
    <Head title="Admin — Voucher toàn sàn" />
    <AdminLayout>
        <template #title>Voucher toàn sàn (Facebook / YouTube)</template>

        <div class="flex justify-end mb-4">
            <button @click="openAdd"
                class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white text-sm font-semibold px-4 py-2 rounded-xl transition">
                + Thêm voucher
            </button>
        </div>

        <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-peach-soft)]">
                    <tr class="text-left text-xs text-[var(--color-muted)]">
                        <th class="px-5 py-3 font-semibold">Mã</th>
                        <th class="px-5 py-3 font-semibold">Tiêu đề</th>
                        <th class="px-5 py-3 font-semibold">Giảm</th>
                        <th class="px-5 py-3 font-semibold">Nguồn</th>
                        <th class="px-5 py-3 font-semibold">Sàn</th>
                        <th class="px-5 py-3 font-semibold">Hết hạn</th>
                        <th class="px-5 py-3 font-semibold">Trạng thái</th>
                        <th class="px-5 py-3 font-semibold">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-line)]">
                    <tr v-for="v in vouchers" :key="v.id">
                        <td class="px-5 py-3 font-mono font-bold text-[var(--color-accent)]">{{ v.code }}</td>
                        <td class="px-5 py-3 text-[var(--color-ink)] max-w-[160px] truncate">{{ v.title || '—' }}</td>
                        <td class="px-5 py-3 font-semibold text-[var(--color-brand-green)]">{{ discountText(v) }}</td>
                        <td class="px-5 py-3">
                            <span :class="sourceColors[v.source]" class="px-2 py-0.5 rounded-full text-xs font-semibold">
                                {{ sourceLabels[v.source] }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-[var(--color-muted)] text-xs">{{ platformLabels[v.platform] }}</td>
                        <td class="px-5 py-3 text-[var(--color-muted)] text-xs">
                            {{ v.expires_at ? new Date(v.expires_at).toLocaleDateString('vi-VN') : '—' }}
                        </td>
                        <td class="px-5 py-3">
                            <span :class="v.is_active ? 'bg-[var(--color-green-soft)] text-[var(--color-brand-green)]' : 'bg-[var(--color-peach-soft)] text-[var(--color-muted)]'"
                                class="px-2 py-0.5 rounded-full text-xs font-semibold">
                                {{ v.is_active ? 'Đang bật' : 'Tắt' }}
                            </span>
                        </td>
                        <td class="px-5 py-3 flex gap-3">
                            <button @click="openEdit(v)" class="text-xs font-semibold text-[var(--color-accent)] hover:underline">Sửa</button>
                            <button @click="destroy(v.id)" class="text-xs font-semibold text-red-500 hover:underline">Xóa</button>
                        </td>
                    </tr>
                    <tr v-if="!vouchers?.length">
                        <td colspan="8" class="px-5 py-10 text-center text-[var(--color-muted)]">Chưa có voucher nào. Nhấn "+ Thêm voucher" để bắt đầu.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Form modal -->
        <div v-if="showForm" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="bg-[var(--color-surface)] rounded-2xl p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
                <h2 class="font-extrabold text-[var(--color-ink)] mb-5">{{ editing ? 'Chỉnh sửa voucher' : 'Thêm voucher mới' }}</h2>
                <form @submit.prevent="save" class="space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Sàn</label>
                            <select v-model="form.platform" class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--color-accent)]">
                                <option value="shopee">Shopee</option>
                                <option value="lazada">Lazada</option>
                                <option value="tiki">Tiki</option>
                                <option value="tiktok">TikTok</option>
                                <option value="all">Tất cả sàn</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Nguồn</label>
                            <select v-model="form.source" class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--color-accent)]">
                                <option value="facebook">Facebook</option>
                                <option value="youtube">YouTube</option>
                                <option value="manual">Thủ công</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Mã voucher *</label>
                        <input v-model="form.code" type="text" required placeholder="VD: SHOPEE22, FBSALE50K"
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm font-mono focus:outline-none focus:border-[var(--color-accent)]" />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Tiêu đề / Mô tả</label>
                        <input v-model="form.title" type="text" placeholder="VD: Giảm 22% tối đa 50K"
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--color-accent)]" />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Loại giảm giá</label>
                            <select v-model="form.discount_type" class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--color-accent)]">
                                <option value="flat">Giảm tiền (₫)</option>
                                <option value="percent">Giảm % </option>
                                <option value="freeship">Miễn phí ship</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">
                                {{ form.discount_type === 'percent' ? 'Phần trăm (%)' : 'Số tiền (₫)' }}
                            </label>
                            <input v-model="form.discount_value" type="number" min="0"
                                class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--color-accent)]" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Đơn tối thiểu (₫)</label>
                            <input v-model="form.minimum_order" type="number" min="0"
                                class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--color-accent)]" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Ngày hết hạn</label>
                            <input v-model="form.expires_at" type="date"
                                class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--color-accent)]" />
                        </div>
                    </div>

                    <label class="flex items-center gap-2 text-sm font-semibold text-[var(--color-ink)] cursor-pointer">
                        <input v-model="form.is_active" type="checkbox" class="w-4 h-4 accent-[var(--color-accent)]" />
                        Kích hoạt ngay
                    </label>

                    <div class="flex gap-3 pt-2">
                        <button type="submit" :disabled="form.processing"
                            class="flex-1 bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold py-2.5 rounded-xl text-sm transition disabled:opacity-60">
                            {{ editing ? 'Lưu thay đổi' : 'Thêm voucher' }}
                        </button>
                        <button type="button" @click="showForm = false"
                            class="px-6 bg-[var(--color-peach-soft)] text-[var(--color-ink)] font-semibold py-2.5 rounded-xl text-sm hover:bg-[var(--color-peach)] transition">
                            Hủy
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
