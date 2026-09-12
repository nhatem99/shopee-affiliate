<script setup>
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import CashbackExplainer from '@/Components/CashbackExplainer.vue'
import { useCashback } from '@/composables/useCashback'

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

        <section class="px-4 pb-14">
            <div class="max-w-3xl mx-auto text-center">
                <Link href="/" class="btn-fire inline-block px-8 py-4 rounded-2xl no-underline">
                    Về trang chủ lấy mã →
                </Link>
            </div>
        </section>
    </AppLayout>
</template>
