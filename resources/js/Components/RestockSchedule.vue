<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { usePage } from '@inertiajs/vue3'

// Banner "Săn sale mỗi ngày": khung giờ nguồn cấp mã nạp lại lượt (back mã) + đếm ngược tới
// đợt gần nhất. Khách hay dán link đúng lúc hết lượt rồi tưởng công cụ hỏng, nên phải nói trước.
// Giờ tính theo Việt Nam (UTC+7) chứ không theo đồng hồ máy khách — khách ở múi giờ khác vẫn đúng.
// `apps` là logo ứng dụng hiện thay cho chữ viết tắt (YTB, FB-IG) — khách nhìn logo nhận ra
// kênh nhanh hơn đọc chữ; `appsText` dành cho screen reader và tooltip.
const GROUPS = [
    { key: 'youtube', apps: ['youtube'], appsText: 'YouTube', hours: [0, 9, 12, 18] },
    { key: 'igfb', apps: ['facebook', 'instagram'], appsText: 'Facebook, Instagram', hours: [0, 9, 15, 20] },
]

const VN_OFFSET_MINUTES = 7 * 60
const JUST_RESTOCKED_WINDOW = 15 * 60 // vừa qua mốc dưới 15 phút thì mã còn nhiều

const page = usePage()
const communityUrl = computed(() => page.props.settings?.communityUrl || null)

const now = ref(Date.now())
let timer = null

// 30 giây/lần, KHÔNG phải 1 giây/lần. Hai cái đồng hồ nhảy từng giây là hai thứ đang động
// cùng lúc ngay đầu trang chủ, trong khi mốc gần nhất cách đây hàng giờ — từng giây không
// giúp khách quyết định gì, chỉ kéo mắt khỏi ô dán link. Bỏ luôn phần giây trong chữ hiển
// thị thì 30 giây/lần là đủ chính xác, và đỡ cho pin máy khi mở trong webview Facebook/Zalo.
onMounted(() => { timer = setInterval(() => { now.value = Date.now() }, 30000) })
onUnmounted(() => { if (timer) clearInterval(timer) })

// Số giây đã trôi qua kể từ 0h hôm nay theo giờ Việt Nam.
const secondsIntoVnDay = computed(() => {
    const vnMs = now.value + (VN_OFFSET_MINUTES + new Date(now.value).getTimezoneOffset()) * 60000
    const d = new Date(vnMs)
    return d.getHours() * 3600 + d.getMinutes() * 60 + d.getSeconds()
})

// Làm tròn LÊN theo phút: "Còn 0 phút" trong lúc vẫn còn 40 giây là nói sai với khách.
// Làm tròn trên TỔNG số phút rồi mới tách giờ/phút — tách trước rồi làm tròn thì 2 giờ
// 59 phút 59 giây ra "còn 2 giờ 0 phút", sai mất gần một tiếng.
function remainText(sec) {
    const totalMin = Math.ceil(sec / 60)
    const h = Math.floor(totalMin / 60)
    const m = totalMin % 60

    if (h > 0) return m > 0 ? `Còn ${h} giờ ${m} phút` : `Còn ${h} giờ`
    if (m > 0) return `Còn ${m} phút`

    return 'Sắp tới'
}

const groups = computed(() => GROUPS.map(g => {
    const s = secondsIntoVnDay.value
    // Mốc kế tiếp là giờ đầu tiên còn ở phía trước; hết mốc trong ngày thì vòng sang mai.
    const upcoming = g.hours.find(h => h * 3600 > s)
    const remain = upcoming !== undefined ? upcoming * 3600 - s : 86400 - s + g.hours[0] * 3600

    return {
        ...g,
        hoursText: g.hours.join('-') + 'h',
        countdown: remainText(remain),
        justRestocked: g.hours.some(h => {
            const diff = s - h * 3600
            return diff >= 0 && diff < JUST_RESTOCKED_WINDOW
        }),
    }
}))
</script>

<template>
    <!-- Bản cũ là một tấm gradient cam đặc, cao ~210px, tiêu đề in nghiêng viết hoa 28px và
         vạt chéo clip-path. Nó to và cam hơn cả nút hành động chính của trang, nên khách
         nhìn vào banner trước khi nhìn vào ô dán link — trong khi đây chỉ là một lời nhắc
         lịch. Giờ là thẻ thường, nền cam nhạt vừa đủ để tách khỏi các thẻ khác, tiêu đề một
         dòng, cao khoảng 170px. -->
    <div class="card overflow-hidden p-4 bg-[var(--color-peach-soft)] border-[rgba(var(--color-accent-rgb),0.25)]">
        <div class="flex items-center gap-2 mb-2.5">
            <svg viewBox="0 0 24 24" class="w-5 h-5 flex-none" fill="var(--color-accent)" stroke="var(--color-accent)" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true">
                <path d="M13 2 4.5 13.2h6L11 22l8.5-11.2h-6L13 2Z" />
            </svg>
            <h3 class="text-base font-extrabold text-[var(--color-ink)] leading-tight">Săn sale mỗi ngày</h3>
            <!-- Nhắc múi giờ nằm ngay hàng tiêu đề: khách mở trang từ nước ngoài (hoặc máy
                 đặt sai múi giờ) mà không thấy dòng này là hiểu nhầm cả cái lịch bên dưới. -->
            <span class="ml-auto flex-none text-xs font-semibold text-[var(--color-muted)]">giờ VN</span>
        </div>

        <!-- Hai khung giờ back mã -->
        <div class="space-y-2">
            <div
                v-for="g in groups"
                :key="g.key"
                class="panel flex items-center gap-2 px-3 py-2"
            >
                <p class="flex-1 min-w-0 flex items-center flex-wrap gap-x-1.5 gap-y-1 text-xs text-[var(--color-ink)] leading-snug">
                    <!-- "Giờ có mã <logo>: 0-9-12-18h" đọc trôi. Bản rút gọn "Có mã lúc"
                         thì ngay sau nó là LOGO KÊNH chứ không phải giờ, thành ra câu đọc
                         lên là "có mã lúc YouTube" — sai nghĩa với người Việt. -->
                    <span>Giờ có mã</span>
                    <!-- Logo ứng dụng: ô bo góc màu thương hiệu, cỡ 20px cho vừa dòng chữ -->
                    <span class="inline-flex items-center gap-1" :title="g.appsText">
                        <span class="sr-only">{{ g.appsText }}</span>
                        <template v-for="app in g.apps" :key="app">
                            <svg v-if="app === 'youtube'" viewBox="0 0 24 24" class="w-5 h-5 flex-none" aria-hidden="true">
                                <rect width="24" height="24" rx="6" fill="#FF0000" />
                                <path d="M9.5 8.2v7.6l6.5-3.8-6.5-3.8Z" fill="#fff" />
                            </svg>
                            <svg v-else-if="app === 'facebook'" viewBox="0 0 24 24" class="w-5 h-5 flex-none" aria-hidden="true">
                                <rect width="24" height="24" rx="6" fill="#1877F2" />
                                <path d="M13.6 21v-6.9h2.3l.4-2.8h-2.7V9.6c0-.8.3-1.4 1.4-1.4h1.4V5.7c-.3 0-1.1-.1-2.1-.1-2.1 0-3.5 1.3-3.5 3.6v2.1H8.5v2.8h2.3V21h2.8Z" fill="#fff" />
                            </svg>
                            <svg v-else-if="app === 'instagram'" viewBox="0 0 24 24" class="w-5 h-5 flex-none" aria-hidden="true">
                                <defs>
                                    <linearGradient :id="`ig-grad-${g.key}`" x1="0" y1="1" x2="1" y2="0">
                                        <stop offset="0" stop-color="#F9CE34" />
                                        <stop offset=".5" stop-color="#EE2A7B" />
                                        <stop offset="1" stop-color="#6228D7" />
                                    </linearGradient>
                                </defs>
                                <rect width="24" height="24" rx="6" :fill="`url(#ig-grad-${g.key})`" />
                                <rect x="5.5" y="5.5" width="13" height="13" rx="3.8" fill="none" stroke="#fff" stroke-width="1.7" />
                                <circle cx="12" cy="12" r="3" fill="none" stroke="#fff" stroke-width="1.7" />
                                <circle cx="15.7" cy="8.3" r="1" fill="#fff" />
                            </svg>
                        </template>
                    </span>
                    <span class="num">: <b class="font-extrabold whitespace-nowrap">{{ g.hoursText }}</b></span>
                </p>
                <!-- Mã vừa về = tin mừng (màu tiền); còn phải đợi = màu chờ. -->
                <span
                    v-if="g.justRestocked"
                    class="flex-none rounded-lg bg-[var(--color-money-soft)] text-[var(--color-money)] text-xs font-extrabold px-2 py-1 whitespace-nowrap"
                >Mã vừa về 🔥</span>
                <!-- Chú thích đặt g.countdown ở CUỐI câu: chuỗi đó có lúc là "Sắp tới",
                     nhét vào đầu thì thành "Sắp tới nữa tới đợt back mã kế tiếp". -->
                <span
                    v-else
                    :title="`Đợt có mã kế tiếp (giờ Việt Nam): ${g.countdown}`"
                    class="num flex-none rounded-lg bg-[var(--color-warn-soft)] text-[var(--color-warn)] text-xs font-bold px-2 py-1 whitespace-nowrap"
                >{{ g.countdown }}</span>
            </div>
        </div>

        <!-- Chân banner: link cộng đồng (nếu admin đã đặt) + nhắc múi giờ -->
        <a
            v-if="communityUrl"
            :href="communityUrl"
            target="_blank"
            rel="noopener"
            class="focus-ring mt-2 flex items-center min-h-[44px] text-sm font-semibold text-[var(--color-ink)] underline underline-offset-2 decoration-[var(--color-muted)] hover:decoration-[var(--color-ink)]"
        >Bấm vào đây để tham gia cộng đồng săn sale</a>
        <p v-else class="mt-2 text-xs text-[var(--color-muted)] leading-snug">
            Hết lượt thì quay lại đúng khung giờ có mã nhé.
        </p>
    </div>
</template>
