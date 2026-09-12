import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useAuthStore } from '@/Stores/useAuthStore'

/**
 * Nguồn sự thật duy nhất cho MỌI nội dung nói về hoàn tiền trên giao diện khách.
 *
 * Chỉ có MỘT con số quyết định — `cashbackRate` (Setting 'cashback_rate', share ở
 * HandleInertiaRequests). Không thêm cờ boolean song song ở đâu nữa: hai nguồn sự thật thì sớm
 * muộn cũng lệch nhau, và cái giá của việc lệch ở đây là trang web hứa hoàn tiền trong lúc
 * CashbackService::sync() đang trả 0đ cho tất cả mọi người.
 *
 * `cashbackOn` = false xảy ra ở đúng trạng thái mặc định của hệ thống (rate = 0, admin chưa bật
 * chương trình), nên mọi khối nội dung hoàn tiền PHẢI bọc `v-if="cashbackOn"`.
 */
export function useCashback() {
    const page = usePage()
    const auth = useAuthStore()

    const cashbackRate = computed(() => Number(page.props.settings?.cashbackRate ?? 0))
    const cashbackOn = computed(() => cashbackRate.value > 0)
    const minWithdrawal = computed(() => Number(page.props.settings?.minWithdrawal ?? 0))

    // Đăng ký có thể bị admin tắt (Setting 'customer_auth_enabled'). Khi tắt, /register trả 404
    // qua middleware customer.auth.enabled — nên mọi CTA phải tự đổi đích sang /login thay vì
    // đẩy khách vào trang chết.
    const customerAuthEnabled = computed(() => page.props.settings?.customerAuthEnabled ?? true)
    const joinHref = computed(() => (customerAuthEnabled.value ? '/register' : '/login'))
    const joinLabel = computed(() => (customerAuthEnabled.value ? 'Đăng ký nhận hoàn tiền' : 'Đăng nhập để được hoàn tiền'))

    // Khách vãng lai bấm mua = đơn đó vĩnh viễn không quy về ai được (ShortLinkController chỉ gắn
    // sub_id khi có user đăng nhập). Đây là điều kiện đáng nói nhất với khách, nên tách riêng.
    const missingOut = computed(() => cashbackOn.value && !auth.isLoggedIn)

    function vnd(n) {
        return '₫' + Number(n || 0).toLocaleString('vi-VN')
    }

    return {
        cashbackRate,
        cashbackOn,
        minWithdrawal,
        customerAuthEnabled,
        joinHref,
        joinLabel,
        missingOut,
        vnd,
    }
}
