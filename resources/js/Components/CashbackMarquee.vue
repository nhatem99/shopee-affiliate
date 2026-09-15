<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useCashback } from '@/composables/useCashback'

/**
 * Thanh chữ chạy ngang dưới header, CHỈ hiện với khách chưa đăng nhập.
 *
 * Lý do tồn tại: khách vãng lai bấm mua thì đơn đó vĩnh viễn không quy về ai được
 * (ShortLinkController chỉ gắn sub_id khi có user đăng nhập) — mất tiền thật, và không cứu lại
 * được sau khi đơn đã phát sinh. `missingOut` của useCashback đúng là điều kiện đó:
 * cashbackOn && !isLoggedIn. Đăng nhập rồi thì thanh này tự biến mất, không còn gì để nhắc.
 *
 * Con số phần trăm lấy từ `cashbackRate` (Setting 'cashback_display_rate', fallback về
 * 'cashback_rate'). KHÔNG gõ cứng: một nguồn sự thật duy nhất, admin đổi ở /admin/settings là
 * mọi chỗ đổi theo — xem chú thích dài trong useCashback.js.
 *
 * Câu chữ bám đúng cách diễn đạt của cả trang: hoàn X% KHOẢN HOA HỒNG của đơn, không phải X%
 * giá trị đơn hàng. Đây là chỗ ít bối cảnh nhất trên giao diện nên càng không được phép nói tắt
 * thành "giảm X%".
 */
const { cashbackRate, missingOut, joinHref } = useCashback()

const messages = computed(() => [
    `🔥 Đăng nhập trước khi bấm mua để được hoàn ${cashbackRate.value}% hoa hồng của đơn`,
    '💸 Tiền hoàn vào ví trên web, rút về MoMo/ZaloPay khi đủ mức tối thiểu',
    '🎁 Đăng ký miễn phí, vẫn lấy mã giảm giá như thường',
])
</script>

<template>
    <!-- Bấm vào đâu trên thanh cũng ra trang đăng ký (hoặc /login khi admin tắt đăng ký —
         joinHref lo việc đó), vì cả thanh chỉ nói đúng một chuyện: hãy đăng nhập trước. -->
    <Link
        v-if="missingOut"
        :href="joinHref"
        class="marquee block border-b border-[rgba(var(--color-accent-rgb),0.25)] bg-gradient-to-r from-[rgba(var(--color-accent-rgb),0.12)] via-[rgba(var(--color-accent-rgb),0.06)] to-[rgba(var(--color-accent-rgb),0.12)] py-2 text-xs md:text-sm font-semibold text-[var(--color-accent-deep)] dark:text-[var(--color-accent)]"
    >
        <div class="marquee__track">
            <!-- Hai bản giống hệt nhau, chạy tới -50% là đúng hết một bản rồi lặp — mắt không
                 thấy điểm nối. Bản thứ hai chỉ để vá chỗ trống nên ẩn với trình đọc màn hình. -->
            <span class="marquee__group">
                <span v-for="(msg, i) in messages" :key="i" class="marquee__item">
                    {{ msg }}
                    <span class="marquee__dot" aria-hidden="true">•</span>
                </span>
            </span>
            <span class="marquee__group" aria-hidden="true">
                <span v-for="(msg, i) in messages" :key="`dup-${i}`" class="marquee__item">
                    {{ msg }}
                    <span class="marquee__dot">•</span>
                </span>
            </span>
        </div>
    </Link>
</template>
