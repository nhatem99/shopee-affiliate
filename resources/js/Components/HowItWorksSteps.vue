<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'

/**
 * Thanh các bước dạng ngang, đặt ngay dưới ô dán link ở trang chủ.
 *
 * Thay cho khối "Chỉ N bước đơn giản" cũ (các thẻ chữ dài ở cuối trang): khách vào bằng điện
 * thoại gần như không bao giờ cuộn tới đó, nên phần hướng dẫn quan trọng nhất của trang lại là
 * phần ít người đọc nhất. Bản này gói mỗi bước thành một icon + hai chữ để cả bốn bước nằm gọn
 * một hàng trên màn hình 375px, ngay trong tầm mắt lúc khách đang phân vân phải làm gì.
 *
 * Mô tả dài của từng bước không mất: nằm trong thuộc tính title (máy tính rê chuột là thấy) và
 * trong mục Câu hỏi thường gặp ở cuối trang.
 */
const props = defineProps({
    title: { type: String, required: true },
    /** @type {{icon: string, label: string, desc: string}[]} */
    steps: { type: Array, required: true },
})

// Hiệu ứng chỉ chạy MỘT LẦN khi khối lọt vào màn hình, không bám trạng thái thật của khách —
// đây là bảng hướng dẫn, không phải thanh tiến độ. Chạy lại mỗi lần cuộn qua sẽ thành thứ nhấp
// nháy vô nghĩa ngay cạnh ô nhập.
const revealed = ref(false)
const root = ref(null)
let observer = null

/** Hai đầu đường nối dừng đúng tâm icon đầu và icon cuối — xem chú thích ở template. */
const trackInset = computed(() => {
    const half = `${50 / Math.max(props.steps.length, 1)}%`

    return { left: half, right: half }
})

/**
 * Người tắt chuyển động ở hệ điều hành: hiện thẳng, không delay, không trượt. Trả về false khi
 * chạy ở môi trường không có matchMedia (SSR) để không nổ.
 */
const reduceMotion = () => typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches

// Delay xếp tầng cho từng bước. Trả 0 khi tắt chuyển động: để nguyên delay thì khối vẫn "hiện
// dần" từng bước một, chỉ là không có animation — đúng thứ người bật tuỳ chọn đó muốn tránh.
function stepDelay(index) {
    return reduceMotion() ? '0ms' : `${index * 130}ms`
}

onMounted(() => {
    // Không có IntersectionObserver (trình duyệt cũ) thì hiện luôn — thà mất hiệu ứng còn hơn
    // mất hẳn phần hướng dẫn.
    if (reduceMotion() || typeof IntersectionObserver === 'undefined') {
        revealed.value = true

        return
    }

    observer = new IntersectionObserver(([entry]) => {
        if (!entry.isIntersecting) return

        revealed.value = true
        observer?.disconnect()
        observer = null
    }, { threshold: 0.35 })

    if (root.value) observer.observe(root.value)
})

onUnmounted(() => observer?.disconnect())
</script>

<template>
    <div ref="root" class="card-glass rounded-2xl px-4 py-5">
        <h2 class="text-center text-base font-extrabold text-[var(--color-ink)] mb-5">{{ title }}</h2>

        <div class="relative">
            <!-- Đường nối chạy sau các icon. Đặt ở 22px (nửa chiều cao icon 44px) thay vì canh
                 giữa cả khối: khối còn có nhãn chữ bên dưới nên tâm khối không phải tâm hàng
                 icon, canh giữa là đường kẻ cắt ngang mặt chữ.
                 Hai đầu thụt vào đúng tâm icon đầu/cuối: mỗi bước chiếm 100/n phần trăm chiều
                 ngang nên tâm icon đầu nằm ở 50/n. Ghi cứng một con số thì 4 bước (chưa bật hoàn
                 tiền) và 5 bước lệch nhau thấy rõ. -->
            <div class="absolute top-[22px] h-0.5 -translate-y-1/2 bg-[var(--color-line)]" :style="trackInset"></div>
            <div
                class="absolute top-[22px] h-0.5 -translate-y-1/2 origin-left bg-[var(--color-brand-green)] transition-transform ease-out"
                :class="revealed ? 'scale-x-100' : 'scale-x-0'"
                :style="{ ...trackInset, transitionDuration: reduceMotion() ? '0ms' : '1100ms' }"
            ></div>

            <ol class="relative flex items-start justify-between gap-1">
                <li
                    v-for="(step, i) in steps"
                    :key="i"
                    :title="step.desc"
                    class="flex flex-col items-center gap-2 min-w-0 flex-1 transition-all ease-out"
                    :class="revealed ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-3'"
                    :style="{ transitionDelay: stepDelay(i), transitionDuration: reduceMotion() ? '0ms' : '450ms' }"
                >
                    <span
                        class="w-11 h-11 flex-none rounded-full bg-[var(--color-surface)] border-2 flex items-center justify-center text-lg transition-[border-color,transform] duration-300"
                        :class="revealed ? 'border-[var(--color-brand-green)] scale-100' : 'border-[var(--color-line)] scale-90'"
                        :style="{ transitionDelay: stepDelay(i) }"
                    >{{ step.icon }}</span>

                    <!-- Nhãn phải vừa một dòng ở 375px với 4-5 bước: cỡ chữ 11px, không truncate
                         (cắt mất chữ thì bước đó thành vô nghĩa) — chữ ngắn là việc của nơi
                         truyền dữ liệu vào. -->
                    <span class="text-[11px] leading-tight font-bold text-center text-[var(--color-ink)]">{{ step.label }}</span>
                </li>
            </ol>
        </div>
    </div>
</template>
