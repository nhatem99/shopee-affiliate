<script setup>
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import CashbackExplainer from '@/Components/CashbackExplainer.vue'
import CashbackLeaderboard from '@/Components/CashbackLeaderboard.vue'
import MembershipTiers from '@/Components/MembershipTiers.vue'
import { useCashback } from '@/composables/useCashback'

defineProps({
    leaderboard: { type: Object, default: null },
    membershipTiers: { type: Object, default: null },
})

// Trang riêng cho phần giải thích hoàn tiền, dùng lại đúng component đang nằm ở trang chủ.
//
// Vì sao là TRANG THẬT chứ không phải cuộn tới khối ở trang chủ: thanh điều hướng dưới hiện ở
// mọi trang khách, nên bấm từ /blog hay /login cũng phải tới được; và có URL riêng thì dán
// thẳng vào bài đăng Facebook/Zalo được, thay vì bảo người ta "vào trang chủ rồi kéo xuống".
const { cashbackRate } = useCashback()
</script>

<template>
    <Head>
        <title>Hoàn tiền khi mua Shopee | tietkiemvi.com</title>
        <meta
            name="description"
            :content="`Mua Shopee qua tietkiemvi được chia lại ${cashbackRate}% khoản hoa hồng tiếp thị của đơn. Xem rõ khi nào được hoàn, khi nào không, và bao lâu tiền về ví.`"
        />
    </Head>
    <AppLayout>
        <!-- Mở đầu của trang. Trước đây trang này bắt đầu thẳng bằng hai cột ✓/✕ — tức trả lời
             trước khi khách kịp hỏi. Khách vào đây phần lớn từ một link dán trong Facebook/Zalo,
             chưa biết tụi mình là ai, nên ba dòng đầu phải đi đúng thứ tự người ta nghĩ:
             được gì → tiền ở đâu ra → có mất gì không.

             Route /hoan-tien đã abort 404 khi rate = 0, nên ở đây cashbackRate chắc chắn > 0 và
             câu "chia lại X%" không bao giờ rơi vào cảnh khoe con số 0. -->
        <section class="px-4 pt-8 pb-2">
            <div class="max-w-3xl mx-auto text-center">
                <span class="step-badge step-badge--emerald px-2.5 py-1 text-xs">HOÀN TIỀN</span>

                <h1 class="text-2xl md:text-3xl font-extrabold text-[var(--color-ink)] mt-3">
                    Mua Shopee như mọi khi, tiền quay lại ví bạn
                </h1>

                <!-- Con số là --color-money chứ không phải --color-accent: đây là TIỀN của khách.
                     Màu lửa để dành cho đúng một thứ trên màn hình — cái nút phải bấm. -->
                <p class="text-base md:text-lg text-[var(--color-ink)] leading-relaxed mt-3 max-w-xl mx-auto">
                    Đơn nào bạn đặt sau khi bấm mua từ link ở đây, tụi mình chia lại
                    <b class="num text-[var(--color-money)]">{{ cashbackRate }}%</b>
                    khoản hoa hồng tiếp thị của đơn đó vào ví bạn.
                </p>

                <p class="text-sm text-[var(--color-muted)] leading-relaxed mt-3 max-w-xl mx-auto">
                    Tiền ở đâu ra: Shopee trả hoa hồng tiếp thị cho tụi mình vì đơn của bạn — tụi mình
                    chia lại phần hoa hồng đó cho chính bạn. Bạn không trả thêm đồng nào, và mã giảm
                    giá của đơn vẫn giữ nguyên.
                </p>
            </div>
        </section>

        <CashbackExplainer />

        <!-- Bảng hạng đặt ngay sau phần giải thích: người đọc tới đây đã tin là có tiền thật,
             câu hỏi kế tiếp là "được bao nhiêu" — đúng thứ bảng này trả lời. -->
        <MembershipTiers v-if="membershipTiers" v-bind="membershipTiers" />

        <!-- Cùng bảng vàng với trang chủ: trang này là nơi bài đăng Facebook/Zalo trỏ tới, khách
             mới vào thẳng đây phải thấy được bằng chứng có người đang nhận tiền thật. -->
        <CashbackLeaderboard v-if="leaderboard" :leaderboard="leaderboard" />

        <section class="px-4 pb-14">
            <div class="max-w-3xl mx-auto text-center">
                <!-- Chiều cao đặt bằng min-h chứ không bằng py-*: .btn-fire đã tự giữ sàn 44px,
                     cộng thêm py-4 là hai nguồn cùng quyết định một kích thước. -->
                <Link
                    href="/"
                    class="btn-fire inline-flex items-center justify-center px-8 min-h-[52px] rounded-2xl no-underline"
                >
                    Về trang chủ lấy mã →
                </Link>
            </div>
        </section>
    </AppLayout>
</template>
