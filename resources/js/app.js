import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { createPinia } from 'pinia'
import '../css/app.css'

createInertiaApp({
    title: (title) => title ? `${title} — Mã Giảm Giá` : 'Mã Giảm Giá',
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
        return pages[`./Pages/${name}.vue`]
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
