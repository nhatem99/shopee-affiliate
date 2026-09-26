<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover: BẮT BUỘC để env(safe-area-inset-*) trả về giá trị thật. Thiếu nó thì
         env() trả 0 và mọi lớp chống "tai thỏ"/vạch home đều chỉ là padding vô nghĩa — thanh
         điều hướng dưới nằm đè lên vạch home của iPhone. Đi kèm: AppLayout phải chừa
         safe-area-inset-top cho header, BottomNav chừa inset-bottom. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Màu thanh trạng thái/khung webview. Đặt sẵn bản tối vì đó là mặc định; script dưới
         chỉnh lại ngay nếu khách đã chọn giao diện sáng. Thiếu thẻ này thì viền webview
         Facebook/Zalo giữ màu trắng, cắt ngang nền navy — trông như trang bị lỗi. --}}
    <meta name="theme-color" content="#0B1120">
    <script nonce="{{ request()->attributes->get('csp_nonce') }}">
        // Đặt theme trước khi render để tránh nhấp nháy (FOUC).
        // Mặc định là tối (giống salesoc.vn) trừ khi người dùng đã tự chọn "light".
        (function () {
            try {
                var t = localStorage.getItem('theme');
                if (t !== 'dark' && t !== 'light') t = 'dark';
                if (t === 'dark') document.documentElement.classList.add('dark');
                var m = document.querySelector('meta[name="theme-color"]');
                if (m) m.setAttribute('content', t === 'dark' ? '#0B1120' : '#FBFBFC');
            } catch (e) {}
        })();
    </script>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
