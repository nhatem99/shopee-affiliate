<script setup>
import { Link } from '@inertiajs/vue3'
import { useAuthStore } from '@/Stores/useAuthStore'
import { useCashback } from '@/composables/useCashback'

// Khối giải thích hoàn tiền cho trang chủ.
//
// Vì sao viết thẳng cả cột "KHÔNG được hoàn": khách Việt đã gặp quá nhiều trang hứa hoàn tiền
// rồi im lặng, nên vào đây mang sẵn nghi ngờ. Liệt kê đủ 4 trường hợp mình KHÔNG trả tiền là
// thứ duy nhất làm cho cột "được hoàn" bên cạnh trở nên đáng tin — và cũng là thứ chặn trước
// khiếu nại "mua rồi mà ví vẫn 0đ".
//
// Mọi con số đều lấy động từ server (xem useCashback): tỉ lệ hoàn do admin đặt và có thể đổi
// bất cứ lúc nào, mức rút tối thiểu nằm trong PHP. Gõ cứng ở đây là sớm muộn cũng nói dối.
// `compact` = bản dùng ở TRANG CHỦ: giữ hai cột ✓/✕ (thứ thuyết phục nhất) và bỏ phần thời
// gian + rút tiền, vốn là chi tiết người ta chỉ đọc khi đã quan tâm. Bản đầy đủ nằm ở
// /hoan-tien. Một component hai chế độ chứ không phải hai bản nội dung — chép ra hai nơi thì
// sớm muộn cũng lệch, mà lệch ở đây nghĩa là hai trang nói hai điều khác nhau về tiền.
defineProps({
    compact: { type: Boolean, default: false },
})

const auth = useAuthStore()
const { cashbackRate, minWithdrawal, joinHref, joinLabel, vnd } = useCashback()
</script>

<template>
    <section id="cashback" class="py-14 px-4 bg-[var(--color-bg)] scroll-mt-20">
        <div class="max-w-3xl mx-auto">
            <div class="text-center mb-8">
                <span class="step-badge step-badge--emerald px-2.5 py-1 text-xs">MỚI</span>
                <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-ink)] mt-3 mb-3">
                    Mua qua tietkiemvi, tiền quay lại ví bạn
                </h2>
                <!-- Câu nguồn tiền. Đặt ngay đầu khối vì đây là câu trả lời cho ý nghĩ đầu tiên
                     của khách ("tiền ở đâu ra mà cho không?") — chưa trả lời xong câu này thì
                     mọi lời mời phía dưới đều bị nghe thành lừa đảo. -->
                <p class="text-[var(--color-muted)] text-sm leading-relaxed max-w-xl mx-auto">
                    Shopee trả hoa hồng tiếp thị cho tụi mình vì đơn của bạn. Tụi mình chia lại
                    <b class="text-[var(--color-brand-green)]">{{ cashbackRate }}% khoản hoa hồng đó</b>
                    cho chính bạn — cộng thêm vào phần mã giảm giá bạn đã được, chứ không thay thế nó.
                </p>
            </div>

            <div class="grid md:grid-cols-2 gap-4 mb-6">
                <!-- Cột ĐƯỢC -->
                <div class="rounded-2xl p-5 bg-[var(--color-green-soft)] border border-[var(--color-brand-green)]/25">
                    <p class="font-extrabold text-[var(--color-brand-green)] text-sm mb-3">✓ ĐƯỢC HOÀN khi</p>
                    <ul class="space-y-2.5 text-sm text-[var(--color-ink)] leading-relaxed">
                        <li class="flex gap-2">
                            <span class="text-[var(--color-brand-green)] flex-none">✓</span>
                            <span>Bạn <b>đăng nhập trước</b> rồi mới bấm nút mua ở đầu trang này</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-[var(--color-brand-green)] flex-none">✓</span>
                            <span>Bạn đi thẳng từ link của tụi mình sang Shopee và đặt hàng ở đó</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-[var(--color-brand-green)] flex-none">✓</span>
                            <span>Đơn chuyển sang trạng thái <b>Hoàn thành</b> trên Shopee</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-[var(--color-brand-green)] flex-none">✓</span>
                            <span>Shopee thực sự trả hoa hồng cho đơn đó — vài ngành hàng hoa hồng bằng 0 thì không có gì để chia</span>
                        </li>
                    </ul>
                    <!-- Nói luôn rằng danh sách này chưa phải là tất cả. Liệt kê 3-4 gạch đầu dòng
                         rồi im lặng sẽ bị đọc thành "đủ điều kiện này là chắc chắn có tiền", và
                         đúng một trường hợp rơi ra ngoài là mất sạch niềm tin vừa xây. -->
                    <p class="text-xs text-[var(--color-muted)] mt-3 leading-relaxed">
                        Kỹ thuật cũng có lúc trục trặc (nguồn cấp mã đổi đường dẫn, chuỗi chuyển hướng gãy).
                        Hiếm, nhưng có — gặp thì nhắn tụi mình kèm mã đơn để đối chiếu.
                    </p>
                </div>

                <!-- Cột KHÔNG ĐƯỢC -->
                <div class="rounded-2xl p-5 bg-[var(--color-peach-soft)] border border-[var(--color-accent)]/25">
                    <p class="font-extrabold text-[var(--color-accent-deep)] text-sm mb-3">✕ KHÔNG ĐƯỢC HOÀN khi</p>
                    <ul class="space-y-2.5 text-sm text-[var(--color-ink)] leading-relaxed">
                        <li class="flex gap-2">
                            <span class="text-[var(--color-accent)] flex-none">✕</span>
                            <span>Bấm mua lúc <b>chưa đăng nhập</b> — đơn đó không quy về ai được, và sau này không cứu lại được</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-[var(--color-accent)] flex-none">✕</span>
                            <span>Tự mở app Shopee tìm lại sản phẩm rồi đặt (mã giảm giá cũng mất luôn)</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-[var(--color-accent)] flex-none">✕</span>
                            <span>Đơn bị huỷ hoặc trả hàng — khoản đã ghi sẽ bị trừ lại</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-[var(--color-accent)] flex-none">✕</span>
                            <span>Đơn còn đang giao, chưa sang <b>Hoàn thành</b></span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Khối thời gian. Câu từ chối hứa hẹn nằm ở đây là có chủ đích: đối thủ đều hứa
                 "về ví trong 24h", nên nói thẳng mình KHÔNG hứa vậy vừa đúng sự thật (hệ thống
                 đối soát bằng báo cáo tải tay, không có SLA nào cả) vừa là điểm khác biệt. -->
            <div v-if="!compact" class="card-glass rounded-2xl p-5 mb-4">
                <p class="font-bold text-[var(--color-ink)] text-sm mb-2">⏳ Bao lâu thì tiền về ví?</p>
                <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                    Thật lòng: không nhanh. Đơn phải hoàn thành bên Shopee trước đã — tức qua hết hạn đổi trả —
                    rồi tụi mình đối soát theo báo cáo Shopee mới ghi tiền vào ví bạn. Thường mất vài tuần kể từ ngày đặt.
                </p>
                <p class="text-sm text-[var(--color-ink)] leading-relaxed mt-2">
                    Tụi mình <b>không hứa 24h</b>, không hứa "tự động về ví ngay sau khi nhận hàng".
                    Trang nào hứa vậy thì bạn nên nghi ngờ.
                </p>
            </div>

            <div v-if="!compact" class="card-glass rounded-2xl p-5 mb-6">
                <p class="font-bold text-[var(--color-ink)] text-sm mb-2">💸 Rút tiền thế nào?</p>
                <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                    Tiền hoàn vào số dư của bạn trên web trước. Khi số dư đủ mức tối thiểu<!--
                    --><template v-if="minWithdrawal > 0"> ({{ vnd(minWithdrawal) }})</template>,
                    bạn gửi yêu cầu rút về ví <b class="text-[var(--color-ink)]">MoMo</b> hoặc
                    <b class="text-[var(--color-ink)]">ZaloPay</b> đã khai sẵn. Bên mình duyệt rồi chuyển tay —
                    không phải tự động, nên bạn chờ một chút nhé.
                </p>
            </div>

            <div class="text-center" :class="compact ? 'mt-6' : ''">
                <Link
                    v-if="!auth.isLoggedIn"
                    :href="joinHref"
                    class="btn-fire inline-block px-8 py-4 rounded-2xl no-underline"
                >{{ joinLabel }}</Link>
                <Link
                    v-else
                    href="/profile"
                    class="btn-fire inline-block px-8 py-4 rounded-2xl no-underline"
                >Xem ví của tôi →</Link>
                <p v-if="!auth.isLoggedIn" class="text-xs text-[var(--color-muted)] mt-3">
                    Miễn phí. Đăng nhập xong quay lại dán link như bình thường.
                </p>

                <!-- Đường dẫn tới bản đầy đủ. Nêu thẳng hai câu hỏi người ta hay thắc mắc nhất
                     thay vì "Xem thêm" chung chung — người đang phân vân chỉ bấm khi biết bấm
                     vào sẽ được trả lời đúng thứ mình đang lăn tăn. -->
                <Link
                    v-if="compact"
                    href="/hoan-tien"
                    class="inline-block mt-4 text-sm font-semibold text-[var(--color-accent)] hover:underline"
                >Bao lâu tiền về ví? Rút thế nào? → Xem chi tiết</Link>
            </div>
        </div>
    </section>
</template>
