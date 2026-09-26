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
//
// Đệm trên/dưới của section cũng đi theo `compact`: bản đầy đủ nằm ngay dưới phần mở đầu của
// /hoan-tien nên py-14 ở mép trên làm hai khối cách nhau 56px, trông như hai trang rời. Bản
// compact đứng giữa các khối khác ở trang chủ nên giữ nguyên nhịp cũ. (Ghi ở đây chứ không phải
// một comment ngay trên <section>: comment đứng ở GỐC template biến component thành fragment ở
// bản dev, và fragment thì mọi class truyền từ ngoài vào sẽ rơi mất.)
defineProps({
    compact: { type: Boolean, default: false },
})

const auth = useAuthStore()
const { cashbackRate, minWithdrawal, joinHref, joinLabel, vnd } = useCashback()
</script>

<template>
    <section
        id="cashback"
        class="px-4 bg-[var(--color-bg)] scroll-mt-20"
        :class="compact ? 'py-14' : 'pt-6 pb-10'"
    >
        <div class="max-w-3xl mx-auto">
            <div class="text-center mb-8">
                <!-- Ở TRANG CHỦ đây là lần đầu khách nghe tới hoàn tiền nên khối phải tự giới
                     thiệu đủ: tiêu đề lớn + câu nguồn tiền. Ở /hoan-tien thì phần mở đầu của
                     trang vừa nói xong đúng hai điều đó cách đây 200px — nhắc lại là chữ thừa,
                     nên bản đầy đủ chỉ giữ một tiêu đề mục đúng việc nó làm. -->
                <template v-if="compact">
                    <span class="step-badge step-badge--emerald px-2.5 py-1 text-xs">MỚI</span>
                    <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-ink)] mt-3 mb-3">
                        Mua qua tietkiemvi, tiền quay lại ví bạn
                    </h2>
                    <!-- Câu nguồn tiền. Đặt ngay đầu khối vì đây là câu trả lời cho ý nghĩ đầu
                         tiên của khách ("tiền ở đâu ra mà cho không?") — chưa trả lời xong câu
                         này thì mọi lời mời phía dưới đều bị nghe thành lừa đảo. -->
                    <p class="text-[var(--color-muted)] text-sm leading-relaxed max-w-xl mx-auto">
                        Shopee trả hoa hồng tiếp thị cho tụi mình vì đơn của bạn. Tụi mình chia lại
                        <b class="num text-[var(--color-money)]">{{ cashbackRate }}% khoản hoa hồng đó</b>
                        cho chính bạn — cộng thêm vào phần mã giảm giá bạn đã được, chứ không thay thế nó.
                    </p>
                </template>
                <template v-else>
                    <h2 class="text-xl md:text-2xl font-extrabold text-[var(--color-ink)] mb-2">
                        Khi nào được hoàn, khi nào không
                    </h2>
                    <!-- Không viết "cột bên phải": trên điện thoại hai cột xếp chồng lên nhau,
                         mà gần như 100% khách đọc trang này bằng điện thoại. -->
                    <p class="text-[var(--color-muted)] text-sm leading-relaxed max-w-xl mx-auto">
                        Đọc hết phần <b class="text-[var(--color-ink)]">KHÔNG ĐƯỢC HOÀN</b> trước khi
                        bấm mua — đó là những lý do phổ biến nhất khiến đơn đã mua mà ví vẫn đứng yên.
                    </p>
                </template>
            </div>

            <div class="grid md:grid-cols-2 gap-4 mb-6">
                <!-- Cột ĐƯỢC.
                     Màu đổi từ --color-brand-green sang --color-money: biến brand-green chỉ đạt
                     3.41:1 trên nền sáng nên KHÔNG được làm chữ ở chế độ sáng (xem app.css), mà
                     ở đây nó đang làm chữ cho cả tiêu đề cột lẫn 4 dấu tích. --color-money là
                     đúng vai luôn: mấy dòng này nói về tiền khách nhận được. -->
                <div class="rounded-2xl p-5 bg-[var(--color-money-soft)] border border-[var(--color-money)]/25">
                    <p class="font-extrabold text-[var(--color-money)] text-sm mb-3">✓ ĐƯỢC HOÀN khi</p>
                    <ul class="space-y-2.5 text-sm text-[var(--color-ink)] leading-relaxed">
                        <li class="flex gap-2">
                            <span class="text-[var(--color-money)] flex-none" aria-hidden="true">✓</span>
                            <span>Bạn <b>đăng nhập trước</b> rồi mới bấm nút mua ở đầu trang này</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-[var(--color-money)] flex-none" aria-hidden="true">✓</span>
                            <span>Bạn đi thẳng từ link của tụi mình sang Shopee và đặt hàng ở đó</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-[var(--color-money)] flex-none" aria-hidden="true">✓</span>
                            <span>Đơn chuyển sang trạng thái <b>Hoàn thành</b> trên Shopee</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-[var(--color-money)] flex-none" aria-hidden="true">✓</span>
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

                <!-- Cột KHÔNG ĐƯỢC.
                     Đổi từ màu lửa sang --color-danger vì hai lẽ. Một: màu lửa là màu của NÚT
                     BẤM, dùng nó cho cả danh sách cảnh báo thì cái nút phía dưới mất hết sức
                     nặng. Hai: nền peach-soft của cột này giống hệt nền các khối khuyến mãi
                     trên trang, nên cột đang đọc ra như một lời mời chứ không phải lời cảnh báo.
                     Đỏ là màu duy nhất khách hiểu ngay là "mất tiền". -->
                <div class="rounded-2xl p-5 bg-[var(--color-danger-soft)] border border-[var(--color-danger)]/25">
                    <p class="font-extrabold text-[var(--color-danger)] text-sm mb-3">✕ KHÔNG ĐƯỢC HOÀN khi</p>
                    <ul class="space-y-2.5 text-sm text-[var(--color-ink)] leading-relaxed">
                        <li class="flex gap-2">
                            <span class="text-[var(--color-danger)] flex-none" aria-hidden="true">✕</span>
                            <span>Bấm mua lúc <b>chưa đăng nhập</b> — đơn đó không quy về ai được, và sau này không cứu lại được</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-[var(--color-danger)] flex-none" aria-hidden="true">✕</span>
                            <span>Tự mở app Shopee tìm lại sản phẩm rồi đặt (mã giảm giá cũng mất luôn)</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-[var(--color-danger)] flex-none" aria-hidden="true">✕</span>
                            <span>Đơn bị huỷ hoặc trả hàng — khoản đã ghi sẽ bị trừ lại</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-[var(--color-danger)] flex-none" aria-hidden="true">✕</span>
                            <span>Đơn còn đang giao, chưa sang <b>Hoàn thành</b></span>
                        </li>

                        <!-- Bốn ca dưới đây CHỈ hiện ở bản đầy đủ (/hoan-tien), không hiện ở
                             trang chủ: bản compact nằm ngay trên màn hình điện thoại nơi khách
                             đang muốn dán link, dài thêm bốn dòng là đẩy công cụ ra khỏi tầm mắt.
                             Nhưng vẫn phải viết ra ở đâu đó — đây đều là ca có thật, khách mất
                             tiền rồi mới biết thì tụi mình chỉ còn cách xin lỗi. -->
                        <template v-if="!compact">
                            <li class="flex gap-2">
                                <span class="text-[var(--color-danger)] flex-none" aria-hidden="true">✕</span>
                                <span><b>Đặt hàng xong rồi</b> mới quay lại đây lấy link — không gắn ngược lại được cho đơn đã đặt</span>
                            </li>
                            <li class="flex gap-2">
                                <span class="text-[var(--color-danger)] flex-none" aria-hidden="true">✕</span>
                                <span>Bấm thêm link của bên khác (quảng cáo, nhóm săn sale) <b>sau khi</b> bấm mua ở đây — lượt sau đè lượt trước</span>
                            </li>
                            <li class="flex gap-2">
                                <span class="text-[var(--color-danger)] flex-none" aria-hidden="true">✕</span>
                                <span>Đơn đặt từ <b>LiveStream hoặc video</b> trong app Shopee</span>
                            </li>
                            <li class="flex gap-2">
                                <span class="text-[var(--color-danger)] flex-none" aria-hidden="true">✕</span>
                                <span>Đổi máy, đổi trình duyệt hoặc đổi tài khoản Shopee giữa chừng</span>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>

            <!-- Khối thời gian. Nói thẳng là không nhanh để quản lý kỳ vọng (hệ thống đối soát
                 bằng báo cáo tải tay, không có SLA nào cả), nhưng diễn đạt theo hướng "chậm vì
                 chắc" thay vì đá xoáy đối thủ. -->
            <!-- .card thay cho "card-glass rounded-2xl": card-glass là thẻ có viền sáng lên khi
                 rê chuột, dành cho thứ bấm được — hai khối chữ này không bấm được, và bo góc của
                 chúng trước giờ phải gõ tay nên là một trong những chỗ bo góc dễ trôi nhất. -->
            <div v-if="!compact" class="card p-5 mb-4">
                <p class="font-bold text-[var(--color-ink)] text-sm mb-2">⏳ Bao lâu thì tiền về ví?</p>
                <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                    Thật lòng: không nhanh. Đơn phải hoàn thành bên Shopee trước đã — tức qua hết hạn đổi trả —
                    rồi tụi mình đối soát theo báo cáo Shopee mới ghi tiền vào ví bạn. Thường mất vài tuần kể từ ngày đặt.
                </p>
                <p class="text-sm text-[var(--color-ink)] leading-relaxed mt-2">
                    Chậm hơn một chút nhưng <b>chắc</b>: tiền chỉ ghi vào ví khi Shopee đã xác nhận
                    hoa hồng cho đơn đó, nên khoản nào đã hiện trong ví là khoản bạn thật sự nhận được.
                </p>
            </div>

            <div v-if="!compact" class="card p-5 mb-6">
                <p class="font-bold text-[var(--color-ink)] text-sm mb-2">💸 Rút tiền thế nào?</p>
                <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                    Tiền hoàn vào số dư của bạn trên web trước. Khi số dư đủ mức tối thiểu<!--
                    --><template v-if="minWithdrawal > 0"> (<span class="num">{{ vnd(minWithdrawal) }}</span>)</template>,
                    bạn gửi yêu cầu rút về ví <b class="text-[var(--color-ink)]">MoMo</b> hoặc
                    <b class="text-[var(--color-ink)]">ZaloPay</b> đã khai sẵn. Bên mình duyệt rồi chuyển tay —
                    không phải tự động, nên bạn chờ một chút nhé.
                </p>
            </div>

            <div class="text-center" :class="compact ? 'mt-6' : ''">
                <!-- Chiều cao bằng min-h: .btn-fire đã có sàn 44px sẵn, py-4 chồng lên là hai
                     nguồn cùng quyết một kích thước. -->
                <Link
                    v-if="!auth.isLoggedIn"
                    :href="joinHref"
                    class="btn-fire inline-flex items-center justify-center px-8 min-h-[52px] rounded-2xl no-underline"
                >{{ joinLabel }}</Link>
                <Link
                    v-else
                    href="/profile"
                    class="btn-fire inline-flex items-center justify-center px-8 min-h-[52px] rounded-2xl no-underline"
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
                    class="focus-ring inline-flex items-center justify-center mt-2 px-3 min-h-[44px] rounded-xl text-sm font-semibold text-[var(--color-accent-deep)] hover:underline"
                >Bao lâu tiền về ví? Rút thế nào? → Xem chi tiết</Link>
            </div>
        </div>
    </section>
</template>
