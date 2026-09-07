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

    {{-- Trang này CHỈ được trả về cho request bị nhận diện là bot (xem ShortLinkController::
    redirect()) — người dùng thật luôn nhận 302 riêng, không bao giờ thấy trang này. Vẫn cần
    tự chuyển tiếp ở đây để chống trường hợp hiếm bot-detection nhận nhầm người thật là bot.

    Dùng JS thay vì <meta http-equiv="refresh"> — đã xác minh trên production (9cd1967) là bot
    Facebook CÓ thực thi http-equiv="refresh", nên nó tự ghé thẳng target_url rồi lấy
    title/image/description CỦA SHOPEE làm preview. Shopee không trả gì dùng được cho crawler
    nên card ra trống trơn: không ảnh, tiêu đề rơi về tên miền.

    Bản JS này từng bị revert ở ee247fc với lý do "chỉ đổi chữ/ảnh hiển thị, không đổi đích
    click" — nhưng chính chữ/ảnh hiển thị mới là thứ đang hỏng, nên đưa lại. Bot không chạy JS
    nên buộc phải dùng og tag ở trên; trình duyệt thật (kể cả bị nhận nhầm) vẫn tự chuyển tiếp. --}}
    <script nonce="{{ request()->attributes->get('csp_nonce') }}">location.replace(@json($link->target_url));</script>
</head>
<body>
    <p><a href="{{ $link->target_url }}">Bấm vào đây để nhận mã giảm giá</a></p>
</body>
</html>
