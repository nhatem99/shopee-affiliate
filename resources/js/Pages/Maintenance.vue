<script setup>
import { Head } from '@inertiajs/vue3'
</script>

<template>
    <Head title="Đang bảo trì" />
    <div class="relative min-h-screen flex items-center justify-center overflow-hidden bg-[var(--color-bg)] px-4 safe-top safe-bottom">
        <!-- Ambient glow phía sau, cùng tông màu thương hiệu -->
        <div class="pointer-events-none absolute inset-0 flex items-center justify-center" aria-hidden="true">
            <div
                class="w-80 h-80 rounded-full blur-3xl opacity-30"
                style="background: radial-gradient(circle, var(--color-accent), transparent 70%)"
            ></div>
        </div>

        <div class="relative max-w-md w-full text-center" style="animation: fadeUp 0.6s cubic-bezier(0.2, 0.7, 0.2, 1)">
            <!-- Bỏ animate-float khỏi cái búa. Màn hình này chỉ cần MỘT thứ động để nói "đang
                 làm" và ba chấm bên dưới đã làm đúng việc đó; hơn nữa animate-float không nằm
                 trong khối @media (prefers-reduced-motion) nên khách tắt hiệu ứng ở hệ điều hành
                 vẫn thấy nó nhấp nhô suốt. -->
            <div
                class="w-20 h-20 mx-auto mb-6 rounded-2xl bg-gradient-to-br from-[var(--color-accent)] to-[var(--color-accent-deep)] flex items-center justify-center text-4xl shadow-[0_10px_40px_rgba(var(--color-accent-rgb),0.4)]"
            >
                🛠️
            </div>

            <h1 class="text-2xl font-extrabold text-[var(--color-ink)] mb-3">Đang bảo trì</h1>
            <!-- Gọi đúng tên miền khách vừa gõ vào. "Mã Giảm Giá" không phải tên nào trên trang
                 này, mà đây lại là màn hình duy nhất khách thấy lúc web tắt — không phải lúc để
                 họ phân vân mình có vào nhầm chỗ không. -->
            <p class="text-[var(--color-muted)] leading-relaxed mb-1">
                tietkiemvi.com đang được nâng cấp để phục vụ bạn tốt hơn.
            </p>
            <p class="text-[var(--color-muted)] leading-relaxed mb-7">
                Vui lòng quay lại sau ít phút. Xin lỗi vì sự bất tiện này!
            </p>

            <div class="flex items-center justify-center gap-2.5" role="status">
                <span class="sr-only">Đang bảo trì, vui lòng quay lại sau</span>
                <span aria-hidden="true" class="w-2.5 h-2.5 rounded-full bg-[var(--color-accent)] animate-blink-dot" style="animation-delay: 0s"></span>
                <span aria-hidden="true" class="w-2.5 h-2.5 rounded-full bg-[var(--color-accent)] animate-blink-dot" style="animation-delay: 0.2s"></span>
                <span aria-hidden="true" class="w-2.5 h-2.5 rounded-full bg-[var(--color-accent)] animate-blink-dot" style="animation-delay: 0.4s"></span>
            </div>
        </div>
    </div>
</template>
