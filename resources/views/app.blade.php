<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script nonce="{{ request()->attributes->get('csp_nonce') }}">
        // Đặt theme trước khi render để tránh nhấp nháy (FOUC).
        // Mặc định là tối (giống salesoc.vn) trừ khi người dùng đã tự chọn "light".
        (function () {
            try {
                var t = localStorage.getItem('theme');
                if (t !== 'dark' && t !== 'light') t = 'dark';
                if (t === 'dark') document.documentElement.classList.add('dark');
            } catch (e) {}
        })();
        // iPhone/iPad tự phóng to khi chạm ô nhập có chữ < 16px (ô dán link là text-sm).
        // maximum-scale=1 chặn việc đó; iOS vẫn cho véo 2 ngón để phóng to. Chỉ gắn trên iOS
        // vì Android tôn trọng maximum-scale và sẽ mất luôn véo phóng to.
        (function () {
            var ua = navigator.userAgent;
            var ios = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            var vp = document.querySelector('meta[name=viewport]');
            if (ios && vp) vp.setAttribute('content', 'width=device-width, initial-scale=1, maximum-scale=1');
        })();
    </script>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    {{-- Kèm chunk của trang đang mở (app.js tải trang theo nhu cầu) để trình duyệt tải song song với
         app.js thay vì đợi app.js chạy rồi mới đi lấy. is_file: tên component sai thì thôi preload,
         đừng để Vite ném lỗi "not in manifest" thành trang 500. --}}
    @php($pageEntry = 'resources/js/Pages/'.$page['component'].'.vue')
    @vite(array_values(array_filter(['resources/css/app.css', 'resources/js/app.js', is_file(base_path($pageEntry)) ? $pageEntry : null])))
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
