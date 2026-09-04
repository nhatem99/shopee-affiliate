<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $link->product_name ?? 'Mã giảm giá' }}</title>

    {{-- og:url tự trỏ về chính link rút gọn này (không phải target_url) — để bot Facebook/Zalo/
    Telegram không "thấy" link Shopee thật rồi thay link chia sẻ bằng bản không có mmp_pid/mã giảm giá. --}}
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:type" content="product">
    <meta property="og:title" content="{{ $link->product_name ?? 'Mã giảm giá' }}">
    <meta property="og:description" content="Bấm để nhận mã giảm giá.">
    @if($link->product_image)
        <meta property="og:image" content="{{ $link->product_image }}">
    @endif

    {{-- Trình duyệt thật (không phải bot) tự chuyển tiếp ngay; bot Facebook/Zalo/Telegram
    chỉ đọc thẻ meta ở trên, không thực thi refresh/JS nên vẫn giữ nguyên link rút gọn này. --}}
    <meta http-equiv="refresh" content="0;url={{ $link->target_url }}">
</head>
<body>
    <p><a href="{{ $link->target_url }}">Bấm vào đây để nhận mã giảm giá</a></p>
</body>
</html>
