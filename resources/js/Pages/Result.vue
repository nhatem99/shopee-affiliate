<script setup>
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import CouponTicket from '@/Components/CouponTicket.vue'
import SavingsSummary from '@/Components/SavingsSummary.vue'
import Disclaimer from '@/Components/Disclaimer.vue'
import RestockSchedule from '@/Components/RestockSchedule.vue'

const props = defineProps({
    product: Object,
    vouchers: Array,
    platformVouchers: Array,
    affiliateLink: String,
    cashback: Number,
    savings: Object,
})

function vnd(n) {
    return '₫' + Number(n).toLocaleString('vi-VN')
}

// Chỉ còn TÊN sàn, bỏ bảng màu thương hiệu gõ cứng (#F5511E, #0F146D, #1A94FF, black). Bốn màu
// đó không có bản tối nào — riêng "bg-black" nằm trên thẻ navy ở chế độ tối là một ô đen trong ô
// đen — và cái #F5511E của Shopee trùng đúng màu nhấn, tức nhãn sàn tự nhận là hành động chính
// của màn hình trong khi hành động thật là nút "Mở link". Nhãn sàn giờ là chip trung tính, giống
// hệt bên Lịch sử.
const platformLabels = { shopee: 'Shopee', lazada: 'Lazada', tiki: 'Tiki', tiktok: 'TikTok' }
</script>

<template>
    <Head title="Kết quả — Mã giảm giá" />
    <AppLayout>
        <div class="max-w-5xl mx-auto px-4 py-10">
            <!-- Khung giờ back mã: đặt trên cùng, giống trang chủ -->
            <RestockSchedule class="mb-6" />

            <!-- Success banner. Dùng --color-money thay --color-brand-green làm CHỮ: brand-green
                 chỉ đạt 3.41:1 ở chế độ sáng, mà đây là câu báo tin mừng to nhất trang. Dòng phụ
                 trước đây là brand-green/70 — tức 3.41:1 nhân thêm 0.7 nữa. -->
            <div class="bg-[var(--color-money-soft)] border border-[rgba(var(--color-money-rgb),.25)] rounded-[var(--radius-card)] px-4 sm:px-6 py-4 flex items-center gap-3 mb-8 flex-wrap">
                <span class="text-2xl" aria-hidden="true">✅</span>
                <!-- basis-0 (không phải chỉ min-w-0): flex-wrap ngắt dòng theo bề rộng MONG MUỐN
                     của từng món, mà khối chữ này muốn ~300px — ở 375px nó vượt dòng và đẩy dấu
                     ✅ ngồi một mình trên một dòng riêng. Đặt basis-0 thì khối chữ ở lại cùng dòng
                     với dấu ✅ rồi giãn ra vừa chỗ còn lại. -->
                <div class="flex-1 basis-0 min-w-0">
                    <p class="font-extrabold text-[var(--color-money)]">Tìm thấy <span class="num">{{ vouchers?.length || 0 }}</span> mã giảm giá!</p>
                    <p class="text-sm text-[var(--color-muted)]">Bạn có thể tiết kiệm <b class="num text-[var(--color-money)]">{{ vnd(savings?.total_saved || 0) }}</b> cho đơn hàng này.</p>
                </div>
                <!-- Trên điện thoại cho hẳn một dòng riêng (basis-full) thay vì chen vào cạnh khối
                     chữ: chen vào thì cả hai cùng bị bóp, chữ "← Tìm mã khác" gãy làm ba dòng.
                     Gạch chân cố định chứ không chỉ hover: đây là chữ màu ink giống hệt chữ thường
                     xung quanh, mà điện thoại thì không có hover để lộ ra rằng bấm được. -->
                <Link href="/" class="focus-ring basis-full sm:basis-auto ml-auto inline-flex items-center justify-end min-h-[44px] px-2 -mr-2 sm:mr-0 rounded-lg text-sm text-[var(--color-ink)] font-semibold underline underline-offset-4 whitespace-nowrap">← Tìm mã khác</Link>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-[1.6fr_1fr] gap-8 items-start">
                <!-- Left column -->
                <div class="space-y-6">
                    <!-- Product card -->
                    <div class="card p-4 sm:p-6 flex gap-4">
                        <div class="w-20 h-20 sm:w-24 sm:h-24 rounded-xl bg-[var(--color-peach-soft)] flex-none overflow-hidden">
                            <img v-if="product?.image" :src="product.image" :alt="product.name" class="w-full h-full object-cover" />
                            <div v-else class="w-full h-full flex items-center justify-center text-3xl">🛍️</div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="panel border border-[var(--color-line)] text-xs font-bold text-[var(--color-ink)] px-2 py-0.5">
                                    {{ platformLabels[product?.platform] }}
                                </span>
                            </div>
                            <h2 class="font-extrabold text-[var(--color-ink)] text-base leading-snug line-clamp-2 mb-2">{{ product?.name }}</h2>
                            <div class="flex items-baseline gap-2 flex-wrap">
                                <!-- Giá về màu chữ thường; phần GIẢM mới là tin vui nên mang màu
                                     tiền. Trước đây cả giá lẫn badge giảm đều màu nhấn, nên hai
                                     con số cạnh nhau cùng hét một âm lượng. -->
                                <span class="num text-xl font-extrabold text-[var(--color-ink)]">{{ vnd(product?.discounted_price || 0) }}</span>
                                <span v-if="product?.original_price !== product?.discounted_price" class="num text-sm text-[var(--color-muted)] line-through">{{ vnd(product?.original_price || 0) }}</span>
                                <span v-if="product?.discount_percent" class="num bg-[var(--color-money-soft)] text-[var(--color-money)] text-xs font-bold px-2 py-0.5 rounded-full">-{{ product.discount_percent }}%</span>
                            </div>
                            <div class="flex items-center gap-3 mt-2 text-xs text-[var(--color-muted)] flex-wrap">
                                <span v-if="product?.rating" class="num">⭐ {{ product.rating }}</span>
                                <span v-if="product?.sold_count" class="num">Đã bán {{ product.sold_count.toLocaleString('vi-VN') }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Product vouchers -->
                    <div>
                        <h3 class="font-extrabold text-[var(--color-ink)] mb-3">Mã giảm giá sản phẩm</h3>
                        <div v-if="vouchers?.length" class="space-y-3">
                            <CouponTicket
                                v-for="v in vouchers"
                                :key="v.id || v.code"
                                :code="v.code"
                                :discount-type="v.discount_type"
                                :discount-value="v.discount_value"
                                :minimum-order="v.minimum_order"
                                :expires-at="v.expires_at"
                                :is-freeship="v.is_freeship"
                            />
                        </div>
                        <div v-else class="panel px-5 py-8 text-center text-[var(--color-muted)] text-sm leading-relaxed">
                            Không tìm thấy mã giảm giá sản phẩm nào cho sản phẩm này.
                        </div>
                    </div>

                    <!-- Platform vouchers (Facebook / YouTube) -->
                    <div v-if="platformVouchers?.length">
                        <h3 class="font-extrabold text-[var(--color-ink)] mb-1">Voucher toàn sàn</h3>
                        <p class="text-xs text-[var(--color-muted)] mb-3">Mã từ Facebook &amp; YouTube — áp dụng khi thanh toán</p>
                        <div class="space-y-3">
                            <CouponTicket
                                v-for="v in platformVouchers"
                                :key="v.id"
                                :code="v.code"
                                :discount-type="v.discount_type"
                                :discount-value="v.discount_value"
                                :minimum-order="v.minimum_order"
                                :expires-at="v.expires_at"
                                :is-freeship="v.discount_type === 'freeship'"
                                :source="v.source"
                                :subtitle="v.title"
                            />
                        </div>
                    </div>

                    <!-- Affiliate link -->
                    <div class="card p-4 sm:p-5">
                        <!-- Bỏ chữ "(có hoàn tiền)": link ở trang này KHÔNG đi qua
                             ShortLinkController::store nên không mang sub_id của khách, tức đơn đặt
                             từ đây không quy về ai được. Hứa hoàn tiền ở đúng chỗ chắc chắn không
                             hoàn được là lỗi nặng nhất trong cả nhóm này. -->
                        <p class="text-xs font-semibold text-[var(--color-muted)] uppercase tracking-wide mb-2">Link mua hàng</p>
                        <div class="flex items-center gap-2">
                            <label class="sr-only" for="result-affiliate-link">Link mua hàng</label>
                            <input id="result-affiliate-link" :value="affiliateLink" readonly
                                class="focus-ring flex-1 min-w-0 min-h-[44px] text-xs font-mono text-[var(--color-ink)] bg-[var(--color-bg)] rounded-[var(--radius-ctl)] px-3 border border-[var(--color-line)] truncate" />
                            <!-- Hành động chính DUY NHẤT của màn hình này: chỉ chỗ này được mang
                                 màu lửa. -->
                            <a :href="affiliateLink" target="_blank" rel="noopener"
                                class="btn-fire inline-flex items-center justify-center text-sm px-5 rounded-[var(--radius-ctl)] whitespace-nowrap">
                                Mở →
                            </a>
                        </div>
                    </div>

                    <Disclaimer />
                </div>

                <!-- Right sticky summary (desktop only) -->
                <div class="hidden lg:block">
                    <SavingsSummary
                        :savings="savings"
                        :cashback="cashback"
                        :affiliate-link="affiliateLink"
                    />
                </div>
            </div>

            <!-- Mobile summary -->
            <div class="lg:hidden mt-6">
                <SavingsSummary
                    :savings="savings"
                    :cashback="cashback"
                    :affiliate-link="affiliateLink"
                />
            </div>
        </div>
    </AppLayout>
</template>
