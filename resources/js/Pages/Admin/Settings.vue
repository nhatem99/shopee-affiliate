<script setup>
import { ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps({
    customerAuthEnabled: { type: Boolean, required: true },
    maintenanceMode: { type: Boolean, required: true },
})

const toast = useToast()
const customerAuthEnabled = ref(props.customerAuthEnabled)
const maintenanceMode = ref(props.maintenanceMode)
const savingCustomerAuth = ref(false)
const savingMaintenance = ref(false)

// Đồng bộ lại nếu server trả về giá trị khác (ví dụ sau khi lưu xong)
watch(() => props.customerAuthEnabled, (v) => { customerAuthEnabled.value = v })
watch(() => props.maintenanceMode, (v) => { maintenanceMode.value = v })

function toggleCustomerAuth() {
    const next = !customerAuthEnabled.value
    customerAuthEnabled.value = next
    savingCustomerAuth.value = true

    router.post('/admin/settings', { customer_auth_enabled: next }, {
        preserveScroll: true,
        onSuccess: () => toast.success(next ? 'Đã bật đăng nhập/đăng ký cho khách.' : 'Đã tắt đăng nhập/đăng ký cho khách.'),
        onError: () => {
            customerAuthEnabled.value = !next // rollback nếu lưu lỗi
            toast.error('Không lưu được cài đặt, vui lòng thử lại.')
        },
        onFinish: () => { savingCustomerAuth.value = false },
    })
}

function toggleMaintenance() {
    const next = !maintenanceMode.value
    maintenanceMode.value = next
    savingMaintenance.value = true

    router.post('/admin/settings', { maintenance_mode: next }, {
        preserveScroll: true,
        onSuccess: () => toast.success(next ? 'Đã bật chế độ bảo trì.' : 'Đã tắt chế độ bảo trì.'),
        onError: () => {
            maintenanceMode.value = !next // rollback nếu lưu lỗi
            toast.error('Không lưu được cài đặt, vui lòng thử lại.')
        },
        onFinish: () => { savingMaintenance.value = false },
    })
}
</script>

<template>
    <Head title="Admin — Cài đặt chung" />
    <AdminLayout>
        <template #title>Cài đặt chung</template>

        <div class="max-w-2xl space-y-6">
            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="min-w-0">
                        <h2 class="font-bold text-[var(--color-ink)] mb-1">Đăng nhập / Đăng ký cho khách</h2>
                        <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                            Khi tắt, nút "Đăng nhập" và "Đăng ký" sẽ không hiện trên trang cho khách nữa, và khách gõ thẳng
                            link <span class="font-mono text-xs">/login</span>, <span class="font-mono text-xs">/register</span>
                            cũng sẽ được chuyển về trang chủ. Bật lại bất cứ lúc nào khi cần.
                        </p>
                    </div>

                    <button
                        type="button"
                        role="switch"
                        :aria-checked="customerAuthEnabled"
                        @click="toggleCustomerAuth"
                        :disabled="savingCustomerAuth"
                        class="relative flex-none w-14 h-8 rounded-full transition-colors duration-200 disabled:opacity-60"
                        :class="customerAuthEnabled ? 'bg-[var(--color-brand-green)]' : 'bg-[var(--color-line)]'"
                    >
                        <span
                            class="absolute top-1 left-1 w-6 h-6 rounded-full bg-white shadow-md transition-transform duration-200"
                            :class="customerAuthEnabled ? 'translate-x-6' : 'translate-x-0'"
                        ></span>
                    </button>
                </div>

                <div class="mt-4 pt-4 border-t border-[var(--color-line)] flex items-center gap-2 text-sm">
                    <span class="w-2 h-2 rounded-full flex-none" :class="customerAuthEnabled ? 'bg-[var(--color-brand-green)]' : 'bg-[var(--color-muted)]'"></span>
                    <span class="text-[var(--color-ink)] font-medium">
                        {{ customerAuthEnabled ? 'Đang bật — khách có thể đăng nhập/đăng ký.' : 'Đang tắt — khách không thấy mục đăng nhập/đăng ký.' }}
                    </span>
                </div>
            </div>

            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="min-w-0">
                        <h2 class="font-bold text-[var(--color-ink)] mb-1">Chế độ bảo trì</h2>
                        <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                            Khi bật, toàn bộ trang cho khách (trang chủ, blog, quét link, lịch sử, tài khoản...) hiện
                            thông báo "đang bảo trì" thay vì nội dung thật. Trang <span class="font-mono text-xs">/login</span>
                            và khu vực admin vẫn vào được bình thường để bạn tự tắt lại khi xong.
                            <strong class="text-[var(--color-ink)]">Tài khoản admin vẫn dùng được đầy đủ trang khách</strong>
                            (dán link, tìm mã, bấm thử link) để kiểm tra chức năng trong lúc khách bị chặn.
                        </p>
                    </div>

                    <button
                        type="button"
                        role="switch"
                        :aria-checked="maintenanceMode"
                        @click="toggleMaintenance"
                        :disabled="savingMaintenance"
                        class="relative flex-none w-14 h-8 rounded-full transition-colors duration-200 disabled:opacity-60"
                        :class="maintenanceMode ? 'bg-amber-500' : 'bg-[var(--color-line)]'"
                    >
                        <span
                            class="absolute top-1 left-1 w-6 h-6 rounded-full bg-white shadow-md transition-transform duration-200"
                            :class="maintenanceMode ? 'translate-x-6' : 'translate-x-0'"
                        ></span>
                    </button>
                </div>

                <div class="mt-4 pt-4 border-t border-[var(--color-line)] flex items-center gap-2 text-sm">
                    <span class="w-2 h-2 rounded-full flex-none" :class="maintenanceMode ? 'bg-amber-500' : 'bg-[var(--color-muted)]'"></span>
                    <span class="text-[var(--color-ink)] font-medium">
                        {{ maintenanceMode ? 'Đang bảo trì — khách không vào được trang, admin vẫn dùng bình thường.' : 'Đang tắt — trang hoạt động bình thường.' }}
                    </span>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
