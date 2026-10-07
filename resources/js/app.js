import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { createPinia } from 'pinia'
import axios from 'axios'
import '../css/app.css'

// Đảm bảo mọi request axios (không chỉ Inertia) đều gửi CSRF token.
// Đọc trực tiếp từ thẻ <meta> thay vì để axios tự dò cookie XSRF-TOKEN —
// một số trình duyệt di động không đọc/gửi cookie đó ổn định qua axios.
axios.defaults.withCredentials = true
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
if (csrfToken) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken
}

createInertiaApp({
    title: (title) => title ? `${title} — Mã Giảm Giá` : 'Mã Giảm Giá',
    // Mỗi trang một chunk riêng, chỉ tải khi cần: trước đây eager: true gom cả 39 trang (kể cả
    // toàn bộ khu Admin) vào một file JS ~750 KB mà khách mở trang chủ trên điện thoại cũng phải
    // tải và parse hết. Chunk của trang ĐANG mở được preload sẵn ở app.blade.php nên không phải
    // chờ app.js chạy xong mới đi lấy.
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue')
        return pages[`./Pages/${name}.vue`]()
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(createPinia())
            .mount(el)
    },
    progress: {
        color: '#F5511E',
    },
})
