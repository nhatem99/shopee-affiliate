<script setup>
import { ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps({
    customerAuthEnabled: { type: Boolean, required: true },
    maintenanceMode: { type: Boolean, required: true },
    festiveDecor: { type: Boolean, default: false },
    historyRebuyEnabled: { type: Boolean, default: false },
    fbigWindowAutoSwitch: { type: Boolean, default: false },
    leaderboardDemo: { type: Boolean, default: true },
    communityUrl: { type: String, default: '' },
    messengerUrl: { type: String, default: '' },
    cashbackRate: { type: Number, default: 0 },
    // null = chưa đặt riêng, khách đang thấy đúng tỉ lệ thực.
    cashbackDisplayRate: { type: Number, default: null },
    welcomeBonusEnabled: { type: Boolean, default: true },
    welcomeBonusAmount: { type: Number, default: 5000 },
})

const toast = useToast()
const customerAuthEnabled = ref(props.customerAuthEnabled)
const maintenanceMode = ref(props.maintenanceMode)
const savingCustomerAuth = ref(false)
const savingMaintenance = ref(false)
const festiveDecor = ref(props.festiveDecor)
const savingFestive = ref(false)
const historyRebuyEnabled = ref(props.historyRebuyEnabled)
const savingHistoryRebuy = ref(false)
const fbigWindowAutoSwitch = ref(props.fbigWindowAutoSwitch)
const savingFbigAutoSwitch = ref(false)
const leaderboardDemo = ref(props.leaderboardDemo)
const savingLeaderboardDemo = ref(false)
const communityUrl = ref(props.communityUrl)
const savingCommunityUrl = ref(false)
const messengerUrl = ref(props.messengerUrl)
const savingMessengerUrl = ref(false)
const cashbackRate = ref(props.cashbackRate)
const savingCashbackRate = ref(false)
const cashbackDisplayRate = ref(props.cashbackDisplayRate ?? '')
const savingCashbackDisplayRate = ref(false)
const welcomeBonusEnabled = ref(props.welcomeBonusEnabled)
const savingWelcomeBonus = ref(false)
const welcomeBonusAmount = ref(props.welcomeBonusAmount)
const savingWelcomeBonusAmount = ref(false)

// Đồng bộ lại nếu server trả về giá trị khác (ví dụ sau khi lưu xong)
watch(() => props.customerAuthEnabled, (v) => { customerAuthEnabled.value = v })
watch(() => props.maintenanceMode, (v) => { maintenanceMode.value = v })
watch(() => props.festiveDecor, (v) => { festiveDecor.value = v })
watch(() => props.historyRebuyEnabled, (v) => { historyRebuyEnabled.value = v })
watch(() => props.fbigWindowAutoSwitch, (v) => { fbigWindowAutoSwitch.value = v })
watch(() => props.leaderboardDemo, (v) => { leaderboardDemo.value = v })
watch(() => props.communityUrl, (v) => { communityUrl.value = v })
watch(() => props.messengerUrl, (v) => { messengerUrl.value = v })
watch(() => props.cashbackRate, (v) => { cashbackRate.value = v })
watch(() => props.cashbackDisplayRate, (v) => { cashbackDisplayRate.value = v ?? '' })
watch(() => props.welcomeBonusEnabled, (v) => { welcomeBonusEnabled.value = v })
watch(() => props.welcomeBonusAmount, (v) => { welcomeBonusAmount.value = v })

function toggleCustomerAuth() {
    const next = !customerAuthEnabled.value
    customerAuthEnabled.value = next
    savingCustomerAuth.value = true

    router.post('/admin/settings', { customer_auth_enabled: next }, {
        preserveScroll: true,
        onSuccess: () => toast.success(next ? 'Đã bật đăng nhập/đăng ký cho khách.' : 'Đã tắt đăng nhập/đăng ký cho khách.'),
        onError: () => {
            customerAuthEnabled.value = !next // rollback nếu lưu lỗi
            toast.error('Không lưu được cài đặt, vui lòng thử lại.')
        },
        onFinish: () => { savingCustomerAuth.value = false },
    })
}

function toggleMaintenance() {
    const next = !maintenanceMode.value
    maintenanceMode.value = next
    savingMaintenance.value = true

    router.post('/admin/settings', { maintenance_mode: next }, {
        preserveScroll: true,
        onSuccess: () => toast.success(next ? 'Đã bật chế độ bảo trì.' : 'Đã tắt chế độ bảo trì.'),
        onError: () => {
            maintenanceMode.value = !next // rollback nếu lưu lỗi
            toast.error('Không lưu được cài đặt, vui lòng thử lại.')
        },
        onFinish: () => { savingMaintenance.value = false },
    })
}

function toggleFestive() {
    const next = !festiveDecor.value
    festiveDecor.value = next
    savingFestive.value = true

    router.post('/admin/settings', { festive_decor: next }, {
        preserveScroll: true,
        onSuccess: () => toast.success(next ? 'Đã bật trang trí Trung thu trên trang khách.' : 'Đã tắt trang trí.'),
        onError: () => {
            festiveDecor.value = !next // rollback nếu lưu lỗi
            toast.error('Không lưu được cài đặt, vui lòng thử lại.')
        },
        onFinish: () => { savingFestive.value = false },
    })
}

function toggleHistoryRebuy() {
    const next = !historyRebuyEnabled.value
    historyRebuyEnabled.value = next
    savingHistoryRebuy.value = true

    router.post('/admin/settings', { history_rebuy_enabled: next }, {
        preserveScroll: true,
        onSuccess: () => toast.success(next
            ? 'Đã bật mua lại từ lịch sử.'
            : 'Đã tắt — khách phải dán lại link mới mua được.'),
        onError: () => {
            historyRebuyEnabled.value = !next // rollback nếu lưu lỗi
            toast.error('Không lưu được cài đặt, vui lòng thử lại.')
        },
        onFinish: () => { savingHistoryRebuy.value = false },
    })
}

function toggleFbigAutoSwitch() {
    const next = !fbigWindowAutoSwitch.value
    fbigWindowAutoSwitch.value = next
    savingFbigAutoSwitch.value = true

    router.post('/admin/settings', { fbig_window_auto_switch: next }, {
        preserveScroll: true,
        onSuccess: () => toast.success(next
            ? 'Đã bật tự chuyển sang FB-IG trong khung giờ back mã.'
            : 'Đã tắt — nguồn lấy mã giữ đúng lựa chọn ở trang Cấu hình API.'),
        onError: () => {
            fbigWindowAutoSwitch.value = !next // rollback nếu lưu lỗi
            toast.error('Không lưu được cài đặt, vui lòng thử lại.')
        },
        onFinish: () => { savingFbigAutoSwitch.value = false },
    })
}

function toggleLeaderboardDemo() {
    const next = !leaderboardDemo.value
    leaderboardDemo.value = next
    savingLeaderboardDemo.value = true

    router.post('/admin/settings', { leaderboard_demo: next }, {
        preserveScroll: true,
        onSuccess: () => toast.success(next ? 'Đã bật số liệu minh hoạ cho bảng xếp hạng.' : 'Đã tắt số liệu minh hoạ.'),
        onError: () => {
            leaderboardDemo.value = !next
            toast.error('Không lưu được cài đặt, vui lòng thử lại.')
        },
        onFinish: () => { savingLeaderboardDemo.value = false },
    })
}

function saveCommunityUrl() {
    savingCommunityUrl.value = true

    router.post('/admin/settings', { community_url: communityUrl.value.trim() || null }, {
        preserveScroll: true,
        onSuccess: () => toast.success(communityUrl.value.trim()
            ? 'Đã lưu link cộng đồng săn sale.'
            : 'Đã bỏ link cộng đồng — banner sẽ ẩn dòng đó đi.'),
        onError: (errors) => toast.error(errors.community_url || 'Không lưu được link, vui lòng thử lại.'),
        onFinish: () => { savingCommunityUrl.value = false },
    })
}

function saveMessengerUrl() {
    savingMessengerUrl.value = true

    router.post('/admin/settings', { messenger_url: messengerUrl.value.trim() || null }, {
        preserveScroll: true,
        onSuccess: () => toast.success(messengerUrl.value.trim()
            ? 'Đã lưu link Messenger — icon chat sẽ hiện trên trang khách.'
            : 'Đã bỏ link Messenger — icon chat sẽ ẩn đi.'),
        onError: (errors) => toast.error(errors.messenger_url || 'Không lưu được link, vui lòng thử lại.'),
        onFinish: () => { savingMessengerUrl.value = false },
    })
}
function saveCashbackRate() {
    savingCashbackRate.value = true

    router.post('/admin/settings', { cashback_rate: Number(cashbackRate.value) || 0 }, {
        preserveScroll: true,
        onSuccess: () => toast.success(Number(cashbackRate.value) > 0
            ? `Đã đặt tỉ lệ hoàn tiền ${Number(cashbackRate.value)}% và áp lại cho các đơn đã nhập.`
            : 'Đã tắt hoàn tiền — đơn mới sẽ không sinh hoa hồng cho khách.'),
        onError: (errors) => toast.error(errors.cashback_rate || 'Không lưu được tỉ lệ, vui lòng thử lại.'),
        onFinish: () => { savingCashbackRate.value = false },
    })
}

function toggleWelcomeBonus() {
    const next = !welcomeBonusEnabled.value
    savingWelcomeBonus.value = true

    router.post('/admin/settings', { welcome_bonus_enabled: next }, {
        preserveScroll: true,
        onSuccess: () => {
            welcomeBonusEnabled.value = next
            toast.success(next ? 'Đã bật thưởng người mới.' : 'Đã tắt — tài khoản mới không được cộng thưởng nữa.')
        },
        onError: () => toast.error('Không lưu được, vui lòng thử lại.'),
        onFinish: () => { savingWelcomeBonus.value = false },
    })
}

function saveWelcomeBonusAmount() {
    savingWelcomeBonusAmount.value = true
    const value = Math.max(0, Math.round(Number(welcomeBonusAmount.value) || 0))

    router.post('/admin/settings', { welcome_bonus_amount: value }, {
        preserveScroll: true,
        onSuccess: () => toast.success(`Tài khoản đăng ký từ giờ được thưởng ${value.toLocaleString('vi-VN')} đ.`),
        onError: (errors) => toast.error(errors.welcome_bonus_amount || 'Không lưu được, vui lòng thử lại.'),
        onFinish: () => { savingWelcomeBonusAmount.value = false },
    })
}

// Ô để trống = gửi null = xoá số riêng, khách quay về thấy đúng tỉ lệ thực. Không sync tiền
// vì con số này chỉ để hiển thị.
function saveCashbackDisplayRate() {
    savingCashbackDisplayRate.value = true
    const raw = String(cashbackDisplayRate.value).trim()
    const value = raw === '' ? null : Number(raw)

    router.post('/admin/settings', { cashback_display_rate: value }, {
        preserveScroll: true,
        onSuccess: () => toast.success(value === null
            ? 'Đã bỏ số riêng — khách sẽ thấy đúng tỉ lệ thực trả.'
            : `Khách sẽ thấy "hoàn ${value}%" trên trang chủ và bài mẫu.`),
        onError: (errors) => toast.error(errors.cashback_display_rate || 'Không lưu được, vui lòng thử lại.'),
        onFinish: () => { savingCashbackDisplayRate.value = false },
    })
}
</script>

<template>
    <Head title="Admin — Cài đặt chung" />
    <AdminLayout>
        <template #title>Cài đặt chung</template>

        <div class="max-w-2xl space-y-6">
            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="min-w-0">
                        <h2 class="font-bold text-[var(--color-ink)] mb-1">Đăng nhập / Đăng ký cho khách</h2>
                        <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                            Khi tắt, nút "Đăng nhập" và "Đăng ký" sẽ không hiện trên trang cho khách nữa, và khách gõ thẳng
                            link <span class="font-mono text-xs">/login</span>, <span class="font-mono text-xs">/register</span>
                            cũng sẽ được chuyển về trang chủ. Bật lại bất cứ lúc nào khi cần.
                        </p>
                    </div>

                    <button
                        type="button"
                        role="switch"
                        :aria-checked="customerAuthEnabled"
                        @click="toggleCustomerAuth"
                        :disabled="savingCustomerAuth"
                        class="relative flex-none w-14 h-8 rounded-full transition-colors duration-200 disabled:opacity-60"
                        :class="customerAuthEnabled ? 'bg-[var(--color-brand-green)]' : 'bg-[var(--color-line)]'"
                    >
                        <span
                            class="absolute top-1 left-1 w-6 h-6 rounded-full bg-white shadow-md transition-transform duration-200"
                            :class="customerAuthEnabled ? 'translate-x-6' : 'translate-x-0'"
                        ></span>
                    </button>
                </div>

                <div class="mt-4 pt-4 border-t border-[var(--color-line)] flex items-center gap-2 text-sm">
                    <span class="w-2 h-2 rounded-full flex-none" :class="customerAuthEnabled ? 'bg-[var(--color-brand-green)]' : 'bg-[var(--color-muted)]'"></span>
                    <span class="text-[var(--color-ink)] font-medium">
                        {{ customerAuthEnabled ? 'Đang bật — khách có thể đăng nhập/đăng ký.' : 'Đang tắt — khách không thấy mục đăng nhập/đăng ký.' }}
                    </span>
                </div>
            </div>

            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="min-w-0">
                        <h2 class="font-bold text-[var(--color-ink)] mb-1">Chế độ bảo trì</h2>
                        <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                            Khi bật, toàn bộ trang cho khách (trang chủ, blog, quét link, lịch sử, tài khoản...) hiện
                            thông báo "đang bảo trì" thay vì nội dung thật. Trang <span class="font-mono text-xs">/login</span>
                            và khu vực admin vẫn vào được bình thường để bạn tự tắt lại khi xong.
                            <strong class="text-[var(--color-ink)]">Tài khoản admin vẫn dùng được đầy đủ trang khách</strong>
                            (dán link, tìm mã, bấm thử link) để kiểm tra chức năng trong lúc khách bị chặn.
                        </p>
                    </div>

                    <button
                        type="button"
                        role="switch"
                        :aria-checked="maintenanceMode"
                        @click="toggleMaintenance"
                        :disabled="savingMaintenance"
                        class="relative flex-none w-14 h-8 rounded-full transition-colors duration-200 disabled:opacity-60"
                        :class="maintenanceMode ? 'bg-amber-500' : 'bg-[var(--color-line)]'"
                    >
                        <span
                            class="absolute top-1 left-1 w-6 h-6 rounded-full bg-white shadow-md transition-transform duration-200"
                            :class="maintenanceMode ? 'translate-x-6' : 'translate-x-0'"
                        ></span>
                    </button>
                </div>

                <div class="mt-4 pt-4 border-t border-[var(--color-line)] flex items-center gap-2 text-sm">
                    <span class="w-2 h-2 rounded-full flex-none" :class="maintenanceMode ? 'bg-amber-500' : 'bg-[var(--color-muted)]'"></span>
                    <span class="text-[var(--color-ink)] font-medium">
                        {{ maintenanceMode ? 'Đang bảo trì — khách không vào được trang, admin vẫn dùng bình thường.' : 'Đang tắt — trang hoạt động bình thường.' }}
                    </span>
                </div>
            </div>

            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="min-w-0">
                        <h2 class="font-bold text-[var(--color-ink)] mb-1">🏮 Trang trí Trung thu</h2>
                        <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                            Đèn lồng, bánh trung thu, trăng và mây trôi lơ lửng trên mọi trang khách. Chỉ là lớp
                            trang trí, không che nút bấm. Hết mùa thì tắt ở đây — không cần sửa gì khác.
                        </p>
                    </div>

                    <button
                        type="button"
                        role="switch"
                        :aria-checked="festiveDecor"
                        @click="toggleFestive"
                        :disabled="savingFestive"
                        class="relative flex-none w-14 h-8 rounded-full transition-colors duration-200 disabled:opacity-60"
                        :class="festiveDecor ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-line)]'"
                    >
                        <span
                            class="absolute top-1 left-1 w-6 h-6 rounded-full bg-white shadow-md transition-transform duration-200"
                            :class="festiveDecor ? 'translate-x-6' : 'translate-x-0'"
                        ></span>
                    </button>
                </div>
            </div>

            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="min-w-0">
                        <h2 class="font-bold text-[var(--color-ink)] mb-1">🕘 Mua lại từ lịch sử</h2>
                        <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                            Cho khách bấm mua thẳng từ mục đã quét trước đó — cả khối lịch sử dưới ô dán link
                            ở trang chủ lẫn trang Lịch sử. Tắt thì mỗi mục chỉ còn là sổ ghi, muốn mua phải
                            dán lại link để quét mới. Nên tắt: mã trong link cũ có thể đã hết lượt hoặc hết
                            hạn từ lúc quét, và lượt bấm đi từ đó không chắc được ghi nhận.
                        </p>
                    </div>

                    <button
                        type="button"
                        role="switch"
                        :aria-checked="historyRebuyEnabled"
                        @click="toggleHistoryRebuy"
                        :disabled="savingHistoryRebuy"
                        class="relative flex-none w-14 h-8 rounded-full transition-colors duration-200 disabled:opacity-60"
                        :class="historyRebuyEnabled ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-line)]'"
                    >
                        <span
                            class="absolute top-1 left-1 w-6 h-6 rounded-full bg-white shadow-md transition-transform duration-200"
                            :class="historyRebuyEnabled ? 'translate-x-6' : 'translate-x-0'"
                        ></span>
                    </button>
                </div>

                <div class="mt-4 pt-4 border-t border-[var(--color-line)] flex items-center gap-2 text-sm">
                    <span class="w-2 h-2 rounded-full flex-none" :class="historyRebuyEnabled ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-muted)]'"></span>
                    <span class="text-[var(--color-ink)] font-medium">
                        {{ historyRebuyEnabled
                            ? 'Đang bật — lịch sử có nút mua, khách bấm lại được không cần dán link.'
                            : 'Đang tắt — khách luôn phải dán lại link khi muốn mua.' }}
                    </span>
                </div>
            </div>

            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="min-w-0">
                        <h2 class="font-bold text-[var(--color-ink)] mb-1">⚡ Tự chuyển sang FB-IG trong khung giờ back mã</h2>
                        <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                            Khi nguồn lấy mã đang là <b>ganma (mã YouTube)</b>: tới khung giờ back mã FB-IG
                            (<b>0h, 9h, 15h, 20h — mỗi khung 1 tiếng</b>, giờ VN) thì tạm chuyển sang
                            <b>kieushopee</b>, hết khung tự quay lại ganma. Đang để kieushopee sẵn thì không đổi gì.
                            Công tắc nguồn ở trang Cấu hình API <b>không bị sửa</b> — đây chỉ là lớp ghi đè tạm thời.
                            Giao diện khách không đổi gì cả.
                        </p>
                    </div>

                    <button
                        type="button"
                        role="switch"
                        :aria-checked="fbigWindowAutoSwitch"
                        @click="toggleFbigAutoSwitch"
                        :disabled="savingFbigAutoSwitch"
                        class="relative flex-none w-14 h-8 rounded-full transition-colors duration-200 disabled:opacity-60"
                        :class="fbigWindowAutoSwitch ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-line)]'"
                    >
                        <span
                            class="absolute top-1 left-1 w-6 h-6 rounded-full bg-white shadow-md transition-transform duration-200"
                            :class="fbigWindowAutoSwitch ? 'translate-x-6' : 'translate-x-0'"
                        ></span>
                    </button>
                </div>

                <div class="mt-4 pt-4 border-t border-[var(--color-line)] flex items-center gap-2 text-sm">
                    <span class="w-2 h-2 rounded-full flex-none" :class="fbigWindowAutoSwitch ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-muted)]'"></span>
                    <span class="text-[var(--color-ink)] font-medium">
                        {{ fbigWindowAutoSwitch
                            ? 'Đang bật — xem nguồn nào thật sự đang phục vụ khách ở trang Cấu hình API.'
                            : 'Đang tắt — nguồn lấy mã luôn đúng lựa chọn ở trang Cấu hình API.' }}
                    </span>
                </div>
            </div>

            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="min-w-0">
                        <h2 class="font-bold text-[var(--color-ink)] mb-1">🏆 Số liệu minh hoạ cho bảng xếp hạng</h2>
                        <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                            Khi tháng này <strong class="text-[var(--color-ink)]">chưa có ai</strong> được hoàn tiền, bảng vàng ở trang chủ
                            và /hoan-tien sẽ hiện 7 người mẫu (tên che sẵn) y như bảng thật thay vì để trống.
                            Có người thật đầu tiên là mẫu tự biến mất. Chỉ hiện khi chương trình hoàn tiền đang bật.
                        </p>
                    </div>

                    <button
                        type="button"
                        role="switch"
                        :aria-checked="leaderboardDemo"
                        @click="toggleLeaderboardDemo"
                        :disabled="savingLeaderboardDemo"
                        class="relative flex-none w-14 h-8 rounded-full transition-colors duration-200 disabled:opacity-60"
                        :class="leaderboardDemo ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-line)]'"
                    >
                        <span
                            class="absolute top-1 left-1 w-6 h-6 rounded-full bg-white shadow-md transition-transform duration-200"
                            :class="leaderboardDemo ? 'translate-x-6' : 'translate-x-0'"
                        ></span>
                    </button>
                </div>
            </div>

            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6">
                <h2 class="font-bold text-[var(--color-ink)] mb-1">Tỉ lệ hoàn tiền cho khách</h2>
                <p class="text-sm text-[var(--color-muted)] leading-relaxed mb-4">
                    Phần trăm <strong class="text-[var(--color-ink)]">hoa hồng ròng</strong> (đã trừ phí MCN) trả lại cho khách
                    mỗi khi một đơn hàng <strong class="text-[var(--color-ink)]">Hoàn thành</strong> trong báo cáo Shopee.
                    Để <strong class="text-[var(--color-ink)]">0</strong> là tắt hẳn — nhập báo cáo vẫn chạy nhưng không đồng nào vào ví khách.
                    Đổi tỉ lệ sẽ <strong class="text-[var(--color-ink)]">tính lại cả những đơn đã nhập trước đó</strong>, trừ đơn đã chi trả.
                </p>

                <div class="flex flex-col sm:flex-row gap-2">
                    <div class="relative flex-1 min-w-0">
                        <input
                            v-model="cashbackRate"
                            type="number"
                            min="0"
                            max="100"
                            step="1"
                            @keydown.enter="saveCashbackRate"
                            class="w-full px-4 py-2.5 pr-10 rounded-xl border border-[var(--color-line)] bg-[var(--color-bg)] text-sm text-[var(--color-ink)] tabular-nums focus:outline-none focus:border-[var(--color-accent)]"
                        />
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-[var(--color-muted)] pointer-events-none">%</span>
                    </div>
                    <button
                        type="button"
                        @click="saveCashbackRate"
                        :disabled="savingCashbackRate"
                        class="btn-fire px-6 py-2.5 rounded-xl text-sm whitespace-nowrap disabled:opacity-60"
                    >{{ savingCashbackRate ? 'Đang lưu...' : 'Lưu tỉ lệ' }}</button>
                </div>

                <div class="mt-4 pt-4 border-t border-[var(--color-line)] flex items-center gap-2 text-sm">
                    <span class="w-2 h-2 rounded-full flex-none" :class="Number(cashbackRate) > 0 ? 'bg-[var(--color-brand-green)]' : 'bg-[var(--color-muted)]'"></span>
                    <span class="text-[var(--color-ink)] font-medium">
                        {{ Number(cashbackRate) > 0
                            ? `Đang hoàn ${Number(cashbackRate)}% hoa hồng ròng cho khách.`
                            : 'Đang tắt — chưa trả hoa hồng cho khách nào.' }}
                    </span>
                </div>
            </div>

            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6">
                <h2 class="font-bold text-[var(--color-ink)] mb-1">Tỉ lệ hiển thị cho khách</h2>
                <p class="text-sm text-[var(--color-muted)] leading-relaxed mb-4">
                    Con số khách <strong class="text-[var(--color-ink)]">nhìn thấy</strong> trên trang chủ, trang Hoàn tiền và các bài mẫu ở mục Nội dung quảng bá.
                    <strong class="text-[var(--color-ink)]">Không dùng để tính tiền</strong> — tiền vào ví luôn tính theo tỉ lệ ở khung trên.
                    Để trống là khách thấy đúng tỉ lệ thực. Tắt hoàn tiền (tỉ lệ thực = 0) thì số này cũng tự ẩn.
                </p>

                <div class="flex flex-col sm:flex-row gap-2">
                    <div class="relative flex-1 min-w-0">
                        <input
                            v-model="cashbackDisplayRate"
                            type="number"
                            min="0"
                            max="100"
                            step="1"
                            :placeholder="`Trống = theo tỉ lệ thực (${Number(cashbackRate)}%)`"
                            @keydown.enter="saveCashbackDisplayRate"
                            class="w-full px-4 py-2.5 pr-10 rounded-xl border border-[var(--color-line)] bg-[var(--color-bg)] text-sm text-[var(--color-ink)] tabular-nums focus:outline-none focus:border-[var(--color-accent)]"
                        />
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-[var(--color-muted)] pointer-events-none">%</span>
                    </div>
                    <button
                        type="button"
                        @click="saveCashbackDisplayRate"
                        :disabled="savingCashbackDisplayRate"
                        class="btn-fire px-6 py-2.5 rounded-xl text-sm whitespace-nowrap disabled:opacity-60"
                    >{{ savingCashbackDisplayRate ? 'Đang lưu...' : 'Lưu số hiển thị' }}</button>
                </div>

                <div
                    v-if="String(cashbackDisplayRate).trim() !== '' && Number(cashbackDisplayRate) !== Number(cashbackRate)"
                    class="mt-4 pt-4 border-t border-[var(--color-line)] text-sm text-amber-700 dark:text-amber-400"
                >
                    ⚠️ Khách đang thấy <strong>{{ Number(cashbackDisplayRate) }}%</strong> nhưng ví thực trả <strong>{{ Number(cashbackRate) }}%</strong>.
                    Trang Đơn hàng của khách vẫn ghi tỉ lệ thực vì nó giải thích cách tính từng khoản tiền.
                </div>
            </div>

            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="min-w-0">
                        <h2 class="font-bold text-[var(--color-ink)] mb-1">Thưởng người mới</h2>
                        <p class="text-sm text-[var(--color-muted)] leading-relaxed">
                            Cộng thẳng vào ví ngay khi khách tạo tài khoản (email hoặc Google), kèm thông báo.
                            Khách <strong class="text-[var(--color-ink)]">không rút được</strong> nếu chưa có đơn nào được hoàn tiền thật,
                            nên không sợ cày tài khoản ảo. Đổi số tiền chỉ áp cho tài khoản đăng ký từ đó về sau.
                        </p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="welcomeBonusEnabled"
                        :disabled="savingWelcomeBonus"
                        @click="toggleWelcomeBonus"
                        class="relative inline-flex h-7 w-12 flex-none items-center rounded-full transition-colors disabled:opacity-60"
                        :class="welcomeBonusEnabled ? 'bg-[var(--color-brand-green)]' : 'bg-[var(--color-line)]'"
                    >
                        <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform" :class="welcomeBonusEnabled ? 'translate-x-6' : 'translate-x-1'"></span>
                    </button>
                </div>

                <div class="mt-4 flex flex-col sm:flex-row gap-2">
                    <div class="relative flex-1 min-w-0">
                        <input
                            v-model="welcomeBonusAmount"
                            type="number"
                            min="0"
                            step="1000"
                            @keydown.enter="saveWelcomeBonusAmount"
                            class="w-full px-4 py-2.5 pr-10 rounded-xl border border-[var(--color-line)] bg-[var(--color-bg)] text-sm text-[var(--color-ink)] tabular-nums focus:outline-none focus:border-[var(--color-accent)]"
                        />
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-[var(--color-muted)] pointer-events-none">đ</span>
                    </div>
                    <button
                        type="button"
                        @click="saveWelcomeBonusAmount"
                        :disabled="savingWelcomeBonusAmount"
                        class="btn-fire px-6 py-2.5 rounded-xl text-sm whitespace-nowrap disabled:opacity-60"
                    >{{ savingWelcomeBonusAmount ? 'Đang lưu...' : 'Lưu số tiền' }}</button>
                </div>

                <div class="mt-4 pt-4 border-t border-[var(--color-line)] flex items-center gap-2 text-sm">
                    <span class="w-2 h-2 rounded-full flex-none" :class="welcomeBonusEnabled && Number(welcomeBonusAmount) > 0 ? 'bg-[var(--color-brand-green)]' : 'bg-[var(--color-muted)]'"></span>
                    <span class="text-[var(--color-ink)] font-medium">
                        {{ welcomeBonusEnabled && Number(welcomeBonusAmount) > 0
                            ? `Đang tặng ${Number(welcomeBonusAmount).toLocaleString('vi-VN')} đ cho mỗi tài khoản mới.`
                            : 'Đang tắt — tài khoản mới không được thưởng.' }}
                    </span>
                </div>
            </div>

            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6">
                <h2 class="font-bold text-[var(--color-ink)] mb-1">Link cộng đồng săn sale</h2>
                <p class="text-sm text-[var(--color-muted)] leading-relaxed mb-4">
                    Hiện ở dòng cuối banner <strong class="text-[var(--color-ink)]">"Săn sale mỗi ngày"</strong> (khung giờ back mã)
                    trên trang chủ và trang kết quả. Dán link nhóm Zalo, Telegram hoặc Facebook đều được.
                    Để trống thì banner tự ẩn dòng link đi.
                </p>

                <div class="flex flex-col sm:flex-row gap-2">
                    <input
                        v-model="communityUrl"
                        type="url"
                        placeholder="https://zalo.me/g/..."
                        @keydown.enter="saveCommunityUrl"
                        class="flex-1 min-w-0 px-4 py-2.5 rounded-xl border border-[var(--color-line)] bg-[var(--color-bg)] text-sm text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]"
                    />
                    <button
                        type="button"
                        @click="saveCommunityUrl"
                        :disabled="savingCommunityUrl"
                        class="btn-fire px-6 py-2.5 rounded-xl text-sm whitespace-nowrap disabled:opacity-60"
                    >{{ savingCommunityUrl ? 'Đang lưu...' : 'Lưu link' }}</button>
                </div>
            </div>

            <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-6">
                <h2 class="font-bold text-[var(--color-ink)] mb-1">💬 Icon Messenger cho khách nhắn tin</h2>
                <p class="text-sm text-[var(--color-muted)] leading-relaxed mb-4">
                    Hiện icon Messenger nổi ở góc màn hình trên mọi trang khách — bấm vào sẽ nhảy thẳng sang
                    Messenger để chat với fanpage. Dán link dạng <span class="font-mono text-xs">https://m.me/tenpage</span>
                    (lấy trong phần Cài đặt trang trên Facebook). Để trống thì icon tự ẩn.
                </p>

                <div class="flex flex-col sm:flex-row gap-2">
                    <input
                        v-model="messengerUrl"
                        type="url"
                        placeholder="https://m.me/tenpage"
                        @keydown.enter="saveMessengerUrl"
                        class="flex-1 min-w-0 px-4 py-2.5 rounded-xl border border-[var(--color-line)] bg-[var(--color-bg)] text-sm text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]"
                    />
                    <button
                        type="button"
                        @click="saveMessengerUrl"
                        :disabled="savingMessengerUrl"
                        class="btn-fire px-6 py-2.5 rounded-xl text-sm whitespace-nowrap disabled:opacity-60"
                    >{{ savingMessengerUrl ? 'Đang lưu...' : 'Lưu link' }}</button>
                </div>

                <div class="mt-4 pt-4 border-t border-[var(--color-line)] flex items-center gap-2 text-sm">
                    <span class="w-2 h-2 rounded-full flex-none" :class="messengerUrl.trim() ? 'bg-[var(--color-brand-green)]' : 'bg-[var(--color-muted)]'"></span>
                    <span class="text-[var(--color-ink)] font-medium">
                        {{ messengerUrl.trim() ? 'Đang hiện icon Messenger trên trang khách.' : 'Đang ẩn — chưa đặt link Messenger.' }}
                    </span>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
