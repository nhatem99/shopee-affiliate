<script setup>
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useCashback } from '@/composables/useCashback'

/**
 * Chân trang — trước đây KHÔNG tồn tại ở bất kỳ trang khách nào (grep '<footer' trên toàn
 * resources/js trả về rỗng, 0/20 trang).
 *
 * Với nhóm khách này đó là lỗ hổng niềm tin lớn nhất của khung trang: họ đã nghe về hàng loạt
 * "web hoàn tiền" lừa đảo, mà một trang cuộn tới đáy rồi hết sạch, không nói ai vận hành, tiền
 * ở đâu ra, liên hệ chỗ nào — thì trông đúng như mấy trang đó.
 *
 * Nguyên tắc cứng: CHỈ liên kết tới trang CÓ THẬT trong routes/web.php. Trang này chưa có điều
 * khoản/chính sách bảo mật, nên footer KHÔNG được bịa ra hai link đó — link chết ở chân trang
 * còn phá niềm tin mạnh hơn là không có link.
 */
const { cashbackRate, cashbackOn } = useCashback()

const nam = new Date().getFullYear()

const lienKet = computed(() => [
    { href: '/ma-giam-gia', label: 'Mã giảm giá' },
    { href: '/flashsale', label: 'Flash Sale' },
    ...(cashbackOn.value ? [{ href: '/hoan-tien', label: 'Hoàn tiền' }] : []),
    { href: '/huong-dan', label: 'Hướng dẫn' },
    { href: '/blog', label: 'Bài viết' },
    { href: '/ho-tro', label: 'Hỗ trợ' },
])
</script>

<template>
    <footer class="mt-auto border-t border-[var(--color-line)] px-4 pt-8 pb-24 md:pb-10">
        <div class="max-w-5xl mx-auto">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-6">
                <div class="max-w-sm">
                    <img src="/logo.png" alt="tietkiemvi" class="h-8 w-auto mb-3">
                    <!-- Nói thẳng nguồn tiền. Câu hỏi thầm của mọi khách mới là "sao tự nhiên
                         cho tiền?" — không trả lời thì họ tự trả lời bằng giả thiết xấu nhất. -->
                    <p class="text-sm text-[var(--color-muted)]">
                        <template v-if="cashbackOn">
                            Shopee trả hoa hồng tiếp thị cho mỗi đơn đi qua link của tụi mình.
                            Tụi mình chia lại <b class="text-[var(--color-money)]">{{ cashbackRate }}%</b>
                            khoản đó vào ví của bạn — đó là toàn bộ mô hình, không có phí ẩn nào.
                        </template>
                        <template v-else>
                            Dán link sản phẩm Shopee, nhận lại link đã áp sẵn mã giảm giá còn hiệu lực.
                            Miễn phí, không cần tài khoản.
                        </template>
                    </p>
                </div>

                <nav class="grid grid-cols-2 gap-x-8 gap-y-1" aria-label="Liên kết chân trang">
                    <Link
                        v-for="l in lienKet"
                        :key="l.href"
                        :href="l.href"
                        class="focus-ring text-sm text-[var(--color-muted)] hover:text-[var(--color-ink)] transition-colors py-1.5"
                    >{{ l.label }}</Link>
                </nav>
            </div>

            <div class="mt-6 pt-5 border-t border-[var(--color-line)] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <p class="text-xs text-[var(--color-muted)]">© {{ nam }} tietkiemvi.com</p>
                <!-- Nói rõ mình KHÔNG phải Shopee. Vừa là chuyện trung thực, vừa chặn trước hiểu
                     nhầm mà bộ phận hỗ trợ đang phải trả lời bằng tay. -->
                <p class="text-xs text-[var(--color-muted)]">
                    Trang độc lập, không phải website chính thức của Shopee.
                </p>
            </div>
        </div>
    </footer>
</template>
