<script setup>
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import CouponTicket from '@/Components/CouponTicket.vue'
import SavingsSummary from '@/Components/SavingsSummary.vue'

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

const platformLabels = {
    shopee: { name: 'Shopee', color: 'bg-[#F5511E] text-white' },
    lazada: { name: 'Lazada', color: 'bg-[#0F146D] text-white' },
    tiki: { name: 'Tiki', color: 'bg-[#1A94FF] text-white' },
    tiktok: { name: 'TikTok', color: 'bg-black text-white' },
}
</script>

<template>
    <Head title="Kết quả — Mã giảm giá" />
    <AppLayout>
        <div class="max-w-5xl mx-auto px-4 py-10">
            <!-- Success banner -->
            <div class="bg-[var(--color-green-soft)] border border-[var(--color-brand-green)]/20 rounded-2xl px-6 py-4 flex items-center gap-3 mb-8">
                <span class="text-2xl">✅</span>
                <div>
                    <p class="font-extrabold text-[var(--color-brand-green)]">Tìm thấy {{ vouchers?.length || 0 }} mã giảm giá!</p>
                    <p class="text-sm text-[var(--color-brand-green)]/70">Bạn có thể tiết kiệm {{ vnd(savings?.total_saved || 0) }} cho đơn hàng này.</p>
                </div>
                <Link href="/" class="ml-auto text-sm text-[var(--color-brand-green)] font-semibold hover:underline">← Tìm mã khác</Link>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-[1.6fr_1fr] gap-8 items-start">
                <!-- Left column -->
                <div class="space-y-6">
                    <!-- Product card -->
                    <div class="bg-white rounded-2xl border border-[var(--color-line)] p-6 flex gap-4">
                        <div class="w-24 h-24 rounded-xl bg-[var(--color-peach-soft)] flex-none overflow-hidden">
                            <img v-if="product?.image" :src="product.image" :alt="product.name" class="w-full h-full object-cover" />
                            <div v-else class="w-full h-full flex items-center justify-center text-3xl">🛍️</div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <span :class="platformLabels[product?.platform]?.color" class="text-xs font-bold px-2 py-0.5 rounded-md">
                                    {{ platformLabels[product?.platform]?.name }}
                                </span>
                            </div>
                            <h2 class="font-extrabold text-[var(--color-ink)] text-base leading-snug line-clamp-2 mb-2">{{ product?.name }}</h2>
                            <div class="flex items-baseline gap-2">
                                <span class="text-xl font-extrabold text-[var(--color-accent)]">{{ vnd(product?.discounted_price || 0) }}</span>
                                <span v-if="product?.original_price !== product?.discounted_price" class="text-sm text-[var(--color-muted)] line-through">{{ vnd(product?.original_price || 0) }}</span>
                                <span v-if="product?.discount_percent" class="bg-[var(--color-peach)] text-[var(--color-accent)] text-xs font-bold px-2 py-0.5 rounded-full">-{{ product.discount_percent }}%</span>
                            </div>
                            <div class="flex items-center gap-3 mt-2 text-xs text-[var(--color-muted)]">
                                <span v-if="product?.rating">⭐ {{ product.rating }}</span>
                                <span v-if="product?.sold_count">Đã bán {{ product.sold_count.toLocaleString('vi-VN') }}</span>
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
                        <div v-else class="bg-[var(--color-peach-soft)] rounded-2xl p-6 text-center text-[var(--color-muted)] text-sm">
                            Không tìm thấy mã giảm giá sản phẩm nào.
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
                    <div class="bg-white rounded-2xl border border-[var(--color-line)] p-5">
                        <p class="text-xs font-semibold text-[var(--color-muted)] uppercase tracking-wide mb-2">Link mua hàng (có hoàn tiền)</p>
                        <div class="flex items-center gap-2">
                            <input :value="affiliateLink" readonly class="flex-1 text-xs font-mono bg-[var(--color-peach-soft)] rounded-lg px-3 py-2 border border-[var(--color-line)] truncate" />
                            <a :href="affiliateLink" target="_blank" rel="noopener"
                                class="bg-[var(--color-accent)] text-white text-xs font-bold px-4 py-2 rounded-lg hover:bg-[var(--color-accent-deep)] transition whitespace-nowrap">
                                Mở →
                            </a>
                        </div>
                    </div>
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
