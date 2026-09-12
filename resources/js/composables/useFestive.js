import { ref } from 'vue'

/**
 * Cầu nối giữa trang (Home.vue) và lớp trang trí (Components/FestiveDecor.vue) — hai bên
 * không cha-con nhau (FestiveDecor nằm trong AppLayout) nên dùng state cấp module.
 *
 * celebrate(): kích một lượt "chú Cuội bay lên chơi với chị Hằng" — gọi khi tìm mã xong. Gọi
 * lúc trang trí đang tắt thì không có gì xảy ra, nên chỗ gọi không cần kiểm tra cờ.
 */
const flying = ref(false)
let timer = null

// Phải khớp với thời lượng festiveFly trong app.css: hết animation mới bỏ class, nếu bỏ sớm
// Cuội đang lơ lửng giữa trời bị giật về gốc cây.
export const FESTIVE_FLIGHT_MS = 9000

export function useFestive() {
    function celebrate() {
        if (timer) clearTimeout(timer)
        // Tắt rồi bật lại ở frame sau để lần gọi kế tiếp chạy lại animation từ đầu — chỉ đặt
        // true khi đang true thì trình duyệt không khởi động lại keyframe.
        flying.value = false
        requestAnimationFrame(() => {
            flying.value = true
            timer = setTimeout(() => {
                flying.value = false
                timer = null
            }, FESTIVE_FLIGHT_MS)
        })
    }

    return { flying, celebrate }
}
