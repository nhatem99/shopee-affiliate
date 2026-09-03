import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        // host:'0.0.0.0' khiến laravel-vite-plugin ghi literal "0.0.0.0" vào public/hot,
        // trình duyệt không kết nối được tới địa chỉ đó — ép origin về 127.0.0.1 để asset
        // URL luôn hợp lệ khi test qua localhost. cors:true để không kẹt CORS khi IP đổi.
        origin: 'http://127.0.0.1:5173',
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
