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
import FestiveKid from '@/Components/FestiveKid.vue'

// Bật khi Home.vue báo tìm mã xong: đoàn trẻ con rước đèn diễu hành ngang chân màn hình,
// hai em đứng sẵn ở góc nhảy cẫng lên, sao loé dọc đường rước.
const { celebrating } = useFestive()

const items = [
    // Trăng: to, mờ, góc trên phải — lúc chưa cuộn thấy nửa trăng ló dưới header.
    { kind: 'moon', left: '70%', top: '3%', w: 'w-40 md:w-56', anim: 'festive-glow', duration: '7s', delay: '0s', opacity: 0.85 },

    // Hai em bé cầm đèn đứng hai góc dưới (ngay trên BottomNav), nhấp nhô nhẹ. Lúc ăn mừng
    // đổi sang nhảy (festive-hop).
    { kind: 'kid', left: '1%', top: '70%', w: 'w-20 md:w-28', anim: 'festive-float', duration: '6s', delay: '0s', opacity: 1, shirt: '#ff6b4a', shorts: '#2f5fd0', hair: 'boy', lantern: 'star' },
    { kind: 'kid', left: '80%', top: '72%', w: 'w-16 md:w-24', anim: 'festive-float', duration: '7s', delay: '1.5s', opacity: 1, shirt: '#ffd35c', shorts: '#e0457b', hair: 'girl', lantern: 'round' },

    // Đèn lồng treo từ mép trên (có dây), đung đưa quanh điểm treo.
    { kind: 'lantern', left: '3%', top: '2%', w: 'w-12 md:w-16', anim: 'festive-sway', duration: '4.5s', delay: '0s', opacity: 1 },
    { kind: 'lantern', left: '14%', top: '-2%', w: 'w-8 md:w-11', anim: 'festive-sway', duration: '5.5s', delay: '1.3s', opacity: 0.9 },
    { kind: 'lantern', left: '90%', top: '28%', w: 'w-10 md:w-14', anim: 'festive-sway', duration: '5s', delay: '0.7s', opacity: 1 },
    { kind: 'lantern', left: '2%', top: '42%', w: 'w-9 md:w-12', anim: 'festive-sway', duration: '6s', delay: '2s', opacity: 0.9 },

    // Bánh trung thu nhỏ, sát mép.
    { kind: 'mooncake', left: '91%', top: '54%', w: 'w-8 md:w-14', anim: 'festive-float', duration: '6.5s', delay: '0.4s', opacity: 1 },

    // Sao lấp lánh: nhỏ, mờ-sáng lệch pha.
    { kind: 'sparkle', left: '26%', top: '14%', w: 'w-4 md:w-5', anim: 'festive-twinkle', duration: '2.6s', delay: '0s', opacity: 1 },
    { kind: 'sparkle', left: '64%', top: '22%', w: 'w-3 md:w-4', anim: 'festive-twinkle', duration: '3.1s', delay: '0.9s', opacity: 1 },
    { kind: 'sparkle', left: '88%', top: '46%', w: 'w-4', anim: 'festive-twinkle', duration: '2.3s', delay: '1.5s', opacity: 1 },
    { kind: 'sparkle', left: '18%', top: '56%', w: 'w-3', anim: 'festive-twinkle', duration: '2.9s', delay: '0.4s', opacity: 1 },
    { kind: 'sparkle', left: '52%', top: '84%', w: 'w-4 md:w-5', anim: 'festive-twinkle', duration: '2.7s', delay: '2s', opacity: 1 },

    // Mây trôi ngang cả màn hình, rất mờ để chỉ là nền.
    { kind: 'cloud', left: '-20%', top: '34%', w: 'w-32 md:w-56', anim: 'festive-drift', duration: '55s', delay: '0s', opacity: 0.32 },
    { kind: 'cloud', left: '-20%', top: '62%', w: 'w-24 md:w-40', anim: 'festive-drift', duration: '70s', delay: '-30s', opacity: 0.28 },
]

// Đoàn rước đèn: chỉ dựng lúc ăn mừng, đi từ ngoài mép trái sang hết mép phải (festive-parade,
// 9s = FESTIVE_CELEBRATE_MS). Mỗi em xuất phát lệch nhau một chút để thành hàng chứ không
// chồng lên nhau; bên trong mỗi em có nhịp bước (festive-walk) riêng.
const parade = [
    { left: '-14%', top: '66%', w: 'w-20 md:w-28', walk: '0.55s', shirt: '#2fbf71', shorts: '#2f5fd0', hair: 'boy', lantern: 'star' },
    { left: '-30%', top: '69%', w: 'w-16 md:w-24', walk: '0.62s', shirt: '#ff6b4a', shorts: '#ffd35c', hair: 'girl', lantern: 'fish' },
    { left: '-46%', top: '67%', w: 'w-[4.5rem] md:w-[6.5rem]', walk: '0.5s', shirt: '#7c5cff', shorts: '#e0457b', hair: 'boy', lantern: 'round' },
    { left: '-62%', top: '70%', w: 'w-14 md:w-[5.5rem]', walk: '0.58s', shirt: '#ffd35c', shorts: '#2fbf71', hair: 'girl', lantern: 'star' },
]

// Sao loé dọc đường rước, hiện lệch pha theo hướng đoàn đi (trái -> phải).
const paradeSparkles = [
    { left: '12%', top: '62%', w: 'w-4 md:w-5', duration: '1.1s', delay: '1.5s' },
    { left: '30%', top: '80%', w: 'w-3 md:w-4', duration: '1.3s', delay: '2.6s' },
    { left: '48%', top: '60%', w: 'w-4', duration: '1s', delay: '3.7s' },
    { left: '66%', top: '82%', w: 'w-3', duration: '1.2s', delay: '4.8s' },
    { left: '84%', top: '64%', w: 'w-4 md:w-5', duration: '1.1s', delay: '5.9s' },
]

const petals = [0, 45, 90, 135, 180, 225, 270, 315]
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
                <radialGradient id="fd-spark" cx="50%" cy="50%" r="50%">
                    <stop offset="0%" stop-color="#fffbe6" />
                    <stop offset="100%" stop-color="#ffd35c" />
                </radialGradient>
                <radialGradient id="fd-star-glow" cx="50%" cy="50%" r="50%">
                    <stop offset="30%" stop-color="#ffb84a" stop-opacity="0.6" />
                    <stop offset="100%" stop-color="#ffb84a" stop-opacity="0" />
                </radialGradient>
                <linearGradient id="fd-fish" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0%" stop-color="#ff8a3d" />
                    <stop offset="100%" stop-color="#e0342e" />
                </linearGradient>
            </defs>
        </svg>

        <!-- Đoàn rước đèn + sao dọc đường: chỉ dựng lúc ăn mừng. -->
        <template v-if="celebrating">
            <div
                v-for="(kid, i) in parade"
                :key="'parade-' + i"
                class="absolute festive-parade"
                :class="kid.w"
                :style="{ left: kid.left, top: kid.top }"
            >
                <div class="festive-walk" :style="{ animationDuration: kid.walk }">
                    <FestiveKid :shirt="kid.shirt" :shorts="kid.shorts" :hair="kid.hair" :lantern="kid.lantern" />
                </div>
            </div>

            <div
                v-for="(spot, i) in paradeSparkles"
                :key="'pspark-' + i"
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
            :class="[item.w, item.anim, celebrating && item.kind === 'kid' ? 'festive-hop' : '']"
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

            <!-- Em bé cầm đèn đứng ở góc. -->
            <FestiveKid v-else-if="item.kind === 'kid'" :shirt="item.shirt" :shorts="item.shorts" :hair="item.hair" :lantern="item.lantern" />

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
