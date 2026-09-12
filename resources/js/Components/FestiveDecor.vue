<script setup>
/**
 * Lớp trang trí theo mùa (Trung thu) trôi lơ lửng đè lên trang khách.
 *
 * Vẽ bằng SVG inline thay vì emoji: emoji mỗi máy render một kiểu, phẳng, không có bóng hay
 * ánh sáng nên nhìn rẻ. SVG ở đây có gradient + glow, và vẫn không tải ảnh nào — trang chủ
 * đang được chấm tốc độ trên điện thoại, không đắp thêm request vì hoa lá.
 *
 * pointer-events-none bắt buộc: lớp này phủ full màn hình, giữ sự kiện chuột là khách không
 * bấm được nút nào phía dưới. z-20 nằm dưới header (z-50), khung dán link (z-30) và BottomNav
 * (z-50) để không che thứ khách cần thao tác; chỉ trôi qua nội dung đọc.
 *
 * Bố cục: món to (trăng, mây) dồn về mép và để mờ, món nhỏ (đèn, bánh, sao) rải thưa; tránh
 * đặt gì ở dải giữa 25-75% chiều ngang trong 1/3 màn hình trên — đó là chỗ ô dán link.
 * Keyframes ở app.css (festive-*). Thời lượng/độ trễ lệch nhau để không nhún đồng loạt.
 */
import { useFestive } from '@/composables/useFestive'

// Bật khi Home.vue báo tìm mã xong: Cuội (kèm cả cây đa — đúng tích) bay lên cạnh chị Hằng
// rồi về; chị Hằng nhún chào; thêm mấy ngôi sao loé quanh chỗ hai người gặp nhau.
const { flying } = useFestive()

const items = [
    // Trăng: to, mờ, nửa lọt ra ngoài góc trên phải để chỉ thấy quầng sáng tràn vào.
    { kind: 'moon', left: '70%', top: '3%', w: 'w-40 md:w-56', anim: 'festive-glow', duration: '7s', delay: '0s', opacity: 0.85 },

    // Chị Hằng bay cạnh trăng, chú Cuội ngồi gốc đa ở góc dưới trái (trên BottomNav một chút).
    // Lúc ăn mừng hai người GẶP NHAU Ở GIỮA màn hình bên phải (~55vh, xem festiveFly/Greet):
    // vùng trên cùng bị header + khung dán link dính che (tới ~40vh trên điện thoại thấp) nên
    // hẹn ở trên đó là khách không thấy gì cả.
    { kind: 'hang', left: '74%', top: '12%', w: 'w-20 md:w-28', anim: 'festive-float', duration: '8s', delay: '0.5s', opacity: 1 },
    { kind: 'cuoi', left: '1%', top: '72%', w: 'w-28 md:w-40', anim: 'festive-float', duration: '9s', delay: '1s', opacity: 1 },

    // Đèn lồng treo từ mép trên (có dây), đung đưa quanh điểm treo.
    { kind: 'lantern', left: '3%', top: '2%', w: 'w-12 md:w-16', anim: 'festive-sway', duration: '4.5s', delay: '0s', opacity: 1 },
    { kind: 'lantern', left: '14%', top: '-2%', w: 'w-8 md:w-11', anim: 'festive-sway', duration: '5.5s', delay: '1.3s', opacity: 0.9 },
    { kind: 'lantern', left: '90%', top: '28%', w: 'w-10 md:w-14', anim: 'festive-sway', duration: '5s', delay: '0.7s', opacity: 1 },
    { kind: 'lantern', left: '2%', top: '46%', w: 'w-9 md:w-12', anim: 'festive-sway', duration: '6s', delay: '2s', opacity: 0.9 },

    // Bánh trung thu nhấp nhô nhẹ.
    { kind: 'mooncake', left: '91%', top: '62%', w: 'w-8 md:w-14', anim: 'festive-float', duration: '6.5s', delay: '0.4s', opacity: 1 },
    { kind: 'mooncake', left: '34%', top: '90%', w: 'w-7 md:w-11', anim: 'festive-float', duration: '7.5s', delay: '1.8s', opacity: 0.95 },

    // Sao lấp lánh: nhỏ, mờ-sáng lệch pha.
    { kind: 'sparkle', left: '26%', top: '14%', w: 'w-4 md:w-5', anim: 'festive-twinkle', duration: '2.6s', delay: '0s', opacity: 1 },
    { kind: 'sparkle', left: '64%', top: '22%', w: 'w-3 md:w-4', anim: 'festive-twinkle', duration: '3.1s', delay: '0.9s', opacity: 1 },
    { kind: 'sparkle', left: '88%', top: '50%', w: 'w-4', anim: 'festive-twinkle', duration: '2.3s', delay: '1.5s', opacity: 1 },
    { kind: 'sparkle', left: '18%', top: '46%', w: 'w-3', anim: 'festive-twinkle', duration: '2.9s', delay: '0.4s', opacity: 1 },
    { kind: 'sparkle', left: '52%', top: '88%', w: 'w-4 md:w-5', anim: 'festive-twinkle', duration: '2.7s', delay: '2s', opacity: 1 },

    // Mây trôi ngang cả màn hình, rất mờ để chỉ là nền.
    { kind: 'cloud', left: '-20%', top: '34%', w: 'w-32 md:w-56', anim: 'festive-drift', duration: '55s', delay: '0s', opacity: 0.32 },
    { kind: 'cloud', left: '-20%', top: '74%', w: 'w-24 md:w-40', anim: 'festive-drift', duration: '70s', delay: '-30s', opacity: 0.28 },
]

const petals = [0, 45, 90, 135, 180, 225, 270, 315]

// Rải quanh điểm hẹn (bên phải, ~50-70vh) — nơi Cuội bay lên và chị Hằng bay xuống gặp nhau.
const meetSparkles = [
    { left: '58%', top: '48%', w: 'w-4 md:w-5', duration: '1.1s', delay: '2.5s' },
    { left: '66%', top: '72%', w: 'w-3 md:w-4', duration: '1.3s', delay: '2.9s' },
    { left: '93%', top: '52%', w: 'w-4', duration: '1s', delay: '3.2s' },
    { left: '54%', top: '62%', w: 'w-3', duration: '1.2s', delay: '3.6s' },
    { left: '86%', top: '76%', w: 'w-4 md:w-5', duration: '1.1s', delay: '4s' },
]
</script>

<template>
    <div class="fixed inset-0 z-20 pointer-events-none overflow-hidden select-none" aria-hidden="true">
        <svg class="absolute w-0 h-0" aria-hidden="true">
            <defs>
                <radialGradient id="fd-moon" cx="40%" cy="38%" r="65%">
                    <stop offset="0%" stop-color="#fff9dc" />
                    <stop offset="55%" stop-color="#ffe38a" />
                    <stop offset="100%" stop-color="#f4b73f" />
                </radialGradient>
                <radialGradient id="fd-halo" cx="50%" cy="50%" r="50%">
                    <stop offset="55%" stop-color="#ffd46a" stop-opacity="0.45" />
                    <stop offset="100%" stop-color="#ffd46a" stop-opacity="0" />
                </radialGradient>
                <linearGradient id="fd-lantern" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0%" stop-color="#b3121b" />
                    <stop offset="45%" stop-color="#ff4d3d" />
                    <stop offset="100%" stop-color="#b3121b" />
                </linearGradient>
                <radialGradient id="fd-lantern-glow" cx="50%" cy="50%" r="50%">
                    <stop offset="40%" stop-color="#ff8a3d" stop-opacity="0.55" />
                    <stop offset="100%" stop-color="#ff8a3d" stop-opacity="0" />
                </radialGradient>
                <linearGradient id="fd-gold" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#ffe89a" />
                    <stop offset="100%" stop-color="#d69a1e" />
                </linearGradient>
                <radialGradient id="fd-cake" cx="45%" cy="40%" r="60%">
                    <stop offset="0%" stop-color="#f7c874" />
                    <stop offset="100%" stop-color="#c4801f" />
                </radialGradient>
                <linearGradient id="fd-dress" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#ffffff" />
                    <stop offset="60%" stop-color="#ffd9e8" />
                    <stop offset="100%" stop-color="#f6a5c9" stop-opacity="0.85" />
                </linearGradient>
                <linearGradient id="fd-ribbon" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#bde3ff" />
                    <stop offset="100%" stop-color="#e9c8ff" />
                </linearGradient>
                <radialGradient id="fd-leaf" cx="40%" cy="35%" r="65%">
                    <stop offset="0%" stop-color="#8fd66a" />
                    <stop offset="100%" stop-color="#2f8f3f" />
                </radialGradient>
                <radialGradient id="fd-spark" cx="50%" cy="50%" r="50%">
                    <stop offset="0%" stop-color="#fffbe6" />
                    <stop offset="100%" stop-color="#ffd35c" />
                </radialGradient>
            </defs>
        </svg>

        <!-- Sao loé quanh chỗ Cuội gặp chị Hằng, chỉ hiện trong lúc bay. -->
        <template v-if="flying">
            <div
                v-for="(spot, i) in meetSparkles"
                :key="'meet-' + i"
                class="absolute festive-twinkle"
                :class="spot.w"
                :style="{ left: spot.left, top: spot.top, animationDuration: spot.duration, animationDelay: spot.delay }"
            >
                <svg viewBox="0 0 40 40" class="w-full h-auto drop-shadow-[0_0_8px_rgba(255,214,90,1)]">
                    <path d="M20 2 Q22 18 38 20 Q22 22 20 38 Q18 22 2 20 Q18 18 20 2 Z" fill="url(#fd-spark)" />
                </svg>
            </div>
        </template>

        <div
            v-for="(item, i) in items"
            :key="i"
            class="absolute will-change-transform"
            :class="[item.w, item.anim, flying && item.kind === 'cuoi' ? 'festive-fly' : '', flying && item.kind === 'hang' ? 'festive-greet' : '']"
            :style="{ left: item.left, top: item.top, opacity: item.opacity, animationDuration: item.duration, animationDelay: item.delay }"
        >
            <!-- Trăng: quầng sáng rộng + đĩa trăng gradient + vài vết mờ. -->
            <svg v-if="item.kind === 'moon'" viewBox="0 0 200 200" class="w-full h-auto">
                <circle cx="100" cy="100" r="100" fill="url(#fd-halo)" />
                <circle cx="100" cy="100" r="58" fill="url(#fd-moon)" />
                <circle cx="82" cy="84" r="9" fill="#e9b24a" opacity="0.28" />
                <circle cx="116" cy="112" r="6" fill="#e9b24a" opacity="0.24" />
                <circle cx="96" cy="126" r="4" fill="#e9b24a" opacity="0.2" />
            </svg>

            <!-- Chị Hằng: kiểu chibi đầu to, áo dài trắng hồng bay, dải lụa xanh tím uốn quanh. -->
            <svg v-else-if="item.kind === 'hang'" viewBox="0 0 140 220" class="w-full h-auto drop-shadow-[0_6px_12px_rgba(120,80,160,0.3)]">
                <path d="M18 120 C0 90 30 60 60 78 C90 96 120 60 132 30" fill="none" stroke="url(#fd-ribbon)" stroke-width="9" stroke-linecap="round" opacity="0.9" />
                <path d="M96 150 C120 140 134 165 122 190" fill="none" stroke="url(#fd-ribbon)" stroke-width="8" stroke-linecap="round" opacity="0.85" />
                <path d="M70 92 C40 110 30 160 40 210 C60 200 80 200 100 210 C110 160 100 110 70 92 Z" fill="url(#fd-dress)" />
                <path d="M52 100 C34 106 26 122 24 140 C34 132 44 128 52 128 Z" fill="#fff" opacity="0.95" />
                <path d="M88 100 C106 106 114 122 116 140 C106 132 96 128 88 128 Z" fill="#fff" opacity="0.95" />
                <path d="M58 96 L82 96 L86 120 L54 120 Z" fill="#f6a5c9" opacity="0.6" />
                <circle cx="70" cy="60" r="27" fill="#ffe4cf" />
                <path d="M43 56 C43 30 97 30 97 56 C90 44 82 40 70 40 C58 40 50 44 43 56 Z" fill="#2b2140" />
                <circle cx="70" cy="30" r="10" fill="#2b2140" />
                <circle cx="54" cy="36" r="6" fill="#2b2140" />
                <circle cx="86" cy="36" r="6" fill="#2b2140" />
                <path d="M62 26 L78 22" stroke="#ffd35c" stroke-width="3" stroke-linecap="round" />
                <circle cx="80" cy="21" r="3.5" fill="#ffd35c" />
                <path d="M58 60 q4 -4 8 0 M74 60 q4 -4 8 0" fill="none" stroke="#2b2140" stroke-width="2.4" stroke-linecap="round" />
                <path d="M64 72 q6 5 12 0" fill="none" stroke="#d9707a" stroke-width="2" stroke-linecap="round" />
                <circle cx="56" cy="68" r="4" fill="#ffb3b3" opacity="0.6" />
                <circle cx="84" cy="68" r="4" fill="#ffb3b3" opacity="0.6" />
            </svg>

            <!-- Chú Cuội ngồi gốc cây đa: tán lá 3 tầng, rễ phụ rủ xuống, cậu bé áo nâu ôm gối. -->
            <svg v-else-if="item.kind === 'cuoi'" viewBox="0 0 200 200" class="w-full h-auto drop-shadow-[0_6px_10px_rgba(20,60,20,0.3)]">
                <ellipse cx="100" cy="190" rx="80" ry="8" fill="#2f8f3f" opacity="0.25" />
                <path d="M96 190 C94 150 92 120 100 92 C108 120 106 150 104 190 Z" fill="#7a4a22" />
                <path d="M100 100 C80 110 70 130 62 150 M100 100 C120 110 130 130 138 150" fill="none" stroke="#7a4a22" stroke-width="5" stroke-linecap="round" />
                <path d="M78 118 L74 166 M124 118 L128 166 M90 106 L84 150" fill="none" stroke="#9a6a3a" stroke-width="2.5" stroke-linecap="round" />
                <circle cx="100" cy="78" r="46" fill="url(#fd-leaf)" />
                <circle cx="60" cy="90" r="30" fill="url(#fd-leaf)" />
                <circle cx="142" cy="88" r="32" fill="url(#fd-leaf)" />
                <circle cx="82" cy="46" r="22" fill="url(#fd-leaf)" />
                <circle cx="122" cy="50" r="24" fill="url(#fd-leaf)" />
                <circle cx="78" cy="62" r="7" fill="#c8f0a8" opacity="0.5" />
                <circle cx="116" cy="40" r="5" fill="#c8f0a8" opacity="0.5" />
                <ellipse cx="128" cy="176" rx="20" ry="9" fill="#5b3a86" />
                <path d="M110 150 C108 168 122 176 140 174 C146 160 140 146 128 144 Z" fill="#8b5a2b" />
                <rect x="112" y="166" width="16" height="10" rx="4" fill="#ffe4cf" />
                <rect x="126" y="166" width="16" height="10" rx="4" fill="#ffe4cf" />
                <circle cx="128" cy="134" r="18" fill="#ffe4cf" />
                <path d="M110 130 C112 112 144 112 146 130 C140 122 134 120 128 120 C122 120 116 122 110 130 Z" fill="#1f1a2e" />
                <path d="M126 116 q4 -10 10 -6" fill="none" stroke="#1f1a2e" stroke-width="3" stroke-linecap="round" />
                <path d="M120 134 q3 -3 6 0 M132 134 q3 -3 6 0" fill="none" stroke="#1f1a2e" stroke-width="2.2" stroke-linecap="round" />
                <path d="M124 142 q4 4 8 0" fill="none" stroke="#c0605a" stroke-width="2" stroke-linecap="round" />
                <circle cx="118" cy="140" r="3.5" fill="#ffb3b3" opacity="0.6" />
                <circle cx="138" cy="140" r="3.5" fill="#ffb3b3" opacity="0.6" />
                <path d="M146 150 L164 138" stroke="#d6b27a" stroke-width="4" stroke-linecap="round" />
                <circle cx="166" cy="136" r="4" fill="#ffd35c" />
            </svg>

            <!-- Đèn lồng: dây treo, nắp, thân gradient với gân, đáy, tua. Xoay quanh điểm treo
                 (transform-origin đặt ở CSS .festive-sway). -->
            <svg v-else-if="item.kind === 'lantern'" viewBox="0 0 80 150" class="w-full h-auto drop-shadow-[0_6px_10px_rgba(255,80,40,0.35)]">
                <circle cx="40" cy="66" r="40" fill="url(#fd-lantern-glow)" />
                <line x1="40" y1="0" x2="40" y2="22" stroke="#d69a1e" stroke-width="2" />
                <rect x="27" y="20" width="26" height="8" rx="3" fill="url(#fd-gold)" />
                <ellipse cx="40" cy="66" rx="32" ry="36" fill="url(#fd-lantern)" />
                <ellipse cx="40" cy="66" rx="17" ry="36" fill="none" stroke="#ffb27a" stroke-opacity="0.55" stroke-width="1.5" />
                <ellipse cx="40" cy="66" rx="4" ry="36" fill="none" stroke="#ffb27a" stroke-opacity="0.55" stroke-width="1.5" />
                <ellipse cx="30" cy="52" rx="8" ry="12" fill="#fff" opacity="0.12" />
                <rect x="27" y="100" width="26" height="8" rx="3" fill="url(#fd-gold)" />
                <line x1="40" y1="108" x2="40" y2="122" stroke="#d69a1e" stroke-width="2" />
                <circle cx="40" cy="124" r="3.5" fill="url(#fd-gold)" />
                <path d="M34 127 L32 148 M37 127 L36 148 M40 127 L40 148 M43 127 L44 148 M46 127 L48 148" stroke="#e0342e" stroke-width="1.6" stroke-linecap="round" />
            </svg>

            <!-- Bánh trung thu: đĩa gradient, viền cánh hoa, hoa văn tròn ở giữa. -->
            <svg v-else-if="item.kind === 'mooncake'" viewBox="0 0 100 100" class="w-full h-auto drop-shadow-[0_4px_8px_rgba(120,70,0,0.35)]">
                <circle cx="50" cy="50" r="46" fill="url(#fd-cake)" />
                <g fill="#f4c56d" stroke="#8a5a12" stroke-opacity="0.55" stroke-width="2">
                    <circle cx="50" cy="50" r="36" fill="none" />
                    <path v-for="deg in petals" :key="deg" :transform="'rotate(' + deg + ' 50 50)'" d="M50 14 Q60 26 50 36 Q40 26 50 14 Z" />
                    <circle cx="50" cy="50" r="14" fill="none" />
                </g>
                <circle cx="50" cy="50" r="6" fill="#8a5a12" opacity="0.5" />
                <ellipse cx="36" cy="32" rx="9" ry="6" fill="#fff" opacity="0.18" />
            </svg>

            <!-- Sao 4 cánh phát sáng. -->
            <svg v-else-if="item.kind === 'sparkle'" viewBox="0 0 40 40" class="w-full h-auto drop-shadow-[0_0_6px_rgba(255,214,90,0.9)]">
                <path d="M20 2 Q22 18 38 20 Q22 22 20 38 Q18 22 2 20 Q18 18 20 2 Z" fill="url(#fd-spark)" />
            </svg>

            <!-- Mây nét vẽ mềm: nền trắng mờ + viền mảnh. -->
            <svg v-else viewBox="0 0 200 90" class="w-full h-auto">
                <path
                    d="M40 78 H160 A22 22 0 0 0 158 34 A30 30 0 0 0 102 20 A26 26 0 0 0 56 36 A21 21 0 0 0 40 78 Z"
                    fill="#fff" fill-opacity="0.75" stroke="#c9d6e2" stroke-opacity="0.8" stroke-width="2.5" stroke-linejoin="round"
                />
                <path d="M62 60 A16 16 0 0 1 90 50" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity="0.9" />
            </svg>
        </div>
    </div>
</template>
