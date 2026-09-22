<script setup>
import { computed, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useAuthStore } from '@/Stores/useAuthStore'
import { useCashback } from '@/composables/useCashback'

/**
 * Trang hướng dẫn lấy mã, có video.
 *
 * Phần CHỮ mới là nội dung chính, video là thứ đi kèm: admin có thể chưa đặt video, khách có thể
 * đang ở chỗ mạng yếu hoặc không bật được YouTube — trang vẫn phải trả lời đủ câu hỏi "làm sao
 * lấy được mã". Nên không có khối nào ở dưới phụ thuộc vào việc có video hay không.
 *
 * Nguồn video do server nhận dạng sẵn (GuideVideoService) — ở đây chỉ dựng khung đúng tỉ lệ, cố
 * ý không parse URL trong Vue: làm hai nơi thì sớm muộn cũng lệch nhau, mà bên lệch sẽ là bên
 * khách nhìn thấy.
 */
const props = defineProps({
    // { kind: 'embed'|'file', src, provider, orientation } — null khi admin chưa đặt video nào.
    video: { type: Object, default: null },
})

const auth = useAuthStore()
const { cashbackOn, cashbackRate, missingOut, joinHref, joinLabel } = useCashback()

const isPortrait = computed(() => props.video?.orientation === 'portrait')

// Video dọc phải chặn bề ngang lại, không thì trên màn hình máy tính nó cao lênh khênh chiếm
// trọn màn hình và phần hướng dẫn bằng chữ bị đẩy hẳn xuống dưới tầm nhìn.
const wrapperClass = computed(() => {
    if (!isPortrait.value) return 'max-w-3xl'

    return props.video.provider === 'tiktok' ? 'max-w-[340px]' : 'max-w-[380px]'
})

// TikTok nhúng kèm dải tiêu đề + nút của họ nên khung cao hơn 9/16 thật — dùng đúng tỉ lệ
// TikTok khuyến nghị, không thì hoặc bị cắt mất phần dưới hoặc thừa một dải đen.
const ratioClass = computed(() => {
    if (!isPortrait.value) return 'aspect-video'

    return props.video.provider === 'tiktok' ? 'aspect-[325/739]' : 'aspect-[9/16]'
})

const faqs = [
    {
        q: 'Vì sao phải bấm "Kích hoạt mã YouTube" rồi quay lại, không mua luôn ở đó?',
        a: 'Shopee ghi nhận mã ngay trên máy của bạn khi bạn mở link đó, nên bước này bắt buộc phải do bạn tự bấm. '
            + 'Nhưng nếu đặt hàng luôn tại trang vừa mở, đơn đó dễ bị Shopee đánh dấu F02 và bạn mất cả mã lẫn hoàn tiền. '
            + 'Mở ra thấy Shopee là xong việc — quay lại trang này bấm tiếp bước 2 rồi hãy đặt hàng.',
    },
    {
        q: 'Bấm nút mà Facebook mở ra chứ không phải Shopee, có đúng không?',
        a: 'Đúng. Mã chỉ có hiệu lực khi lượt bấm đi qua Facebook. Facebook sẽ mở ra tại một reel hoặc một bình luận — '
            + 'bấm tiếp vào link ở đó là về Shopee với mã đã áp sẵn. Đừng đóng giữa chừng, đóng là phải làm lại từ đầu.',
    },
    {
        q: 'Dán link xong báo không tìm thấy mã thì sao?',
        a: 'Nghĩa là sản phẩm đó đang không có mã nào, hoặc mã vừa hết lượt — không phải trang bị lỗi. '
            + 'Thử lại sau ít phút, hoặc xem khung giờ back mã ở trang chủ rồi vào đúng lúc đó.',
    },
    {
        q: 'Mã có áp được cùng lúc với voucher của Shop không?',
        a: 'Có. Mã lấy ở đây là voucher của Shopee, nằm ở ô "Shopee Voucher" khi thanh toán, tách riêng với voucher của Shop '
            + 'và mã vận chuyển. Trước khi bấm Đặt hàng, nhìn lại phần giảm giá để chắc chắn mã đã vào.',
    },
    {
        q: 'Copy link sản phẩm ở đâu trong app Shopee?',
        a: 'Mở sản phẩm → bấm biểu tượng chia sẻ ở góc trên bên phải → chọn "Sao chép liên kết". '
            + 'Link dạng shopee.vn/... hay dạng rút gọn s.shopee.vn/... đều dùng được.',
    },
]

const openFaq = ref(null)
</script>

<template>
    <Head>
        <title>Hướng dẫn lấy mã giảm giá Shopee | tietkiemvi.com</title>
        <meta
            name="description"
            content="Xem video hướng dẫn lấy mã giảm giá Shopee: copy link sản phẩm, dán vào ô tìm mã, kích hoạt mã YouTube rồi đặt hàng. Kèm lưu ý để không bị đánh dấu F02."
        />
    </Head>

    <AppLayout>
        <section class="px-4 pt-8 pb-6">
            <div class="max-w-3xl mx-auto text-center">
                <h1 class="text-2xl sm:text-3xl font-extrabold text-[var(--color-ink)] mb-2">
                    Hướng dẫn lấy mã giảm giá
                </h1>
                <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                    Xem video một lần là làm được. Bên dưới có cả các bước bằng chữ để đối chiếu trong lúc thao tác.
                </p>
            </div>
        </section>

        <!-- Video: để trên cùng vì đây là thứ khách vào trang này để xem. -->
        <section class="px-4 pb-8">
            <div v-if="video" class="mx-auto" :class="wrapperClass">
                <div
                    class="w-full overflow-hidden rounded-2xl border border-[var(--color-line)] bg-black shadow-lg"
                    :class="ratioClass"
                >
                    <iframe
                        v-if="video.kind === 'embed'"
                        :src="video.src"
                        class="w-full h-full"
                        loading="lazy"
                        referrerpolicy="strict-origin-when-cross-origin"
                        allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen"
                        allowfullscreen
                        title="Video hướng dẫn lấy mã giảm giá"
                    ></iframe>

                    <!-- File tự host: preload="metadata" để khách dùng 4G không bị tải nguyên
                         video ngay khi mở trang, playsinline để iOS không tự bung toàn màn hình. -->
                    <video
                        v-else
                        :src="video.src"
                        class="w-full h-full object-contain bg-black"
                        controls
                        playsinline
                        preload="metadata"
                    ></video>
                </div>
            </div>

            <!-- Chưa đặt video: nói thật là chưa có, không để một khung đen câm. Phần hướng dẫn
                 bằng chữ bên dưới vẫn đủ dùng nên đây chỉ là một dòng ghi chú. -->
            <div v-else class="max-w-3xl mx-auto">
                <div class="card-glass rounded-2xl px-5 py-6 text-center">
                    <p class="text-sm font-semibold text-[var(--color-ink)] mb-1">Video hướng dẫn đang được cập nhật</p>
                    <p class="text-xs text-[var(--color-muted)] leading-relaxed">
                        Trong lúc chờ, bạn xem các bước bằng chữ ngay bên dưới — đầy đủ y như trong video.
                    </p>
                    <Link
                        v-if="auth.isAdmin"
                        href="/admin/settings"
                        class="inline-block mt-3 text-xs font-semibold text-[var(--color-accent)] underline underline-offset-2"
                    >Đặt video ở Admin → Cài đặt →</Link>
                </div>
            </div>
        </section>

        <!-- Các bước bằng chữ -->
        <section class="px-4 pb-4">
            <div class="max-w-3xl mx-auto">
                <h2 class="text-lg font-extrabold text-[var(--color-ink)] mb-4">Các bước làm</h2>

                <ol class="space-y-3">
                    <li class="card-glass rounded-2xl px-5 py-4 flex gap-4">
                        <span class="flex-none w-8 h-8 rounded-full bg-[var(--color-accent)] text-white font-bold text-sm flex items-center justify-center">1</span>
                        <div class="min-w-0">
                            <p class="font-bold text-[var(--color-ink)] text-sm mb-1">Copy link sản phẩm trên Shopee</p>
                            <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                                Mở sản phẩm trong app Shopee → bấm biểu tượng chia sẻ → <b class="text-[var(--color-ink)]">Sao chép liên kết</b>.
                            </p>
                        </div>
                    </li>

                    <li class="card-glass rounded-2xl px-5 py-4 flex gap-4">
                        <span class="flex-none w-8 h-8 rounded-full bg-[var(--color-accent)] text-white font-bold text-sm flex items-center justify-center">2</span>
                        <div class="min-w-0">
                            <p class="font-bold text-[var(--color-ink)] text-sm mb-1">Dán link vào ô tìm mã ở trang chủ</p>
                            <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                                Quay lại trang chủ, dán vào ô rồi bấm <b class="text-[var(--color-ink)]">Lấy mã</b>. Đợi vài giây để hệ thống dò mã cho đúng sản phẩm đó.
                            </p>
                        </div>
                    </li>

                    <li class="card-glass rounded-2xl px-5 py-4 flex gap-4">
                        <span class="flex-none w-8 h-8 rounded-full bg-[#FF0000] text-white font-bold text-sm flex items-center justify-center">3</span>
                        <div class="min-w-0">
                            <p class="font-bold text-[var(--color-ink)] text-sm mb-1">Kích hoạt mã YouTube — nếu trang hiện khung đỏ</p>
                            <p class="text-sm text-[var(--color-muted)] leading-relaxed mb-2">
                                Bấm <b class="text-[var(--color-ink)]">Bước 1: Kích hoạt mã YouTube</b> → Shopee mở ra. Xem thấy là xong việc,
                                <b class="text-[var(--color-ink)]">quay lại trang</b> để làm tiếp bước 4.
                            </p>
                            <div class="rounded-lg bg-[#FF0000]/10 border border-[#FF0000]/30 px-3 py-2.5">
                                <p class="text-xs font-bold text-[#c00000] leading-relaxed">⛔ Tuyệt đối không đặt hàng ở bước này</p>
                                <p class="text-xs text-[var(--color-ink)] leading-relaxed mt-1">
                                    Shopee mở ra đúng sản phẩm bạn định mua nên rất dễ bấm mua luôn — nhưng đặt hàng ngay tại đây
                                    dễ khiến tài khoản bị Shopee đánh dấu <b>F02</b>, mất cả mã lẫn hoàn tiền.
                                </p>
                            </div>
                        </div>
                    </li>

                    <li class="card-glass rounded-2xl px-5 py-4 flex gap-4">
                        <span class="flex-none w-8 h-8 rounded-full bg-[var(--color-accent)] text-white font-bold text-sm flex items-center justify-center">4</span>
                        <div class="min-w-0">
                            <p class="font-bold text-[var(--color-ink)] text-sm mb-1">Bấm nút bước 2 để nhận mã</p>
                            <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                                Nút sẽ ghi <b class="text-[var(--color-ink)]">Lấy mã qua Facebook</b> hoặc <b class="text-[var(--color-ink)]">Mua ngay (đã áp mã)</b>.
                                Nếu đi qua Facebook: Facebook mở ra tại một reel hoặc bình luận — bấm tiếp vào link ở đó là về Shopee, mã đã áp sẵn.
                                <b class="text-[var(--color-ink)]">Đừng đóng giữa chừng</b>, đóng là phải làm lại từ đầu.
                            </p>
                        </div>
                    </li>

                    <li class="card-glass rounded-2xl px-5 py-4 flex gap-4">
                        <span class="flex-none w-8 h-8 rounded-full bg-[var(--color-brand-green)] text-white font-bold text-sm flex items-center justify-center">5</span>
                        <div class="min-w-0">
                            <p class="font-bold text-[var(--color-ink)] text-sm mb-1">Kiểm tra mã rồi đặt hàng</p>
                            <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                                Ở màn hình thanh toán, nhìn lại ô <b class="text-[var(--color-ink)]">Shopee Voucher</b> xem mã đã vào chưa rồi mới bấm Đặt hàng.
                            </p>
                        </div>
                    </li>
                </ol>
            </div>
        </section>

        <!-- Điều kiện hoàn tiền chỉ nói khi chương trình đang thật sự chạy (rate > 0) — xem useCashback. -->
        <section v-if="cashbackOn" class="px-4 pb-4">
            <div class="max-w-3xl mx-auto">
                <div class="rounded-2xl border border-[var(--color-brand-green)]/30 bg-[var(--color-brand-green)]/10 px-5 py-4">
                    <p class="font-bold text-[var(--color-ink)] text-sm mb-1">💰 Muốn được hoàn thêm {{ cashbackRate }}%?</p>
                    <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                        Phải <b class="text-[var(--color-ink)]">đăng nhập TRƯỚC khi lấy mã</b> thì đơn mới ghi nhận được là của bạn.
                        Lấy mã lúc chưa đăng nhập rồi đăng nhập sau thì đơn đó không quy về ai được nữa.
                    </p>
                    <Link
                        v-if="missingOut"
                        :href="joinHref"
                        class="btn-fire inline-block mt-3 px-5 py-2.5 rounded-xl text-sm no-underline"
                    >{{ joinLabel }}</Link>
                </div>
            </div>
        </section>

        <section class="px-4 py-10">
            <div class="max-w-3xl mx-auto">
                <h2 class="text-lg font-extrabold text-[var(--color-ink)] mb-4">Câu hỏi thường gặp</h2>
                <div class="space-y-3">
                    <div v-for="(faq, i) in faqs" :key="i" class="card-glass rounded-2xl overflow-hidden">
                        <button
                            @click="openFaq = openFaq === i ? null : i"
                            class="w-full px-5 py-4 text-left flex justify-between items-center gap-4 font-semibold text-[var(--color-ink)] text-sm"
                        >
                            {{ faq.q }}
                            <span class="text-[var(--color-muted)] flex-none transition-transform" :class="openFaq === i ? 'rotate-180' : ''">▾</span>
                        </button>
                        <Transition name="fade-up">
                            <div v-if="openFaq === i" class="px-5 pb-4 text-sm text-[var(--color-muted)] leading-relaxed">
                                {{ faq.a }}
                            </div>
                        </Transition>
                    </div>
                </div>
            </div>
        </section>

        <section class="px-4 pb-14">
            <div class="max-w-3xl mx-auto text-center">
                <Link href="/" class="btn-fire inline-block px-8 py-4 rounded-2xl no-underline">
                    Về trang chủ lấy mã →
                </Link>
            </div>
        </section>
    </AppLayout>
</template>
