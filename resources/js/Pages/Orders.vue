<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusBadge from '@/Components/StatusBadge.vue'

// Nút "Hỏi về đơn này" chỉ hiện khi chat đang bật — tắt thì /ho-tro trả 404.
const page = usePage()
const chatEnabled = computed(() => page.props.settings?.supportChatEnabled ?? false)

defineProps({
    orders: Object,
    summary: Object,
    cashbackRate: { type: Number, default: 0 },
    // Phần thưởng hạng thành viên đã nằm SẴN trong cashbackRate ở trên. Gửi kèm để nói rõ ra,
    // vì nếu không thì tỉ lệ ở trang này cao hơn con số quảng bá ngoài trang chủ mà không ai
    // giải thích — khách sẽ nghĩ là nhầm lẫn chứ không nghĩ là mình được thêm.
    tierBonus: { type: Object, default: null },
})

function vnd(n) {
    return '₫' + Number(n || 0).toLocaleString('vi-VN')
}

// Bốn chặng của một đồng tiền hoàn, đúng thứ tự có thật trong OrderHistoryController::present().
// Chữ cắt ngắn ("Xác nhận" chứ không "Shopee xác nhận") để bốn nhãn xếp vừa một hàng ở 375px —
// câu đầy đủ vẫn còn nguyên trong nhãn trạng thái và trong `note` ngay bên dưới.
const STAGES = ['Đặt đơn', 'Xác nhận', 'Đối soát', 'Vào ví']

// Mỗi trạng thái phải trả lời đúng một câu hỏi: "tiền của đơn này đang ở đâu?".
// `step` = số chặng đã đi qua. Màu nằm trong StatusBadge, không chép tay ở đây nữa.
const statusMeta = {
    waiting: {
        label: 'Chờ Shopee xác nhận',
        step: 1,
        note: 'Đơn chưa sang trạng thái Hoàn thành bên Shopee. Tiền chỉ vào ví sau khi hết hạn đổi trả.',
    },
    reconciling: {
        label: 'Đang đối soát',
        step: 2,
        note: 'Đơn đã hoàn thành, bên mình đang đối chiếu với báo cáo Shopee. Tiền sẽ vào ví ở kỳ đối soát gần nhất.',
    },
    credited: {
        label: 'Đã cộng vào ví',
        step: 4,
        note: null,
    },
    paid: {
        label: 'Đã rút về ví của bạn',
        step: 4,
        note: null,
    },
    cancelled: {
        // Đơn huỷ không có chặng nào để đi tiếp — step: 0 để thanh tiến trình biến mất thay vì
        // vẽ một đoạn dở dang gợi ý là tiền vẫn đang trên đường về.
        label: 'Đơn đã huỷ',
        step: 0,
        note: 'Đơn bị huỷ nên không được hoàn tiền. Nếu trước đó đã cộng vào ví thì phần đó được trừ lại.',
    },
}
</script>

<template>
    <Head title="Đơn hàng của tôi" />
    <AppLayout>
        <div class="max-w-4xl mx-auto px-4 py-10">
            <h1 class="text-2xl font-extrabold text-[var(--color-ink)] mb-1">Đơn hàng của tôi</h1>
            <p class="text-sm text-[var(--color-muted)] mb-8">
                Từng đơn mua qua link của bạn và số tiền hoàn tương ứng.
            </p>

            <!-- Tổng quan: ba con số trả lời "đã có bao nhiêu, đang chờ bao nhiêu, rơi mất bao nhiêu".
                 Chỉ con số ĐÃ VÀO VÍ được ăn màu tiền; hai con số còn lại là chữ thường — tô màu cả ba
                 thì không còn phân biệt được cái nào đã là tiền thật. -->
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-8">
                <div class="card p-4">
                    <p class="text-xs text-[var(--color-muted)] mb-1">Đã cộng vào ví</p>
                    <p class="text-xl font-extrabold num text-[var(--color-money)]">{{ vnd(summary?.credited) }}</p>
                </div>
                <div class="card p-4">
                    <p class="text-xs text-[var(--color-muted)] mb-1">Đang chờ (dự kiến)</p>
                    <p class="text-xl font-extrabold num text-[var(--color-ink)]">
                        {{ summary?.pending_estimate === null ? '—' : vnd(summary?.pending_estimate) }}
                    </p>
                </div>
                <div class="card p-4 col-span-2 md:col-span-1">
                    <p class="text-xs text-[var(--color-muted)] mb-1">Đơn bị huỷ</p>
                    <p class="text-xl font-extrabold num text-[var(--color-ink)]">{{ summary?.cancelled_count ?? 0 }}</p>
                </div>
            </div>

            <div v-if="orders?.data?.length" class="space-y-4">
                <div v-for="o in orders.data" :key="o.order_id" class="card p-5">
                    <div class="flex gap-4 items-start">
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <StatusBadge :status="o.status" :label="statusMeta[o.status].label" />
                                <span v-if="o.ordered_at" class="text-xs text-[var(--color-muted)]">Đặt ngày {{ o.ordered_at }}</span>
                            </div>
                            <p class="font-semibold text-[var(--color-ink)] text-sm line-clamp-2">
                                {{ o.product_name || 'Đơn hàng Shopee' }}
                            </p>
                            <p class="text-xs text-[var(--color-muted)] mt-0.5">
                                <span v-if="o.shop_name">{{ o.shop_name }}</span>
                                <span v-if="o.shop_name && o.other_items"> · </span>
                                <span v-if="o.other_items">và {{ o.other_items }} sản phẩm khác</span>
                            </p>
                        </div>
                        <div class="flex-none text-right">
                            <p class="text-xs text-[var(--color-muted)] mb-0.5">
                                {{ o.is_estimate ? 'Dự kiến hoàn' : 'Được hoàn' }}
                            </p>
                            <!-- Tiền hoàn là TIỀN CỦA KHÁCH nên đi màu --color-money, không đi màu cam:
                                 cam để dành cho nút bấm. Đơn huỷ thì xám + gạch ngang, không tô đỏ —
                                 nhãn trạng thái ngay bên trái đã nói "Đơn đã huỷ" bằng màu đỏ rồi. -->
                            <p class="font-extrabold num"
                                :class="o.status === 'cancelled' ? 'text-[var(--color-muted)] line-through' : 'text-[var(--color-money)]'">
                                {{ o.amount === null ? '—' : vnd(o.amount) }}
                            </p>
                        </div>
                    </div>

                    <!-- Thanh chặng đường: câu hỏi duy nhất khách mang tới trang này là "đơn của tôi
                         đang tắc ở đâu". Bốn ô, ô đã qua tô màu tiền — nhìn một cái là biết còn mấy
                         chặng nữa, không phải đọc hết đoạn chú thích bên dưới. -->
                    <div v-if="statusMeta[o.status].step > 0" class="mt-3">
                        <div
                            class="flex gap-1"
                            role="img"
                            :aria-label="`Chặng ${statusMeta[o.status].step} trên 4: ${STAGES[statusMeta[o.status].step - 1]}`"
                        >
                            <span
                                v-for="(s, i) in STAGES"
                                :key="s"
                                class="h-1.5 flex-1 rounded-full"
                                :class="i < statusMeta[o.status].step ? 'bg-[var(--color-money)]' : 'bg-[var(--color-line)]'"
                            ></span>
                        </div>
                        <div class="flex gap-1 mt-1" aria-hidden="true">
                            <span
                                v-for="(s, i) in STAGES"
                                :key="s"
                                class="flex-1 text-xs text-center leading-tight"
                                :class="i < statusMeta[o.status].step ? 'text-[var(--color-ink)] font-semibold' : 'text-[var(--color-muted)]'"
                            >{{ s }}</span>
                        </div>
                    </div>

                    <p v-if="statusMeta[o.status].note" class="text-xs text-[var(--color-muted)] mt-3 pt-3 border-t border-[var(--color-line)]">
                        {{ statusMeta[o.status].note }}
                    </p>

                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-[var(--color-muted)] num">Mã đơn Shopee: {{ o.order_id }}</p>
                        <!-- Mang sẵn mã đơn sang khung chat: khách khỏi phải copy, admin khỏi
                             phải hỏi lại "đơn nào bạn ơi" — xem ChatController::orderContext.
                             Màu trung tính chứ không cam: link này lặp lại ở MỌI thẻ đơn, để cam
                             thì cả trang sáng rực lên vì một lối phụ. -->
                        <Link
                            v-if="chatEnabled"
                            :href="`/ho-tro?don=${encodeURIComponent(o.order_id)}`"
                            class="focus-ring inline-flex items-center min-h-[44px] px-2 -mr-2 rounded-xl text-xs font-semibold text-[var(--color-info)] hover:underline"
                        >💬 Hỏi về đơn này</Link>
                    </div>
                </div>

                <!-- Phân trang -->
                <div class="flex justify-center gap-2 mt-8">
                    <Link v-if="orders.prev_page_url" :href="orders.prev_page_url"
                        class="focus-ring inline-flex items-center min-h-[44px] px-4 rounded-xl border border-[var(--color-line)] text-sm font-semibold text-[var(--color-ink)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent-deep)] transition">
                        ← Trước
                    </Link>
                    <Link v-if="orders.next_page_url" :href="orders.next_page_url"
                        class="focus-ring inline-flex items-center min-h-[44px] px-4 rounded-xl border border-[var(--color-line)] text-sm font-semibold text-[var(--color-ink)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent-deep)] transition">
                        Tiếp →
                    </Link>
                </div>
            </div>

            <!-- Chưa có đơn nào: nói rõ điều kiện để đơn được ghi nhận, vì đây đúng là chỗ khách
                 vào tìm câu trả lời "mua rồi mà sao không thấy gì". -->
            <div v-else class="card p-6 text-center">
                <p class="text-4xl mb-3">🧾</p>
                <p class="font-bold text-[var(--color-ink)] mb-1">Chưa ghi nhận đơn nào</p>
                <p class="text-sm text-[var(--color-muted)] max-w-md mx-auto">
                    Đơn chỉ được ghi nhận khi bạn <b>đang đăng nhập</b> lúc lấy mã và bấm mua qua link ở đây.
                    Đơn mới đặt cũng cần vài ngày để hiện, vì bên mình đối soát theo báo cáo Shopee.
                </p>
                <Link href="/" class="btn-fire focus-ring mt-5 inline-flex items-center px-6 rounded-xl no-underline">
                    Về trang chủ lấy mã →
                </Link>
            </div>

            <p v-if="cashbackRate > 0" class="text-xs text-[var(--color-muted)] mt-6 text-center">
                Số tiền hoàn được tính trên hoa hồng thực nhận của đơn, theo tỉ lệ {{ cashbackRate }}%<!--
                --><template v-if="tierBonus">, đã gồm <b class="text-[var(--color-ink)]">+{{ tierBonus.bonus }}%</b> đặc quyền hạng {{ tierBonus.label }}</template>.
                Số "dự kiến" có thể đổi khi Shopee chốt lại hoa hồng cuối cùng.
            </p>

            <div class="text-center mt-6">
                <Link href="/profile" class="focus-ring inline-flex items-center min-h-[44px] px-3 rounded-xl text-sm font-semibold text-[var(--color-accent-deep)] hover:underline">
                    Xem ví và rút tiền →
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
