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
        <CashbackExplainer />

        <!-- Bảng hạng đặt ngay sau phần giải thích: người đọc tới đây đã tin là có tiền thật,
             câu hỏi kế tiếp là "được bao nhiêu" — đúng thứ bảng này trả lời. -->
        <MembershipTiers v-if="membershipTiers" v-bind="membershipTiers" />

        <!-- Cùng bảng vàng với trang chủ: trang này là nơi bài đăng Facebook/Zalo trỏ tới, khách
             mới vào thẳng đây phải thấy được bằng chứng có người đang nhận tiền thật. -->
        <CashbackLeaderboard v-if="leaderboard" :leaderboard="leaderboard" />

        <section class="px-4 pb-14">
            <div class="max-w-3xl mx-auto text-center">
                <Link href="/" class="btn-fire inline-block px-8 py-4 rounded-2xl no-underline">
                    Về trang chủ lấy mã →
                </Link>
            </div>
        </section>
    </AppLayout>
</template>
