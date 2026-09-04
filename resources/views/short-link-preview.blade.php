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

    Dùng JS thay vì <meta http-equiv="refresh"> — đã xác minh thực tế bot của Facebook có
    THỰC THI http-equiv="refresh" (không đúng như giả định "bot chỉ đọc meta, không refresh"),
    khiến nó tự ghé thẳng target_url và lấy og:title/image/description CỦA SHOPEE làm preview,
    che mất chính hiệu quả của toàn bộ cơ chế og:url tự trỏ ở trên. JS thì bot không thực thi
    nên vẫn giữ được preview do mình kiểm soát, trong khi trình duyệt thật (kể cả bị nhận nhầm
    là bot) vẫn tự chuyển tiếp bình thường. --}}
    <script nonce="{{ request()->attributes->get('csp_nonce') }}">location.replace(@json($link->target_url));</script>
</head>
<body>
    <p><a href="{{ $link->target_url }}">Bấm vào đây để nhận mã giảm giá</a></p>
</body>
</html>
