<script setup>
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useToast } from '@/composables/useToast'

/*
 * Công tắc "chỉ đổi sang link affiliate" — MỘT cài đặt dùng chung cho bài đăng nhóm Facebook và
 * nick Zalo trả lời trong nhóm (DirectAffiliateLinkService). Nằm ở cả hai trang Đăng nhóm FB và
 * Zalo nick nhóm vì admin tìm nó theo kênh đang làm; gạt ở trang nào thì trang kia đổi theo.
 */
const props = defineProps({
    enabled: { type: Boolean, required: true },
})

const toast = useToast()
const on = ref(props.enabled)
const saving = ref(false)

watch(() => props.enabled, (v) => { on.value = v })

function toggle() {
    const next = !on.value
    on.value = next
    saving.value = true

    router.post('/admin/settings', { group_links_direct_affiliate: next }, {
        preserveScroll: true,
        onSuccess: () => toast.success(next
            ? 'Đã bật — bài nhóm FB và bot Zalo gửi thẳng link affiliate Shopee, không mã.'
            : 'Đã tắt — bài nhóm FB và bot Zalo lại gửi link có mã như cũ.'),
        onError: () => {
            on.value = !next // rollback nếu lưu lỗi
            toast.error('Không lưu được cài đặt, vui lòng thử lại.')
        },
        onFinish: () => { saving.value = false },
    })
}
</script>

<template>
    <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5">
        <div class="flex items-start justify-between gap-6">
            <div class="min-w-0">
                <h2 class="font-extrabold text-[var(--color-ink)] mb-1">🔗 Chỉ đổi sang link affiliate</h2>
                <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                    Dùng chung cho <b>bài đăng nhóm Facebook</b> và <b>nick Zalo trả lời trong nhóm</b> — gạt ở đây là đổi cả hai.
                    <b>Bật</b>: không lấy mã nữa — link Shopee được đổi thẳng thành link affiliate chính thức của Shopee
                    (<span class="font-mono text-xs">s.shopee.vn/an_redir…</span>) mang ID của bạn, gửi nguyên link đó, không qua tietkiemvi.com.
                    Nhanh, không phụ thuộc nguồn mã, nhưng <b>khách không có mã giảm giá</b> và không đếm được lượt bấm ở trang admin.
                    <b>Tắt</b>: link có mã qua tietkiemvi.com như hiện nay.
                    Cả hai cách đều chưa hoàn tiền được cho khách trong nhóm — không biết ai là người mua.
                </p>
            </div>

            <button
                type="button"
                role="switch"
                :aria-checked="on"
                @click="toggle"
                :disabled="saving"
                class="relative flex-none w-14 h-8 rounded-full transition-colors duration-200 disabled:opacity-60"
                :class="on ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-line)]'"
            >
                <span
                    class="absolute top-1 left-1 w-6 h-6 rounded-full bg-white shadow-md transition-transform duration-200"
                    :class="on ? 'translate-x-6' : 'translate-x-0'"
                ></span>
            </button>
        </div>

        <div class="mt-4 pt-4 border-t border-[var(--color-line)] flex items-center gap-2 text-sm">
            <span class="w-2 h-2 rounded-full flex-none" :class="on ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-muted)]'"></span>
            <span class="text-[var(--color-ink)] font-medium">
                {{ on
                    ? 'Đang bật — nhóm FB và Zalo nhận link affiliate Shopee, không mã.'
                    : 'Đang tắt — nhóm FB và Zalo nhận link có mã qua tietkiemvi.com.' }}
            </span>
        </div>
    </section>
</template>
