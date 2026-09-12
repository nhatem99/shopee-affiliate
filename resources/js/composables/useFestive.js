import { ref } from 'vue'

/**
 * Cầu nối giữa trang (Home.vue) và lớp trang trí (Components/FestiveDecor.vue) — hai bên
 * không cha-con nhau (FestiveDecor nằm trong AppLayout) nên dùng state cấp module.
 *
 * celebrate(): kích một lượt "trẻ con rước đèn diễu hành" — gọi khi tìm mã xong. Gọi lúc
 * trang trí đang tắt thì không có gì xảy ra, nên chỗ gọi không cần kiểm tra cờ.
 */
const celebrating = ref(false)
let timer = null

// Phải khớp với thời lượng festiveParade trong app.css: hết animation mới gỡ đoàn rước, nếu
// gỡ sớm cả đoàn biến mất giữa màn hình.
export const FESTIVE_CELEBRATE_MS = 9000

export function useFestive() {
    function celebrate() {
        if (timer) clearTimeout(timer)
        // Tắt rồi bật lại ở frame sau để lần gọi kế tiếp chạy lại animation từ đầu — chỉ đặt
        // true khi đang true thì trình duyệt không khởi động lại keyframe.
        celebrating.value = false
        requestAnimationFrame(() => {
            celebrating.value = true
            timer = setTimeout(() => {
                celebrating.value = false
                timer = null
            }, FESTIVE_CELEBRATE_MS)
        })
    }

    return { celebrating, celebrate }
}
