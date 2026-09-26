<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useAuthStore } from '@/Stores/useAuthStore'
import { useCashback } from '@/composables/useCashback'

const page = usePage()
const auth = useAuthStore()
const current = computed(() => page.url)
const { cashbackOn } = useCashback()

const items = computed(() => [
    ...(auth.isAdmin ? [] : [
        { href: '/', icon: 'home', label: 'Trang chủ' },
        // Trang mã đứng ngay cạnh Trang chủ: đây là lối vào KHÔNG cần dán link sản phẩm,
        // dùng được cả với khách vãng lai lẫn khách chưa có sẵn thứ muốn mua.
        { href: '/ma-giam-gia', icon: 'ticket', label: 'Mã giảm giá' },
        { href: '/flashsale', icon: 'bolt', label: 'Flash Sale' },
        // Khách vãng lai trước đây chỉ sinh đúng MỘT mục, chiếm nguyên chiều ngang trông như
        // thanh bị lỗi. Mục này vừa lấp chỗ đó vừa đưa đúng nhóm cần đọc nhất tới phần giải
        // thích — họ là người sẽ mất tiền nếu bấm mua mà chưa đăng nhập.
        // Khách đã đăng nhập không cần: họ đã có Lịch sử + Tài khoản, và số dư nói rõ hơn.
        ...(cashbackOn.value && !auth.isLoggedIn ? [
            { href: '/hoan-tien', icon: 'wallet', label: 'Hoàn tiền' },
        ] : []),
        ...(auth.isLoggedIn ? [
            { href: '/history', icon: 'list', label: 'Lịch sử' },
            // Chỗ khách đi tìm câu trả lời "mua rồi, tiền đâu" — để cạnh ví chứ không giấu
            // trong trang Tài khoản.
            ...(cashbackOn.value ? [{ href: '/don-hang', icon: 'receipt', label: 'Đơn hàng' }] : []),
            { href: '/profile', icon: 'user', label: 'Tài khoản' },
        ] : []),
    ]),
])

/**
 * Icon vẽ bằng SVG stroke thay cho emoji.
 *
 * Emoji (⌂ 🎟️ ⚡ 💰 📋 🧾 👤) trông KHÁC NHAU trên mỗi máy — Android vẽ một kiểu, iOS một kiểu,
 * và ⌂ thì nhiều font còn không có nên rơi về ô vuông rỗng. Chúng cũng không đổi màu theo trạng
 * thái đang đứng được, nên mục đang chọn phải dựa hoàn toàn vào màu chữ bên dưới.
 *
 * Dùng path 24×24 stroke để mọi máy hiện giống nhau, tự ăn theo currentColor, và không tốn thêm
 * một request nào (không thư viện icon, không font icon).
 */
const PATHS = {
    home: 'M3 10.5 12 3l9 7.5M5.5 9.5V20a1 1 0 0 0 1 1H9.5v-5.5h5V21h3a1 1 0 0 0 1-1V9.5',
    ticket: 'M4 8.5A1.5 1.5 0 0 1 5.5 7h13A1.5 1.5 0 0 1 20 8.5v2a2 2 0 0 0 0 3.8v2.2a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 16.5v-2.2a2 2 0 0 0 0-3.8ZM14 7v12',
    bolt: 'M13 2 4.5 13.5H11L10 22l8.5-11.5H12L13 2Z',
    wallet: 'M3 8.5A2.5 2.5 0 0 1 5.5 6H18a2 2 0 0 1 2 2v1.5M3 8.5V17a2 2 0 0 0 2 2h13a2 2 0 0 0 2-2v-2.5M3 8.5h15M21 10.5h-4a2 2 0 0 0 0 4h4v-4Z',
    list: 'M4 6h16M4 12h16M4 18h10',
    receipt: 'M5 3.5v17l2.5-1.5L10 20.5l2-1.5 2 1.5 2.5-1.5L19 20.5v-17L16.5 5 14 3.5 12 5l-2-1.5L7.5 5 5 3.5ZM9 9.5h6M9 13.5h6',
    user: 'M12 12.5a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4.5 20.5a7.5 7.5 0 0 1 15 0',
}
</script>

<template>
    <!--
        safe-bottom: lớp chừa chỗ cho vạch home của iPhone. Trước đây chỗ này ghi
        `safe-area-inset-bottom` — một class KHÔNG TỒN TẠI ở bất cứ đâu trong dự án (không có
        tailwind.config.js, không có trong app.css), tức thanh nav vẫn nằm đè lên vạch home suốt
        thời gian qua trong khi code trông như đã xử lý.
    -->
    <nav
        v-if="items.length"
        class="md:hidden fixed bottom-0 left-0 right-0 z-50 flex safe-bottom bg-[var(--color-surface)]/95 backdrop-blur-md border-t border-[var(--color-line)]"
    >
        <Link
            v-for="item in items"
            :key="item.href"
            :href="item.href"
            :aria-current="current === item.href ? 'page' : undefined"
            class="focus-ring relative flex-1 min-w-0 flex flex-col items-center justify-center gap-1 px-1 pt-2.5 pb-1.5 text-xs font-semibold transition-colors"
            :class="current === item.href ? 'text-[var(--color-accent-deep)]' : 'text-[var(--color-muted)]'"
        >
            <!--
                Mục đang đứng nhận một vạch 2px ở mép trên thay cho viên nền bo tròn + hào quang
                phát sáng. Vạch đọc được ở cả hai chế độ và không cần màu nền thứ hai; hào quang
                thì chỉ thấy ở chế độ tối và làm thanh nav trông như đang quảng cáo.
            -->
            <span
                v-if="current === item.href"
                class="absolute top-0 left-1/2 -translate-x-1/2 h-0.5 w-8 rounded-full bg-[var(--color-accent)]"
                aria-hidden="true"
            ></span>

            <svg class="w-[22px] h-[22px] flex-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path :d="PATHS[item.icon]" />
            </svg>

            <!-- whitespace-nowrap: có tới 6 mục khi khách vừa đăng nhập vừa bật hoàn tiền
                 (Trang chủ/Mã giảm giá/Flash Sale/Lịch sử/Đơn hàng/Tài khoản) — "Mã giảm giá"
                 từng vỡ xuống 2 dòng ở đó, đẩy lệch icon so với các mục còn lại.
                 Cỡ chữ là text-xs (12px) chứ không phải 10.5px như trước: tiếng Việt có dấu
                 chồng, dưới 12px là bóp nghẹt phần dấu — và đây là nhãn điều hướng chính của
                 100% khách dùng điện thoại. -->
            <span class="whitespace-nowrap leading-none">{{ item.label }}</span>
        </Link>
    </nav>
</template>
