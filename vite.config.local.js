import base from './vite.config.js'

/**
 * Cấu hình CHỈ để chạy thử ở máy local khi cổng 5173 đã bị một dự án khác chiếm.
 * vite.config.js ép origin về 127.0.0.1:5173, nên nếu cổng đó thuộc dự án khác thì trang
 * tải app.js từ nhầm server và Vue không mount được (màn hình trắng).
 *
 * Dùng: npx vite --config vite.config.local.js
 * File này không cần commit — xoá lúc nào cũng được.
 */
export default {
    ...base,
    server: {
        ...base.server,
        port: 5174,
        strictPort: true,
        origin: 'http://127.0.0.1:5174',
    },
}
