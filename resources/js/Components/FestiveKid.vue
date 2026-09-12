<script setup>
/**
 * Em bé cầm đèn Trung thu (kiểu chibi đầu to) — dùng chung cho hai em đứng góc và đoàn rước
 * trong FestiveDecor. Các gradient/glow (#fd-*) được khai báo một lần trong <defs> của
 * FestiveDecor nên component này phải nằm trong đó mới lên màu.
 *
 * Đèn cầm tay có ba kiểu quen mắt: đèn ông sao, đèn cá chép, đèn lồng tròn.
 */
defineProps({
    shirt: { type: String, default: '#ff6b4a' },
    shorts: { type: String, default: '#2f5fd0' },
    hair: { type: String, default: 'boy' }, // boy | girl
    lantern: { type: String, default: 'star' }, // star | fish | round
})
</script>

<template>
    <svg viewBox="0 0 140 180" class="w-full h-auto drop-shadow-[0_6px_10px_rgba(0,0,0,0.25)]">
        <!-- Đèn: giơ lên bên phải, có vòng sáng cam. -->
        <g transform="translate(104 34)">
            <circle r="40" fill="url(#fd-star-glow)" />
            <template v-if="lantern === 'star'">
                <circle r="25" fill="none" stroke="#ffd35c" stroke-width="2.5" />
                <path d="M0 -22 L5.3 -7.3 L20.9 -6.8 L8.6 2.8 L12.9 17.8 L0 9 L-12.9 17.8 L-8.6 2.8 L-20.9 -6.8 L-5.3 -7.3 Z" fill="#ff3b3b" stroke="#ffd35c" stroke-width="2" stroke-linejoin="round" />
                <circle r="4" fill="#ffe89a" />
                <path d="M-6 26 L-8 40 M0 26 L0 42 M6 26 L8 40" stroke="#ffd35c" stroke-width="1.6" stroke-linecap="round" />
            </template>
            <template v-else-if="lantern === 'fish'">
                <path d="M-26 0 C-14 -20 14 -20 24 0 C14 20 -14 20 -26 0 Z" fill="url(#fd-fish)" />
                <path d="M22 0 L34 -12 L32 0 L34 12 Z" fill="#e0342e" />
                <path d="M-6 -12 C0 -8 4 -8 10 -12" fill="none" stroke="#ffd35c" stroke-width="1.5" />
                <path d="M-6 12 C0 8 4 8 10 12" fill="none" stroke="#ffd35c" stroke-width="1.5" />
                <circle cx="-16" cy="-3" r="3" fill="#fff" />
                <circle cx="-15" cy="-3" r="1.5" fill="#1f1a2e" />
                <path d="M-4 20 L-6 34 M4 20 L6 34" stroke="#ffd35c" stroke-width="1.6" stroke-linecap="round" />
            </template>
            <template v-else>
                <rect x="-9" y="-30" width="18" height="6" rx="2" fill="url(#fd-gold)" />
                <ellipse rx="22" ry="24" fill="url(#fd-lantern)" />
                <ellipse rx="11" ry="24" fill="none" stroke="#ffb27a" stroke-opacity="0.55" stroke-width="1.4" />
                <rect x="-9" y="24" width="18" height="6" rx="2" fill="url(#fd-gold)" />
                <path d="M-4 30 L-5 42 M0 30 L0 44 M4 30 L5 42" stroke="#e0342e" stroke-width="1.5" stroke-linecap="round" />
            </template>
        </g>
        <!-- Que cầm đèn. -->
        <line x1="86" y1="86" x2="104" y2="60" stroke="#b07a3a" stroke-width="3.5" stroke-linecap="round" />

        <!-- Thân: chân, giày, quần, áo, tay giơ que, tay kia buông. -->
        <rect x="52" y="140" width="12" height="26" rx="5" fill="#ffe4cf" />
        <rect x="70" y="140" width="12" height="26" rx="5" fill="#ffe4cf" />
        <rect x="48" y="160" width="20" height="10" rx="4" fill="#1f1a2e" />
        <rect x="66" y="160" width="20" height="10" rx="4" fill="#1f1a2e" />
        <rect x="48" y="118" width="38" height="28" rx="8" :fill="shorts" />
        <path d="M42 90 C42 76 92 76 92 90 L94 122 L40 122 Z" :fill="shirt" />
        <path d="M88 96 L98 78" stroke="#ffe4cf" stroke-width="10" stroke-linecap="round" />
        <path d="M44 98 L38 118" stroke="#ffe4cf" stroke-width="10" stroke-linecap="round" />

        <!-- Đầu: mặt, tóc (bé trai chỏm tóc / bé gái hai chùm buộc nơ), mắt, miệng cười, má hồng. -->
        <circle cx="67" cy="56" r="26" fill="#ffe4cf" />
        <template v-if="hair === 'girl'">
            <path d="M41 52 C41 26 93 26 93 52 C86 42 78 38 67 38 C56 38 48 42 41 52 Z" fill="#2b2140" />
            <circle cx="40" cy="44" r="9" fill="#2b2140" />
            <circle cx="94" cy="44" r="9" fill="#2b2140" />
            <circle cx="40" cy="44" r="3.5" fill="#ff6b9d" />
            <circle cx="94" cy="44" r="3.5" fill="#ff6b9d" />
        </template>
        <template v-else>
            <path d="M41 54 C41 28 93 28 93 54 C88 44 80 40 67 40 C54 40 46 44 41 54 Z" fill="#1f1a2e" />
            <path d="M66 34 q6 -10 12 -4" fill="none" stroke="#1f1a2e" stroke-width="3.5" stroke-linecap="round" />
        </template>
        <path d="M56 58 q4 -4 8 0 M70 58 q4 -4 8 0" fill="none" stroke="#2b2140" stroke-width="2.4" stroke-linecap="round" />
        <path d="M60 68 q7 7 14 0" fill="none" stroke="#c0605a" stroke-width="2.2" stroke-linecap="round" />
        <circle cx="52" cy="66" r="4" fill="#ffb3b3" opacity="0.6" />
        <circle cx="82" cy="66" r="4" fill="#ffb3b3" opacity="0.6" />
    </svg>
</template>
