<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { usePage } from '@inertiajs/vue3'

// Banner "Săn sale mỗi ngày": khung giờ nguồn cấp mã nạp lại lượt (back mã) + đếm ngược tới
// đợt gần nhất. Khách hay dán link đúng lúc hết lượt rồi tưởng công cụ hỏng, nên phải nói trước.
// Giờ tính theo Việt Nam (UTC+7) chứ không theo đồng hồ máy khách — khách ở múi giờ khác vẫn đúng.
const GROUPS = [
    { key: 'youtube', label: 'Khung giờ YTB', hours: [0, 9, 12, 18] },
    { key: 'igfb', label: 'Khung giờ FB-IG', hours: [0, 9, 15, 20] },
]

const VN_OFFSET_MINUTES = 7 * 60
const JUST_RESTOCKED_WINDOW = 15 * 60 // vừa qua mốc dưới 15 phút thì mã còn nhiều

const page = usePage()
const communityUrl = computed(() => page.props.settings?.communityUrl || null)

const now = ref(Date.now())
let timer = null

onMounted(() => { timer = setInterval(() => { now.value = Date.now() }, 1000) })
onUnmounted(() => { if (timer) clearInterval(timer) })

function pad(n) {
    return String(n).padStart(2, '0')
}

// Số giây đã trôi qua kể từ 0h hôm nay theo giờ Việt Nam.
const secondsIntoVnDay = computed(() => {
    const vnMs = now.value + (VN_OFFSET_MINUTES + new Date(now.value).getTimezoneOffset()) * 60000
    const d = new Date(vnMs)
    return d.getHours() * 3600 + d.getMinutes() * 60 + d.getSeconds()
})

const groups = computed(() => GROUPS.map(g => {
    const s = secondsIntoVnDay.value
    // Mốc kế tiếp là giờ đầu tiên còn ở phía trước; hết mốc trong ngày thì vòng sang mai.
    const upcoming = g.hours.find(h => h * 3600 > s)
    const remain = upcoming !== undefined ? upcoming * 3600 - s : 86400 - s + g.hours[0] * 3600

    return {
        ...g,
        hoursText: g.hours.join('-') + 'h',
        countdown: `${pad(Math.floor(remain / 3600))}:${pad(Math.floor(remain / 60) % 60)}:${pad(remain % 60)}`,
        justRestocked: g.hours.some(h => {
            const diff = s - h * 3600
            return diff >= 0 && diff < JUST_RESTOCKED_WINDOW
        }),
    }
}))
</script>

<template>
    <!-- border-radius bo góc trước, clip-path cắt vạt chéo góc dưới phải sau — hai thứ này
         giao nhau nên vừa bo tròn vừa có vạt như banner gốc. -->
    <div
        class="relative rounded-2xl px-4 py-4 md:px-5 md:py-5 shadow-[0_10px_30px_rgba(234,88,12,.35)] bg-[linear-gradient(115deg,#FF5F00_0%,#FF7A00_45%,#FFA81F_100%)]"
        style="clip-path: polygon(0 0, 100% 0, 100% calc(100% - 30px), calc(100% - 26px) 100%, 0 100%)"
    >
        <!-- Tiêu đề -->
        <div class="flex items-center gap-3 mb-3.5">
            <span class="flex-none w-11 h-11 rounded-2xl bg-[#C7250B] shadow-[0_3px_10px_rgba(0,0,0,.28)] flex items-center justify-center">
                <svg viewBox="0 0 24 24" class="w-6 h-6" fill="#FFD24A" stroke="#FFD24A" stroke-width="1.5" stroke-linejoin="round">
                    <path d="M13 2 4.5 13.2h6L11 22l8.5-11.2h-6L13 2Z" />
                </svg>
            </span>
            <h3 class="italic font-black uppercase leading-[0.95] text-white text-2xl md:text-[28px] tracking-tight [text-shadow:0_2px_0_rgba(140,40,0,.45)]">
                Săn sale<br />mỗi ngày
            </h3>
            <span class="ml-auto self-start flex-none rounded-full bg-black/25 text-white/85 text-[10px] font-bold uppercase tracking-wide px-2 py-1">giờ VN</span>
        </div>

        <!-- Hai khung giờ back mã -->
        <div class="space-y-2">
            <div
                v-for="g in groups"
                :key="g.key"
                class="flex items-center gap-2 rounded-2xl bg-white/18 border border-white/25 pl-3 pr-1.5 py-1.5"
            >
                <svg viewBox="0 0 24 24" class="w-4 h-4 flex-none text-white/85" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <circle cx="12" cy="13" r="8" /><path d="M12 9.5V13l2.2 1.6M9 2h6" />
                </svg>
                <p class="flex-1 min-w-0 text-white text-[13px] md:text-sm leading-snug">
                    {{ g.label }}: <b class="font-extrabold whitespace-nowrap">{{ g.hoursText }}</b>
                </p>
                <span
                    v-if="g.justRestocked"
                    class="flex-none rounded-lg bg-[#0B7A46] text-white text-[11px] md:text-xs font-extrabold px-2.5 py-1.5 whitespace-nowrap"
                >VỪA BACK 🔥</span>
                <span
                    v-else
                    :title="`Còn ${g.countdown} nữa tới đợt back mã kế tiếp (giờ Việt Nam)`"
                    class="flex-none rounded-lg bg-black/35 text-[#FFD9A0] text-[13px] md:text-sm font-mono font-bold tabular-nums px-2.5 py-1 whitespace-nowrap"
                >{{ g.countdown }}</span>
            </div>
        </div>

        <!-- Chân banner: link cộng đồng (nếu admin đã đặt) + nhắc múi giờ -->
        <div class="mt-3 flex items-start gap-2 pr-7">
            <span class="w-1.5 h-1.5 mt-[7px] rounded-full bg-[#FFE8A3] flex-none"></span>
            <a
                v-if="communityUrl"
                :href="communityUrl"
                target="_blank"
                rel="noopener"
                class="text-white text-[13px] md:text-sm font-semibold leading-snug underline underline-offset-2 decoration-white/60 hover:decoration-white"
            >Bấm vào đây để tham gia cộng đồng săn sale</a>
            <span v-else class="text-white/90 text-[13px] md:text-sm leading-snug">Hết lượt thì quay lại đúng khung giờ trên nhé</span>
        </div>
    </div>
</template>
