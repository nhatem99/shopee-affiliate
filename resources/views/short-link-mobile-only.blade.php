<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $link->product_name ?? 'Mã giảm giá' }}</title>
    <style nonce="{{ request()->attributes->get('csp_nonce') }}">
        body { font-family: system-ui, sans-serif; background: #fff7f2; color: #222; margin: 0; display: grid; place-items: center; min-height: 100vh; }
        main { max-width: 26rem; padding: 2rem 1.5rem; text-align: center; }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { line-height: 1.6; color: #555; }
        code { display: block; word-break: break-all; background: #fff; border: 1px solid #eee; border-radius: .5rem; padding: .6rem; font-size: .8rem; color: #222; }
    </style>
</head>
<body>
    <main>
        <h1>📱 Link này dành cho điện thoại</h1>
        <p>Để nhận mã giảm giá và mở được app Shopee, hãy mở link này trên điện thoại của bạn.</p>
        <code>{{ url()->current() }}</code>
    </main>
</body>
</html>
